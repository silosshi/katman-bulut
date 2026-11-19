<?php

namespace ImageOptimization\Classes\Image;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Image_Backup_Path_Validator {
	private const ALLOWED_IMAGE_EXTENSIONS = [ 'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif' ];

	/**
	 * Validates and resolves a backup file path.
	 *
	 * Ensures the path:
	 * - Exists under the uploads directory
	 * - Belongs to the specified attachment (same directory as attachment's file)
	 * - Matches one of two valid patterns for THIS attachment's size:
	 *   1. {stem}.backup.{ext} - Standard backup created by Image_Backup::create()
	 *   2. {stem}.{image_ext} - Conversion backup (original file stored when converting to webp/avif)
	 * - Uses an allowed image extension
	 *
	 * @param int $attachment_id Attachment ID that owns this backup.
	 * @param string $image_size Image size (e.g. 'full', 'thumbnail').
	 * @param string $path Backup path stored in attachment meta.
	 *
	 * @return string|null Canonical resolved path if valid, null otherwise.
	 */
	public static function resolve( int $attachment_id, string $image_size, string $path ): ?string {
		if ( '' === $path ) {
			return null;
		}

		$resolved_path = realpath( $path );

		if ( false === $resolved_path ) {
			return null;
		}

		$upload_dir = wp_upload_dir();
		$uploads_base = realpath( $upload_dir['basedir'] );

		if ( false === $uploads_base ) {
			$uploads_base = wp_normalize_path( $upload_dir['basedir'] );
		} else {
			$uploads_base = wp_normalize_path( $uploads_base );
		}

		$resolved_path = wp_normalize_path( $resolved_path );
		$uploads_prefix = trailingslashit( $uploads_base );

		if ( 0 !== strpos( $resolved_path, $uploads_prefix ) && rtrim( $uploads_base, '/' ) !== $resolved_path ) {
			return null;
		}

		$image = new Image( $attachment_id );
		$current_file = $image->get_file_path( $image_size );

		if ( ! $current_file ) {
			return null;
		}

		$current_file_realpath = realpath( $current_file );

		if ( false === $current_file_realpath ) {
			return null;
		}

		$current_file = wp_normalize_path( $current_file_realpath );
		$current_dir = dirname( $current_file );
		$backup_dir = dirname( $resolved_path );

		if ( $current_dir !== $backup_dir ) {
			return null;
		}

		$expected_stem = self::get_file_stem( $current_file );
		$backup_stem = self::get_file_stem( $resolved_path );

		$stem_matches = ( $expected_stem === $backup_stem );

		if ( ! $stem_matches ) {
			$stem_matches = self::matches_pre_edit_stem( $attachment_id, $image_size, $backup_stem );
		}

		if ( ! $stem_matches ) {
			return null;
		}

		$backup_filename = basename( $resolved_path );
		$backup_ext = strtolower( pathinfo( $backup_filename, PATHINFO_EXTENSION ) );

		if ( ! in_array( $backup_ext, self::ALLOWED_IMAGE_EXTENSIONS, true ) ) {
			return null;
		}

		$is_standard_backup = (bool) preg_match( '/\.backup\.[^.]+$/', $backup_filename );
		$current_ext = strtolower( pathinfo( $current_file, PATHINFO_EXTENSION ) );
		$is_conversion_backup = ! $is_standard_backup && $backup_ext !== $current_ext;

		if ( ! $is_standard_backup && ! $is_conversion_backup ) {
			return null;
		}

		if ( self::is_another_attachments_file( $attachment_id, $resolved_path ) ) {
			return null;
		}

		return $resolved_path;
	}

	/**
	 * Extracts the file stem (basename without .backup. or extension).
	 *
	 * Examples:
	 * - /path/image.jpg -> image
	 * - /path/image.backup.jpg -> image
	 * - /path/image-150x150.png -> image-150x150
	 * - /path/image-150x150.backup.webp -> image-150x150
	 *
	 * @param string $path File path.
	 * @return string File stem.
	 */
	private static function get_file_stem( string $path ): string {
		$filename = basename( $path );

		$filename = preg_replace( '/\.backup\.([^.]+)$/', '.$1', $filename );

		return pathinfo( $filename, PATHINFO_FILENAME );
	}

	/**
	 * Checks if backup stem matches a pre-edit version stored in _wp_attachment_backup_sizes.
	 *
	 * WordPress adds -e{timestamp} suffix when editing images. This checks if the backup
	 * matches the original pre-edit filename.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param string $image_size Image size.
	 * @param string $backup_stem Backup file stem.
	 * @return bool True if backup matches a pre-edit stem.
	 */
	private static function matches_pre_edit_stem( int $attachment_id, string $image_size, string $backup_stem ): bool {
		$backup_sizes = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );

		if ( ! is_array( $backup_sizes ) ) {
			return false;
		}

		foreach ( $backup_sizes as $backup_key => $backup_data ) {
			if ( ! isset( $backup_data['file'] ) ) {
				continue;
			}

			if ( Image::SIZE_FULL === $image_size && ! str_starts_with( $backup_key, 'full-' ) ) {
				continue;
			}

			if ( Image::SIZE_FULL !== $image_size && ! str_contains( $backup_key, $image_size ) ) {
				continue;
			}

			$pre_edit_stem = self::get_file_stem( $backup_data['file'] );

			if ( $backup_stem === $pre_edit_stem ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks if the resolved path is another attachment's primary file.
	 *
	 * Defense in depth: prevents deleting attachment B's live file when restoring
	 * attachment A's conversion backup if both share the same stem in the same directory.
	 * Also prevents someone uploading a file literally named photo.backup.jpg from
	 * being deleted when cleaning up photo.jpg's backups.
	 *
	 * Example: A has photo.webp, B has photo.jpg. When restoring A's conversion backup
	 * (photo.jpg), we shouldn't delete B's live file.
	 *
	 * @param int $attachment_id This attachment's ID.
	 * @param string $resolved_path The resolved backup path.
	 * @return bool True if path belongs to another attachment.
	 */
	private static function is_another_attachments_file( int $attachment_id, string $resolved_path ): bool {
		$upload_dir = wp_upload_dir();
		$uploads_base_real = realpath( $upload_dir['basedir'] );

		if ( false !== $uploads_base_real ) {
			$uploads_base = wp_normalize_path( $uploads_base_real );
		} else {
			$uploads_base = wp_normalize_path( $upload_dir['basedir'] );
		}

		$normalized_path = wp_normalize_path( $resolved_path );
		$uploads_prefix = trailingslashit( $uploads_base );

		if ( 0 !== strpos( $normalized_path, $uploads_prefix ) ) {
			return false;
		}

		$relative_path = substr( $normalized_path, strlen( $uploads_prefix ) );

		global $wpdb;
		$other_attachment_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
				$relative_path
			)
		);

		return $other_attachment_id && $other_attachment_id !== $attachment_id;
	}
}

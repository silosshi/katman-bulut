<?php

namespace ImageOptimization\Modules\Optimization\Components;

use ImageOptimization\Classes\Async_Operation\{
	Async_Operation,
	Async_Operation_Hook,
	Async_Operation_Queue,
};

use ImageOptimization\Classes\Image\{
	Exceptions\Invalid_Image_Exception,
	Image_Meta,
	Image_Optimization_Error_Type,
	Image_Status,
};

use ImageOptimization\Modules\Optimization\Classes\{
	Exceptions\Image_File_Already_Exists_Error,
	Exceptions\Image_Validation_Error,
	Optimize_Image,
	Validate_Image,
};

use ImageOptimization\Classes\Logger;
use ImageOptimization\Classes\Exceptions\Quota_Exceeded_Error;
use ImageOptimization\Modules\Connect\Classes\Exceptions\Connection_Error;
use ImageOptimization\Modules\Settings\Classes\Settings;

use Throwable;
use ImageOptimization\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Upload_Optimization {
	const UPLOAD_OPTIMIZATION_ATTEMPTED_META_KEY = '_image_optimizer_upload_attempted';
	const CLIENT_SIDE_PENDING_META_KEY = '_image_optimizer_client_side_pending';
	const FINALIZE_DETECTED_TRANSIENT_PREFIX = '_image_optimizer_finalize_detected_';
	const PENDING_FALLBACK_DELAY_SECONDS = 300; // 5 minutes

	/**
	 * Track finalize requests via REST route detection.
	 * Set during rest_pre_dispatch for the current request.
	 */
	private static ?int $finalize_attachment_id = null;

	/**
	 * Detect if current REST request is a finalize request.
	 * Called early in REST processing to set a flag for the attachment being finalized.
	 *
	 * @param mixed           $result  Response to replace the requested version with.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @return mixed Unmodified result.
	 */
	public function detect_finalize_request( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result;
		}

		$route = $request->get_route();

		// Match /wp/v2/media/<id>/finalize route
		if ( preg_match( '#^/wp/v2/media/(\d+)/finalize$#', $route, $matches ) ) {
			$attachment_id = (int) $matches[1];
			self::$finalize_attachment_id = $attachment_id;

			// Set a transient so the filter can detect this even if called after dispatch
			set_transient(
				self::FINALIZE_DETECTED_TRANSIENT_PREFIX . $attachment_id,
				true,
				MINUTE_IN_SECONDS
			);
		}

		return $result;
	}

	public function handle_upload( array $metadata, int $attachment_id, string $context ) {
		// WordPress 7.2 with client-side media processing fires this hook twice:
		// 1. context='create' - original uploaded via REST, client sub-sizes don't exist yet
		// 2. context='update' - after POST /wp/v2/media/{id}/finalize, all sub-sizes present
		//
		// WordPress 7.1 and server-side processing fire it once:
		// 1. context='create' - complete metadata with all sub-sizes
		//
		// Other 'update' triggers (wp_update_image_subsizes for regenerate-thumbnails,
		// image edits, etc.) should NOT trigger upload optimization.
		if ( ! in_array( $context, [ 'create', 'update' ], true ) ) {
			return $metadata;
		}

		if ( ! Settings::get( Settings::OPTIMIZE_ON_UPLOAD_OPTION_NAME ) ) {
			return $metadata;
		}

		// @var ImageOptimizer/Modules/ConnectManager/Module
		$module = Plugin::instance()->modules_manager->get_modules( 'connect-manager' );

		if ( ! $module->connect_instance->is_connected() || ! $module->connect_instance->is_activated() ) {
			return $metadata;
		}

		try {
			Validate_Image::is_valid( $attachment_id );
		} catch ( Invalid_Image_Exception | Image_Validation_Error $iie ) {
			return $metadata;
		}

		if ( 'create' === $context ) {
			return $this->handle_create_context( $metadata, $attachment_id );
		}

		if ( 'update' === $context ) {
			return $this->handle_update_context( $metadata, $attachment_id );
		}

		return $metadata;
	}

	/**
	 * Handle 'create' context - initial upload.
	 * Deterministically detect if this is a client-side upload by checking if we're in
	 * a REST API request and client-side processing is enabled.
	 */
	private function handle_create_context( array $metadata, int $attachment_id ): array {
		// Check if already attempted (shouldn't happen on create, but safety check)
		$already_attempted = (bool) get_post_meta( $attachment_id, self::UPLOAD_OPTIMIZATION_ATTEMPTED_META_KEY, true );
		if ( $already_attempted ) {
			return $metadata;
		}

		// Detect if this is a client-side upload
		if ( $this->is_client_side_upload() ) {
			// This is likely a client-side upload. Mark as pending and defer optimization.
			update_post_meta( $attachment_id, self::CLIENT_SIDE_PENDING_META_KEY, true );

			// Schedule a fallback optimization in case finalize never arrives
			// (e.g. closed tab, failed sideload, mobile app, non-image type)
			$this->schedule_fallback_optimization( $attachment_id );

			return $metadata;
		}

		// Server-side upload (or WP < 7.1): queue optimization immediately (old behavior)
		return $this->queue_optimization( $attachment_id, $metadata );
	}

	/**
	 * Detect if this is a client-side upload (WP 7.2+).
	 * Protected so it can be mocked in tests.
	 */
	protected function is_client_side_upload(): bool {
		// Check if we're in a REST API request
		$is_rest_request = defined( 'REST_REQUEST' ) && REST_REQUEST;

		// Check if client-side media processing is enabled (WP 7.2+)
		$client_side_enabled = function_exists( 'wp_is_client_side_media_processing_enabled' ) && wp_is_client_side_media_processing_enabled();

		return $is_rest_request && $client_side_enabled;
	}

	/**
	 * Handle 'update' context - could be finalize, regenerate-thumbnails, or image edit.
	 * Only queue optimization if this is the finalize step of a client-side upload.
	 */
	private function handle_update_context( array $metadata, int $attachment_id ): array {
		// Check if already attempted
		$already_attempted = (bool) get_post_meta( $attachment_id, self::UPLOAD_OPTIMIZATION_ATTEMPTED_META_KEY, true );
		if ( $already_attempted ) {
			return $metadata;
		}

		// Check if this attachment is marked as client-side pending
		$client_side_pending = (bool) get_post_meta( $attachment_id, self::CLIENT_SIDE_PENDING_META_KEY, true );

		if ( ! $client_side_pending ) {
			// Not a client-side upload, so this is regenerate-thumbnails or image edit. Skip.
			return $metadata;
		}

		// Detect if this is the finalize request
		if ( ! $this->is_finalize_request( $attachment_id ) ) {
			// Not finalize yet, keep pending
			return $metadata;
		}

		// This is the finalize step. Clear the pending flag and queue optimization.
		delete_post_meta( $attachment_id, self::CLIENT_SIDE_PENDING_META_KEY );
		delete_transient( self::FINALIZE_DETECTED_TRANSIENT_PREFIX . $attachment_id );

		return $this->queue_optimization( $attachment_id, $metadata );
	}

	/**
	 * Detect if the current request is finalizing this attachment.
	 * Checks both the static flag set during rest_pre_dispatch and a transient.
	 * Protected so it can be mocked in tests.
	 */
	protected function is_finalize_request( int $attachment_id ): bool {
		// Check static flag set during REST request dispatch
		if ( self::$finalize_attachment_id === $attachment_id ) {
			return true;
		}

		// Check transient (set by detect_finalize_request)
		$transient_key = self::FINALIZE_DETECTED_TRANSIENT_PREFIX . $attachment_id;
		if ( get_transient( $transient_key ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Schedule a fallback optimization for client-side uploads that may never finalize.
	 * This catches uploads where the client closed the tab, failed sideload, etc.
	 */
	private function schedule_fallback_optimization( int $attachment_id ): void {
		try {
			// Schedule a delayed check using a unique hook
			// If finalize runs first, the pending flag will be cleared and fallback will no-op
			$timestamp = time() + self::PENDING_FALLBACK_DELAY_SECONDS;
			$fallback_hook = 'image_optimizer_pending_upload_fallback';

			as_schedule_single_action(
				$timestamp,
				$fallback_hook,
				[ 'attachment_id' => $attachment_id ],
				Async_Operation_Queue::OPTIMIZE
			);
		} catch ( Throwable $t ) {
			// Fallback scheduling failed, but don't break the upload
			// The attachment will remain pending but won't block the request
			Logger::error( 'Failed to schedule pending upload fallback. Reason: ' . $t->getMessage() );
		}
	}

	/**
	 * Fallback handler for client-side uploads that never finalized.
	 * Checks if attachment is still pending and queues optimization if needed.
	 *
	 * @async
	 */
	public function handle_pending_upload_fallback( int $attachment_id ): void {
		// Check if already attempted (finalize ran successfully)
		$already_attempted = (bool) get_post_meta( $attachment_id, self::UPLOAD_OPTIMIZATION_ATTEMPTED_META_KEY, true );
		if ( $already_attempted ) {
			// Finalize already ran, nothing to do
			return;
		}

		// Check if still marked as pending
		$client_side_pending = (bool) get_post_meta( $attachment_id, self::CLIENT_SIDE_PENDING_META_KEY, true );
		if ( ! $client_side_pending ) {
			// Not pending anymore, nothing to do
			return;
		}

		// Still pending after fallback delay - finalize never arrived
		// Clear pending flag and queue optimization now
		delete_post_meta( $attachment_id, self::CLIENT_SIDE_PENDING_META_KEY );

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) ) {
			return;
		}

		// Queue optimization directly (bypass handle_upload since we're already in async context)
		$this->queue_optimization( $attachment_id, $metadata );
	}

	/**
	 * Queue the optimization operation.
	 */
	private function queue_optimization( int $attachment_id, array $metadata ): array {
		$meta = new Image_Meta( $attachment_id );

		try {
			// Mark as attempted BEFORE queuing to prevent race conditions and fallback no-op
			update_post_meta( $attachment_id, self::UPLOAD_OPTIMIZATION_ATTEMPTED_META_KEY, true );

			// Clear pending flag if set
			delete_post_meta( $attachment_id, self::CLIENT_SIDE_PENDING_META_KEY );

			$meta
				->set_status( Image_Status::OPTIMIZATION_IN_PROGRESS )
				->save();

			Async_Operation::create(
				Async_Operation_Hook::OPTIMIZE_ON_UPLOAD,
				[ 'attachment_id' => $attachment_id ],
				Async_Operation_Queue::OPTIMIZE
			);
		} catch ( Throwable $t ) {
			$meta
				->set_status( Image_Status::OPTIMIZATION_FAILED )
				->save();
		}

		return $metadata;
	}

	/** @async */
	public function optimize_image_on_upload( int $image_id ) {
		try {
			$oi = new Optimize_Image(
				$image_id,
				'upload',
			);

			$oi->optimize();
		} catch ( Quota_Exceeded_Error $qe ) {
			( new Image_Meta( $image_id ) )
				->set_status( Image_Status::OPTIMIZATION_FAILED )
				->set_error_type( Image_Optimization_Error_Type::QUOTA_EXCEEDED )
				->save();
		} catch ( Connection_Error $ce ) {
			( new Image_Meta( $image_id ) )
				->set_status( Image_Status::OPTIMIZATION_FAILED )
				->set_error_type( Image_Optimization_Error_Type::CONNECTION_ERROR )
				->save();
		} catch ( Image_File_Already_Exists_Error $fe ) {
			( new Image_Meta( $image_id ) )
				->set_status( Image_Status::OPTIMIZATION_FAILED )
				->set_error_type( Image_Optimization_Error_Type::FILE_ALREADY_EXISTS )
				->save();
		} catch ( Throwable $t ) {
			Logger::error( 'Upload optimization error. Reason: ' . $t->getMessage() );

			( new Image_Meta( $image_id ) )
				->set_status( Image_Status::OPTIMIZATION_FAILED )
				->set_error_type( Image_Optimization_Error_Type::GENERIC )
				->save();
		}
	}

	public function __construct() {
		// Hook early in REST request lifecycle to detect finalize route
		add_filter( 'rest_pre_dispatch', [ $this, 'detect_finalize_request' ], 10, 3 );

		// Hook into attachment metadata generation
		add_action( 'wp_generate_attachment_metadata', [ $this, 'handle_upload' ], 10, 3 );

		// Hook async operations
		add_action( Async_Operation_Hook::OPTIMIZE_ON_UPLOAD, [ $this, 'optimize_image_on_upload' ] );
		add_action( 'image_optimizer_pending_upload_fallback', [ $this, 'handle_pending_upload_fallback' ] );
	}
}

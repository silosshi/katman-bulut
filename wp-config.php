<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'katman_bulut' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', '' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'R 78|SwRgum)@d0tBxI*cBmAJE;rC0GX[/C-u4eladdLkZ&?|/yi):Yl!zSYw[>2' );
define( 'SECURE_AUTH_KEY',  'Hx2Blj%Q88AFy w~MRpbx TEGp4Zyb&ar1u,`2Zbc^lq~1+D3{C60AqYn5UyKU3 ' );
define( 'LOGGED_IN_KEY',    'oxfh_e3x4yGt!9l(${uJJ4k<.rB,u18:_ASk,=~2}r-v~dPn;++](ck)kN.g($d|' );
define( 'NONCE_KEY',        '4h)Nlfu.?x,Up]/NCH#(82{Zi#0CCz7~hi>hSVE679HW~L@Q}D.7e>2r *W9(yPR' );
define( 'AUTH_SALT',        'T#cD}I4di{IymTLcn}CGEfwK1d#cz),]W-2i5Q}(s{Na-^fXe P`.OY~_zY<MlCo' );
define( 'SECURE_AUTH_SALT', 'N0,,TZq+<7=a8)s_;aIPut4aCiE0eW;oEbpF1-!SLZjz`4(opy&tfM+DKXZfWtpQ' );
define( 'LOGGED_IN_SALT',   'U&<~q5-Si|1U`HoZ^x[raJ KG2;<ch$er8=j%l.*8x+E/Kw.1@*/tgzQU=QNc87/' );
define( 'NONCE_SALT',       '+?+7(>$~QB5>M7=LF,#] A|!+x{RH0?:z#)>Q=&Sx{%!I(F<WEVVN1j O(9Y^$MR' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';

<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * Localized language
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'local' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', 'root' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

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
define( 'AUTH_KEY',          '&9I<Z<g Df.9N,_.yAIracTfD%gz*W?t)c,nqvwg)Q0gkU@:>lhdW|1qOCWeBjsm' );
define( 'SECURE_AUTH_KEY',   '68m_H@-2MCT*4F&dh.84fjY0R}0-YM7{dk|t>uOoVq.;IE}15AXn4yb9D_}68{E`' );
define( 'LOGGED_IN_KEY',     'g@=lxKF(i(9B.HXwqHC2_?k}Ku.Q~9wHz{<gIv!!WnXFAjr>oan3xG*#mR}r{e@U' );
define( 'NONCE_KEY',         'ouqcU,mkv2+iT5h*AY=nCEhq|ft=D>+h!?XIY}gvIb7g02*sgNLOn97~/1_T]g-.' );
define( 'AUTH_SALT',         '85G}>au`$1QU4*ec;KsXFJzed#@WaSNOk`vx*d,63Jjs6jSb]?gYyLvUWxe8z!w[' );
define( 'SECURE_AUTH_SALT',  'uFf9Xd7GR%Uokxiw^T1]}#s+HPN*TIjxD!6$FwMBL@dKzR8S1~W):frOc4vko@d~' );
define( 'LOGGED_IN_SALT',    'L}lZ~oZ^3)GlrW^UMWF]-rF#fQ@E0Gj%Gad6qFE)I8IJiAXm~I<30R;T};V!zh. ' );
define( 'NONCE_SALT',        '{bKWR<+rnJyX2%q4wmR3%Q[*oJ<z#?[[SL#`T8+[Iff:n: >O8Q_5qcljSNqXIuR' );
define( 'WP_CACHE_KEY_SALT', 'jljgED;K<dW^_@/A_@]<w?+dL4C:1);c`$MrFcbojYk?KpS%Q.tit&94`P{F-/S:' );


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


/* Add any custom values between this line and the "stop editing" line. */



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
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

define( 'WP_ENVIRONMENT_TYPE', 'local' );
/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';

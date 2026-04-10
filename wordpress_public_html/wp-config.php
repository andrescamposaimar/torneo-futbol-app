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
 * * ABSPATH
 *
 * @link https://wordpress.org/documentation/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'entrered_wp257' );

/** Database username */
define( 'DB_USER', 'entrered_wp257' );

/** Database password */
define( 'DB_PASSWORD', 'K(L]!L.0cp!PL93S' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define('DB_CHARSET', 'utf8mb4');

/** The database collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');

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
define( 'AUTH_KEY',         'ulaxvyusff74m67ujqx5jithnzncr3byluyfjdxup4inbscdhadggufvtu0vtg4x' );
define( 'SECURE_AUTH_KEY',  'oaqgc8mu62bu7gogseoxpftwrc4mevzdpnfdbe1jnf6n0rgcsyxuczm25btdju2e' );
define( 'LOGGED_IN_KEY',    'u8ff9hqkbmqwfp7uivncaiusqv2riffbn1zl4xc7cu87lr7moi18xfmngotmh5ws' );
define( 'NONCE_KEY',        'ny6uuwohy0ckfyge1wnxhexwnkrepsniyvtctpdcjrmxi82f2r9gmjvnouiafeyu' );
define( 'AUTH_SALT',        'nbx0ogxghtgj2rbsosu6qhhajps5dtwuprcx0p0jcqgkobszij1a8qweojrnooui' );
define( 'SECURE_AUTH_SALT', '7o8vhkox62ip0frohdk2ketn0gzoxrtysfy5beepprhwixaa581xxazfhjnisgyf' );
define( 'LOGGED_IN_SALT',   'gaco4tv1e5grbaapads0zlnmuaw8t8eicl3k82uqugbj5oxfxyxemxagkjqgmosd' );
define( 'NONCE_SALT',       '4my8xxvm8iwsirs8xady548vqklciiymhppydwcyzrgwujahive96upspkbv5bwp' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
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
 * @link https://wordpress.org/documentation/article/debugging-in-wordpress/
 */
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );

/* Add any custom values between this line and the "stop editing" line. */




/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';

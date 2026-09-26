<?php
/**
 * Plugin Name:       Entre Redes — Cambios de Jugadores
 * Plugin URI:        https://entreredespadres.com.ar
 * Description:       Player change/substitution calendar and lifecycle for the Entre Redes football league. Requires the Entre Redes main plugin.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      8.2
 * Author:            Entre Redes
 * Author URI:        https://entreredespadres.com.ar
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       entre-redes-cambios
 * Domain Path:       /languages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ENTRE_REDES_CAMBIOS_VERSION', '0.1.0' );
define( 'ENTRE_REDES_CAMBIOS_FILE', __FILE__ );
define( 'ENTRE_REDES_CAMBIOS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ENTRE_REDES_CAMBIOS_URL', plugin_dir_url( __FILE__ ) );

// Autoloader — Composer (vendor) or a simple PSR-4 fallback for development.
if ( file_exists( ENTRE_REDES_CAMBIOS_DIR . 'vendor/autoload.php' ) ) {
    require_once ENTRE_REDES_CAMBIOS_DIR . 'vendor/autoload.php';
} else {
    // Minimal PSR-4 fallback so the plugin can be activated even before
    // `composer install` is run. This slice has zero Composer dependencies
    // of its own (see composer.json — only PHP is required), so the fallback
    // is sufficient for every class this plugin currently ships.
    spl_autoload_register( function ( string $class ) {
        $prefix   = 'EntreRedes\\Cambios\\';
        $base_dir = ENTRE_REDES_CAMBIOS_DIR . 'src/';
        $len      = strlen( $prefix );
        if ( strncmp( $prefix, $class, $len ) !== 0 ) {
            return;
        }
        $relative = substr( $class, $len );
        $file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
        if ( file_exists( $file ) ) {
            require $file;
        }
    } );
}

// Activation hook — runs once when the operator clicks "Activate".
register_activation_hook( __FILE__, function () {
    require_once ENTRE_REDES_CAMBIOS_DIR . 'src/Migrations/InitialSchema.php';
    require_once ENTRE_REDES_CAMBIOS_DIR . 'src/Migrations/MigrationRunner.php';

    \EntreRedes\Cambios\Migrations\MigrationRunner::run();
} );

// Deactivation hook — no crons are scheduled by this slice; kept as a no-op
// hook point for future slices that will need to unschedule them. Data is
// NEVER dropped here (that is uninstall.php's job, and only on explicit
// "Delete").
register_deactivation_hook( __FILE__, function () {
    // Intentionally empty for slice 0 — see class docblock in src/Plugin.php.
} );

// Uninstall is handled via uninstall.php (WP calls it only on explicit uninstall).

// Boot the plugin on every request.
add_action( 'plugins_loaded', function () {
    require_once ENTRE_REDES_CAMBIOS_DIR . 'src/Plugin.php';
    \EntreRedes\Cambios\Plugin::boot();
}, 10 );

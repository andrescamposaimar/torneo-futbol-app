<?php
/**
 * Plugin Name:       Entre Redes — Credencial Virtual
 * Plugin URI:        https://entreredespadres.com.ar
 * Description:       Virtual player credential (photo, rotating liveness code, offline cache) for the Entre Redes football league. Requires the Entre Redes main plugin and entre-redes-prode for session auth.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      8.2
 * Author:            Entre Redes
 * Author URI:        https://entreredespadres.com.ar
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       entre-redes-credencial
 * Domain Path:       /languages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ENTRE_REDES_CREDENCIAL_VERSION', '0.1.0' );
define( 'ENTRE_REDES_CREDENCIAL_FILE', __FILE__ );
define( 'ENTRE_REDES_CREDENCIAL_DIR', plugin_dir_path( __FILE__ ) );
define( 'ENTRE_REDES_CREDENCIAL_URL', plugin_dir_url( __FILE__ ) );

// Autoloader — Composer (vendor) or a simple PSR-4 fallback for development.
if ( file_exists( ENTRE_REDES_CREDENCIAL_DIR . 'vendor/autoload.php' ) ) {
    require_once ENTRE_REDES_CREDENCIAL_DIR . 'vendor/autoload.php';
} else {
    // Minimal PSR-4 fallback so the plugin can be activated even before
    // `composer install` is run. Slice 1a's own classes have zero direct
    // Composer dependencies (firebase/php-jwt is used by TokenVerifier only,
    // and is a HARD runtime dependency per the deploy task — see
    // uninstall.php's sibling task brief), so this fallback is enough to
    // activate and run the migrations, but TokenVerifier will fatal without
    // `composer install` having run at least once.
    spl_autoload_register( function ( string $class ) {
        $prefix   = 'EntreRedes\\Credencial\\';
        $base_dir = ENTRE_REDES_CREDENCIAL_DIR . 'src/';
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
    require_once ENTRE_REDES_CREDENCIAL_DIR . 'src/Observability/EventLog.php';
    require_once ENTRE_REDES_CREDENCIAL_DIR . 'src/Observability/WpEventLog.php';
    require_once ENTRE_REDES_CREDENCIAL_DIR . 'src/Migrations/InitialSchema.php';
    require_once ENTRE_REDES_CREDENCIAL_DIR . 'src/Migrations/MigrationRunner.php';

    \EntreRedes\Credencial\Migrations\MigrationRunner::run( new \EntreRedes\Credencial\Observability\WpEventLog() );
} );

// Deactivation hook — no crons are scheduled by slice 1a; kept as a no-op
// hook point for future slices. Data is NEVER dropped here (that is
// uninstall.php's job, and only on explicit "Delete") — migrations are
// additive, so the tables stay inert when the plugin is deactivated.
register_deactivation_hook( __FILE__, function () {
    // Intentionally empty for slice 1a.
} );

// Uninstall is handled via uninstall.php (WP calls it only on explicit uninstall).

// Boot the plugin on every request.
add_action( 'plugins_loaded', function () {
    require_once ENTRE_REDES_CREDENCIAL_DIR . 'src/Plugin.php';
    \EntreRedes\Credencial\Plugin::boot();
}, 10 );

<?php
/**
 * Plugin Name:       Entre Redes — Cambios de Jugadores
 * Plugin URI:        https://entreredespadres.com.ar
 * Description:       Player change/substitution calendar and lifecycle for the Entre Redes football league. Requires the Entre Redes main plugin.
 * Version:           0.1.5
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

define( 'ENTRE_REDES_CAMBIOS_VERSION', '0.1.5' );
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
    require_once ENTRE_REDES_CAMBIOS_DIR . 'src/Observability/EventLog.php';
    require_once ENTRE_REDES_CAMBIOS_DIR . 'src/Observability/WpEventLog.php';
    require_once ENTRE_REDES_CAMBIOS_DIR . 'src/Migrations/InitialSchema.php';
    require_once ENTRE_REDES_CAMBIOS_DIR . 'src/Migrations/MigrationRunner.php';
    require_once ENTRE_REDES_CAMBIOS_DIR . 'src/Admin/ProcessOwnerAuthorizer.php';
    require_once ENTRE_REDES_CAMBIOS_DIR . 'src/Calendario/FechaRepository.php';
    require_once ENTRE_REDES_CAMBIOS_DIR . 'src/Calendario/LigaResolver.php';
    require_once ENTRE_REDES_CAMBIOS_DIR . 'src/Calendario/PartidosApiClient.php';
    require_once ENTRE_REDES_CAMBIOS_DIR . 'src/Calendario/SeedTemporadaService.php';
    require_once ENTRE_REDES_CAMBIOS_DIR . 'src/Calendario/Settings.php';
    require_once ENTRE_REDES_CAMBIOS_DIR . 'src/Calendario/Cron/SeedCalendarioCron.php';

    \EntreRedes\Cambios\Migrations\MigrationRunner::run( new \EntreRedes\Cambios\Observability\WpEventLog() );

    // Grant the process-owner capability to `administrator` by default — see
    // Admin\ProcessOwnerAuthorizer's own docblock for why this is a
    // capability and not a re-check of `manage_options`: today it behaves
    // identically to requiring admin, but the day the comisión wants to hand
    // this screen to someone WITHOUT making them a full site administrator,
    // the fix is granting them this one capability, never redesigning this
    // plugin's permission checks.
    $administrador = get_role( 'administrator' );
    if ( null !== $administrador && ! $administrador->has_cap( \EntreRedes\Cambios\Admin\ProcessOwnerAuthorizer::CAPABILITY ) ) {
        $administrador->add_cap( \EntreRedes\Cambios\Admin\ProcessOwnerAuthorizer::CAPABILITY );
    }

    // Daily calendar-seeding cron — see Calendario\Cron\SeedCalendarioCron's
    // own class docblock for why this is a schedule, not a save_post
    // listener, and for how it guards against overlapping runs.
    \EntreRedes\Cambios\Calendario\Cron\SeedCalendarioCron::schedule();
} );

// Deactivation hook — unschedule the calendar-seeding cron; do NOT drop
// tables (data preserved — that is uninstall.php's job, and only on explicit
// "Delete"). An orphaned cron event that outlives the plugin is a bug that
// only shows up as mystery load months later, with nothing pointing back at
// what caused it — so this runs unconditionally, never behind a flag.
register_deactivation_hook( __FILE__, function () {
    require_once ENTRE_REDES_CAMBIOS_DIR . 'src/Calendario/Cron/SeedCalendarioCron.php';

    \EntreRedes\Cambios\Calendario\Cron\SeedCalendarioCron::unschedule();
} );

// Uninstall is handled via uninstall.php (WP calls it only on explicit uninstall).

// Boot the plugin on every request.
add_action( 'plugins_loaded', function () {
    require_once ENTRE_REDES_CAMBIOS_DIR . 'src/Plugin.php';
    \EntreRedes\Cambios\Plugin::boot();
}, 10 );

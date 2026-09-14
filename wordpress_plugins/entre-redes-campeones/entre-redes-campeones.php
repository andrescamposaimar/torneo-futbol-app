<?php
/**
 * Plugin Name:       Entre Redes — Copa Chaminade
 * Plugin URI:        https://entreredespadres.com.ar
 * Description:       Historical championship record (Copa Chaminade) for the Entre Redes football league.
 * Version:           0.2.0
 * Requires at least: 6.2
 * Requires PHP:      8.2
 * Author:            Entre Redes
 * Author URI:        https://entreredespadres.com.ar
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       entre-redes-campeones
 * Domain Path:       /languages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ENTRE_REDES_CAMPEONES_VERSION', '0.2.0' );
define( 'ENTRE_REDES_CAMPEONES_FILE', __FILE__ );
define( 'ENTRE_REDES_CAMPEONES_DIR', plugin_dir_path( __FILE__ ) );
define( 'ENTRE_REDES_CAMPEONES_URL', plugin_dir_url( __FILE__ ) );

// Autoloader — Composer (vendor) or a simple PSR-4 fallback for development.
//
// Unlike entre-redes-prode, this plugin has NO runtime Composer dependencies
// (design §1 — composer.json's "require" carries only the PHP version
// constraint). The fallback below is therefore always fully capable, never a
// degraded mode, so there is no ENTRE_REDES_CAMPEONES_DEPS_OK apparatus and
// no "incomplete installation" admin notice to maintain.
if ( file_exists( ENTRE_REDES_CAMPEONES_DIR . 'vendor/autoload.php' ) ) {
    require_once ENTRE_REDES_CAMPEONES_DIR . 'vendor/autoload.php';
} else {
    spl_autoload_register( function ( string $class ) {
        $prefix   = 'EntreRedes\\Campeones\\';
        $base_dir = ENTRE_REDES_CAMPEONES_DIR . 'src/';
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
    require_once ENTRE_REDES_CAMPEONES_DIR . 'src/Migrations/InitialSchema.php';
    require_once ENTRE_REDES_CAMPEONES_DIR . 'src/Migrations/MigrationRunner.php';

    \EntreRedes\Campeones\Migrations\MigrationRunner::run();
} );

// Deactivation hook — deliberately a no-op.
//
// This plugin schedules NO cron events: there is no src/Cron/ directory at
// all (design §1). Matching, linking and re-validation are synchronous or
// explicitly human-triggered, never scheduled. The entre-redes-prode
// deactivation hook exists specifically to unschedule its cron events; that
// hook has nothing to do here. It is kept as an explicit, commented no-op —
// rather than omitted entirely — so the next person who copies this
// bootstrap from prode does not silently re-add cron-unscheduling logic for
// cron jobs this plugin will never register. See tests/PluginNoCronTest.php.
register_deactivation_hook( __FILE__, function () {
    // Intentionally empty — see comment above. Deactivation also does not
    // drop tables; uninstall.php handles that, and only on explicit
    // uninstall (WordPress calls it only when the operator chooses
    // "Delete" from the Plugins screen after deactivation).
} );

// Uninstall is handled via uninstall.php (WP calls it only on explicit uninstall).

// Boot the plugin on every request.
add_action( 'plugins_loaded', function () {
    require_once ENTRE_REDES_CAMPEONES_DIR . 'src/Plugin.php';
    \EntreRedes\Campeones\Plugin::boot();
}, 10 );

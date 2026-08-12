<?php
/**
 * Plugin Name:       Entre Redes — Prode Interno
 * Plugin URI:        https://entreredespadres.com.ar
 * Description:       Authenticated predictions game for the Entre Redes football league. Requires the Entre Redes main plugin.
 * Version:           0.9.2
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Entre Redes
 * Author URI:        https://entreredespadres.com.ar
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       entre-redes-prode
 * Domain Path:       /languages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ENTRE_REDES_PRODE_VERSION', '0.9.2' );
define( 'ENTRE_REDES_PRODE_FILE', __FILE__ );
define( 'ENTRE_REDES_PRODE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ENTRE_REDES_PRODE_URL', plugin_dir_url( __FILE__ ) );

// Autoloader — Composer (vendor) or a simple PSR-4 fallback for development.
if ( file_exists( ENTRE_REDES_PRODE_DIR . 'vendor/autoload.php' ) ) {
    require_once ENTRE_REDES_PRODE_DIR . 'vendor/autoload.php';
} else {
    // Minimal PSR-4 fallback so the plugin can be activated and display a
    // meaningful admin notice even before `composer install` is run.
    spl_autoload_register( function ( string $class ) {
        $prefix   = 'EntreRedes\\Prode\\';
        $base_dir = ENTRE_REDES_PRODE_DIR . 'src/';
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

// The fallback autoloader above resolves only EntreRedes\Prode\* from src/ — it
// cannot load Composer dependencies. A plugin packaged without `vendor/` used to
// boot and report a healthy status while every token-signing call was a fatal
// waiting for its first user: Firebase\JWT\JWT (access/intent tokens) and
// Ramsey\Uuid\Uuid (refresh tokens) were simply absent. Resolve dependency
// availability ONCE here so the REST layer can decline to expose auth routes it
// cannot serve, rather than discovering the gap per-request in front of a user.
define(
    'ENTRE_REDES_PRODE_DEPS_OK',
    class_exists( \Firebase\JWT\JWT::class ) && class_exists( \Ramsey\Uuid\Uuid::class )
);

if ( ! ENTRE_REDES_PRODE_DEPS_OK ) {
    add_action( 'admin_notices', function () {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>';
        esc_html_e( 'Entre Redes Prode: incomplete installation.', 'entre-redes-prode' );
        echo '</strong> ';
        esc_html_e(
            'The Composer dependencies (vendor/) are missing, so sign-in and session refresh are disabled. Rebuild the plugin package with "composer install --no-dev" and upload it again.',
            'entre-redes-prode'
        );
        echo '</p></div>';
    } );

    // Also record it server-side: the operator who uploaded the package is not
    // necessarily the person who will read the admin notice.
    error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        '[entre-redes-prode] Composer dependencies missing (vendor/ not installed) — auth routes disabled.'
    );
}

// Activation hook — runs once when the operator clicks "Activate".
register_activation_hook( __FILE__, function () {
    require_once ENTRE_REDES_PRODE_DIR . 'src/Migrations/InitialSchema.php';
    require_once ENTRE_REDES_PRODE_DIR . 'src/Migrations/MigrationRunner.php';

    \EntreRedes\Prode\Migrations\MigrationRunner::run();
} );

// Deactivation hook — unschedule crons; do NOT drop tables (data preserved).
register_deactivation_hook( __FILE__, function () {
    $cron_hooks = [
        'prode_evaluate_matches_cron',
        'prode_recompute_rankings_cron',
        'prode_notify_lock_approaching_cron',
        'prode_create_new_fecha_cron',
        'prode_backfill_match_meta_daily',
    ];
    foreach ( $cron_hooks as $hook ) {
        $timestamp = wp_next_scheduled( $hook );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook );
        }
    }
} );

// Uninstall is handled via uninstall.php (WP calls it only on explicit uninstall).

// Boot the plugin on every request.
add_action( 'plugins_loaded', function () {
    require_once ENTRE_REDES_PRODE_DIR . 'src/Plugin.php';
    \EntreRedes\Prode\Plugin::boot();
}, 10 );

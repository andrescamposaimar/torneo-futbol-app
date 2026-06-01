<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the Entre Redes Prode plugin.
 *
 * Two paths:
 *
 * A) WP test library available (CI or local WP install):
 *    Set WP_TESTS_DIR env var to the wp-tests-lib path, then run:
 *    ./vendor/bin/phpunit
 *
 * B) Standalone (no WP install, e.g. local dev or CI without WP):
 *    This bootstrap loads a minimal WordPress shim so schema-only tests can
 *    run with an in-memory SQLite database via the wp-sqlite-db drop-in.
 *    The shim defines the bare minimum WP functions used by InitialSchema.
 */

// ─── Composer autoloader ────────────────────────────────────────────────────
$autoload = __DIR__ . '/../vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
    echo "Run `composer install` before running PHPUnit.\n";
    exit( 1 );
}
require_once $autoload;

// ─── Constants expected by the plugin ───────────────────────────────────────
if ( ! defined( 'ENTRE_REDES_PRODE_VERSION' ) ) {
    define( 'ENTRE_REDES_PRODE_VERSION', '0.1.0' );
}
if ( ! defined( 'ENTRE_REDES_PRODE_FILE' ) ) {
    define( 'ENTRE_REDES_PRODE_FILE', dirname( __DIR__ ) . '/entre-redes-prode.php' );
}
if ( ! defined( 'ENTRE_REDES_PRODE_DIR' ) ) {
    define( 'ENTRE_REDES_PRODE_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'PRODE_TENANT_ID' ) ) {
    define( 'PRODE_TENANT_ID', 'test_tenant' );
}
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/../../' );
}

// ─── WP test library path ────────────────────────────────────────────────────
$wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( $wp_tests_dir && file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
    // Full WP test environment — load it and let the suite use real wpdb.
    require_once $wp_tests_dir . '/includes/functions.php';
    require_once $wp_tests_dir . '/includes/bootstrap.php';
    return;
}

// ─── Minimal WP shim for standalone tests ───────────────────────────────────
// Only covers what InitialSchema and MigrationRunner actually call.
require_once __DIR__ . '/wp-shim.php';

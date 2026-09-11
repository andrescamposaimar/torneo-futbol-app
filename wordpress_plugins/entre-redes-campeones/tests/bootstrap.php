<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the Entre Redes Campeones plugin.
 *
 * Standalone only: unlike entre-redes-prode, this plugin has no dual
 * WP-test-library path. Every test runs against the in-memory SQLite shim in
 * tests/wp-shim.php — design §9 states no requirement may depend on a live
 * WordPress install to verify (TDD-1).
 */

// ─── Composer autoloader ────────────────────────────────────────────────────
$autoload = __DIR__ . '/../vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
    echo "Run `composer install` before running PHPUnit.\n";
    exit( 1 );
}
require_once $autoload;

// ─── Constants expected by the plugin ───────────────────────────────────────
if ( ! defined( 'ENTRE_REDES_CAMPEONES_VERSION' ) ) {
    define( 'ENTRE_REDES_CAMPEONES_VERSION', '0.1.0' );
}
if ( ! defined( 'ENTRE_REDES_CAMPEONES_FILE' ) ) {
    define( 'ENTRE_REDES_CAMPEONES_FILE', dirname( __DIR__ ) . '/entre-redes-campeones.php' );
}
if ( ! defined( 'ENTRE_REDES_CAMPEONES_DIR' ) ) {
    define( 'ENTRE_REDES_CAMPEONES_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/../../' );
}

// ─── Minimal WP shim for standalone tests ───────────────────────────────────
require_once __DIR__ . '/wp-shim.php';

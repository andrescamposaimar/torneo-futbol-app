<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Cache;

use EntreRedes\Campeones\Cache\CacheInvalidator;
use EntreRedes\Campeones\Tests\Support\FailingOptionsDeleteWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CacheInvalidator (slice 7 — design §7 caching table).
 *
 * Two different deletion techniques for two different key shapes, and the
 * test shim keeps them genuinely separate:
 *   - the history transient has one EXACT, known key, so it is exercised
 *     through get_transient()/set_transient()/delete_transient() — the
 *     shim's transient functions are backed by a plain static array
 *     (tests/wp-shim.php), independent of $wpdb.
 *   - the per-player transients are parametrized by id, so there is no
 *     fixed key to hand delete_transient(); flush() must instead issue a
 *     raw SQL LIKE delete against the options table, mirroring
 *     entre-redes-api.php:51-64's precedent (delete both the value row and
 *     its paired _transient_timeout_* row). This is asserted directly
 *     against $wpdb, the same shape as entre-redes-prode's
 *     ResultChangeListenerTest.
 */
class CacheInvalidatorTest extends TestCase {

    protected function setUp(): void {
        // wp_options is a real WP core table, created once for the whole
        // suite in tests/wp-shim.php (it always exists in production) —
        // clean rows here rather than dropping the table, the same
        // DELETE-based fixture convention every other repository test in
        // this plugin already uses for its own tables.
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}options" );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}options" );
        delete_transient( 'campeones_historia_v2' );
        $GLOBALS['_campeones_test_error_log'] = [];
    }

    public function test_flush_removes_the_history_transient(): void {
        set_transient( 'campeones_historia_v2', [ 'titulos' => [] ], 2592000 );

        global $wpdb;
        ( new CacheInvalidator( $wpdb ) )->flush();

        $this->assertFalse( get_transient( 'campeones_historia_v2' ), 'flush() must remove the history transient.' );
    }

    public function test_flush_removes_every_present_per_player_transient_and_its_timeout_row(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( $p . 'options', [ 'option_name' => '_transient_campeones_titulos_jugador_v1_5078', 'option_value' => 'a' ] );
        $wpdb->insert( $p . 'options', [ 'option_name' => '_transient_timeout_campeones_titulos_jugador_v1_5078', 'option_value' => '123' ] );
        $wpdb->insert( $p . 'options', [ 'option_name' => '_transient_campeones_titulos_jugador_v1_2225', 'option_value' => 'b' ] );
        $wpdb->insert( $p . 'options', [ 'option_name' => '_transient_timeout_campeones_titulos_jugador_v1_2225', 'option_value' => '456' ] );
        $wpdb->insert( $p . 'options', [ 'option_name' => 'unrelated_option', 'option_value' => 'keep' ] );

        ( new CacheInvalidator( $wpdb ) )->flush();

        $remaining = array_column( $wpdb->get_results( "SELECT option_name FROM {$p}options", ARRAY_A ), 'option_name' );

        $this->assertNotContains( '_transient_campeones_titulos_jugador_v1_5078', $remaining );
        $this->assertNotContains( '_transient_timeout_campeones_titulos_jugador_v1_5078', $remaining );
        $this->assertNotContains( '_transient_campeones_titulos_jugador_v1_2225', $remaining );
        $this->assertNotContains( '_transient_timeout_campeones_titulos_jugador_v1_2225', $remaining );
        $this->assertContains( 'unrelated_option', $remaining, 'flush() must not touch an unrelated option row.' );
    }

    public function test_flush_leaves_an_unrelated_transient_untouched(): void {
        set_transient( 'entre_redes_partidos_v1', [ 'x' => 1 ], 3600 );

        global $wpdb;
        ( new CacheInvalidator( $wpdb ) )->flush();

        $this->assertNotFalse( get_transient( 'entre_redes_partidos_v1' ), 'flush() must not remove a transient it does not own.' );

        delete_transient( 'entre_redes_partidos_v1' );
    }

    // -------------------------------------------------------------------------
    // Item 2 (BLOCKER) — flush() discarded the per-player LIKE-delete's
    // return value entirely: no check, no log, no signal. If it fails, the
    // operator sees a success notice while the app keeps serving stale data
    // for up to 30 days with no trace anywhere.
    // -------------------------------------------------------------------------

    public function test_flush_logs_when_the_per_player_delete_query_fails(): void {
        $GLOBALS['_campeones_test_error_log'] = [];

        ( new CacheInvalidator( new FailingOptionsDeleteWpdb() ) )->flush();

        $this->assertNotEmpty(
            $GLOBALS['_campeones_test_error_log'],
            'A failed per-player transient delete must be logged — silence here is exactly the defect item 2 reports.'
        );
        $this->assertStringContainsString(
            'Simulated per-player transient flush failure for test',
            $GLOBALS['_campeones_test_error_log'][0]
        );
    }

    public function test_flush_still_removes_the_history_transient_even_when_the_per_player_delete_fails(): void {
        // The two deletion techniques are independent (design docblock) — a
        // failure in the raw per-player LIKE-delete must not prevent the
        // exact-key history transient from still being cleared.
        set_transient( 'campeones_historia_v2', [ 'titulos' => [] ], 2592000 );

        ( new CacheInvalidator( new FailingOptionsDeleteWpdb() ) )->flush();

        $this->assertFalse( get_transient( 'campeones_historia_v2' ) );
    }
}

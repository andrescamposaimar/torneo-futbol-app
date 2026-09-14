<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests;

use EntreRedes\Campeones\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Pins the wp-shim's wpdb::$last_error reset on success (item 6).
 *
 * Before this fix, $last_error was set on failure by
 * query()/insert()/update()/delete() but never cleared by a LATER
 * successful call on the same instance — and every test in this suite
 * shares one global $wpdb. A failing query anywhere poisoned $last_error
 * for every subsequent test that reads it, regardless of whether that
 * later test's own queries succeeded — reproduced live by reverting the
 * wp_options fixture in tests/wp-shim.php: InitialSchemaTest and
 * MigrationRunnerTest (files this slice never touches) failed for reasons
 * entirely unrelated to themselves.
 *
 * tests/Support/FakeDirectoryWpdb.php:89 already resets $last_error = null
 * on success; this pins the same behaviour in the base shim class every
 * other test in the suite shares.
 */
class WpShimLastErrorResetTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();
    }

    public function test_a_later_successful_query_clears_a_previous_failures_last_error(): void {
        global $wpdb;

        $wpdb->query( 'DELETE FROM a_table_that_does_not_exist_for_this_test' );
        $this->assertNotNull( $wpdb->last_error, 'Sanity check on the double itself: a failing query must set last_error.' );

        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo WHERE 1 = 0" );

        $this->assertNull(
            $wpdb->last_error,
            "A later successful query must clear a previous failure's last_error — otherwise it silently poisons any later test that reads it."
        );
    }

    public function test_a_later_successful_get_results_clears_a_previous_failures_last_error(): void {
        global $wpdb;

        $wpdb->query( 'DELETE FROM another_missing_table_for_this_test' );
        $this->assertNotNull( $wpdb->last_error );

        $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}campeones_titulo" );

        $this->assertNull( $wpdb->last_error, 'get_results() must clear a stale last_error on success.' );
    }

    public function test_a_later_successful_insert_clears_a_previous_failures_last_error(): void {
        global $wpdb;

        $wpdb->query( 'DELETE FROM yet_another_missing_table_for_this_test' );
        $this->assertNotNull( $wpdb->last_error );

        $now = current_time( 'mysql' );
        $wpdb->insert(
            $wpdb->prefix . 'campeones_titulo',
            [
                'anio'          => 2098,
                'zona'          => 'Z',
                'posicion'      => 'campeon',
                'equipo_nombre' => 'TEST-6',
                'created_at'    => $now,
                'updated_at'    => $now,
            ]
        );

        $this->assertNull( $wpdb->last_error, 'insert() must clear a stale last_error on success.' );

        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo WHERE anio = 2098" );
    }

    public function test_a_later_successful_update_clears_a_previous_failures_last_error(): void {
        global $wpdb;

        $now = current_time( 'mysql' );
        $wpdb->insert(
            $wpdb->prefix . 'campeones_titulo',
            [
                'anio'          => 2097,
                'zona'          => 'Z',
                'posicion'      => 'campeon',
                'equipo_nombre' => 'TEST-6-UPDATE',
                'created_at'    => $now,
                'updated_at'    => $now,
            ]
        );
        $id = $wpdb->insert_id;

        $wpdb->query( 'DELETE FROM a_missing_table_before_update_for_this_test' );
        $this->assertNotNull( $wpdb->last_error );

        $wpdb->update( $wpdb->prefix . 'campeones_titulo', [ 'equipo_nombre' => 'TEST-6-UPDATED' ], [ 'id' => $id ] );

        $this->assertNull( $wpdb->last_error, 'update() must clear a stale last_error on success.' );

        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo WHERE id = {$id}" );
    }

    public function test_a_later_successful_delete_clears_a_previous_failures_last_error(): void {
        global $wpdb;

        $now = current_time( 'mysql' );
        $wpdb->insert(
            $wpdb->prefix . 'campeones_titulo',
            [
                'anio'          => 2096,
                'zona'          => 'Z',
                'posicion'      => 'campeon',
                'equipo_nombre' => 'TEST-6-DELETE',
                'created_at'    => $now,
                'updated_at'    => $now,
            ]
        );
        $id = $wpdb->insert_id;

        $wpdb->query( 'DELETE FROM a_missing_table_before_delete_for_this_test' );
        $this->assertNotNull( $wpdb->last_error );

        $wpdb->delete( $wpdb->prefix . 'campeones_titulo', [ 'id' => $id ] );

        $this->assertNull( $wpdb->last_error, 'delete() must clear a stale last_error on success.' );
    }

    public function test_a_later_successful_get_var_clears_a_previous_failures_last_error(): void {
        global $wpdb;

        $wpdb->query( 'DELETE FROM a_missing_table_before_get_var_for_this_test' );
        $this->assertNotNull( $wpdb->last_error );

        $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}campeones_titulo" );

        $this->assertNull( $wpdb->last_error, 'get_var() must clear a stale last_error on success.' );
    }
}

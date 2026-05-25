<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Migrations;

use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that InitialSchema::up() creates all 10 prode_ tables with the
 * expected key columns, and that running it twice is idempotent.
 *
 * Uses the in-memory SQLite shim from tests/wp-shim.php.
 */
class InitialSchemaTest extends TestCase {

    /**
     * The 10 expected tables and a minimum set of columns each must have.
     * Column names are a subset — not exhaustive. The goal is to confirm the
     * schema was applied, not to re-test every column type.
     *
     * @return array<string, string[]>
     */
    private static function expectedTables(): array {
        return [
            'wp_prode_users'                => [ 'id', 'tenant_id', 'dni', 'email', 'provider', 'provider_id', 'display_name', 'session_version' ],
            'wp_prode_associations'         => [ 'id', 'user_id', 'provider', 'provider_id', 'dni', 'player_id' ],
            'wp_prode_refresh_tokens'       => [ 'id', 'user_id', 'jti', 'token_hash', 'expires_at', 'revoked_at' ],
            'wp_prode_fechas'               => [ 'id', 'tenant_id', 'season_id', 'locked_at', 'state' ],
            'wp_prode_fecha_matches'        => [ 'id', 'fecha_id', 'match_id', 'match_kickoff' ],
            'wp_prode_predictions'          => [ 'id', 'user_id', 'fecha_id', 'match_id', 'result', 'score_home', 'score_away' ],
            'wp_prode_scores'               => [ 'id', 'user_id', 'fecha_id', 'match_id', 'points', 'evaluation_method' ],
            'wp_prode_ranking_fecha_cache'  => [ 'id', 'fecha_id', 'user_id', 'total_points', 'rank' ],
            'wp_prode_audit_log'            => [ 'id', 'event_type', 'tenant_id', 'dni_hash', 'provider' ],
            'wp_prode_settings'             => [ 'setting_key', 'setting_value', 'updated_at' ],
        ];
    }

    public function test_all_ten_tables_are_created(): void {
        InitialSchema::up();

        global $wpdb;
        $pdo = $wpdb->getPdo();

        foreach ( self::expectedTables() as $table => $expected_columns ) {
            // SQLite: PRAGMA table_info returns rows for each column.
            $stmt    = $pdo->query( "PRAGMA table_info($table)" );
            $rows    = $stmt->fetchAll( \PDO::FETCH_ASSOC );
            $columns = array_column( $rows, 'name' );

            $this->assertNotEmpty(
                $columns,
                "Table $table should exist after InitialSchema::up()."
            );

            foreach ( $expected_columns as $col ) {
                $this->assertContains(
                    $col,
                    $columns,
                    "Column '$col' should exist in $table."
                );
            }
        }
    }

    public function test_idempotent_second_run_does_not_error(): void {
        // Run twice; the second call should not throw or leave error state.
        InitialSchema::up();
        $results = InitialSchema::up();

        global $wpdb;
        $this->assertNull(
            $wpdb->last_error,
            "Running InitialSchema::up() twice should not produce a DB error. Got: {$wpdb->last_error}"
        );

        // dbDelta (or our shim) returns error messages in the results array.
        $errors = array_filter( $results, static fn( $r ) => str_starts_with( (string) $r, 'Error:' ) );
        $this->assertEmpty(
            $errors,
            'Second run of InitialSchema::up() should not produce error messages. Got: ' . implode( '; ', $errors )
        );
    }

    public function test_settings_seeded_with_defaults(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( "SELECT setting_value FROM {$p}prode_settings WHERE setting_key = 'lock_hours_before'" );
        $this->assertNotNull( $row, "'lock_hours_before' should be seeded in prode_settings." );
        $this->assertSame( '24', $row['setting_value'] );

        $row2 = $wpdb->get_row( "SELECT setting_value FROM {$p}prode_settings WHERE setting_key = 'evaluator_cron_interval_minutes'" );
        $this->assertNotNull( $row2 );
        $this->assertSame( '5', $row2['setting_value'] );
    }

    public function test_tenant_id_seeded_from_constant(): void {
        // PRODE_TENANT_ID is defined in bootstrap.php as 'test_tenant'.
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( "SELECT setting_value FROM {$p}prode_settings WHERE setting_key = 'tenant_id'" );
        $this->assertNotNull( $row, "'tenant_id' should be seeded when PRODE_TENANT_ID is defined." );
        $this->assertSame( 'test_tenant', $row['setting_value'] );
    }

    public function test_prode_users_has_no_wp_user_id_column(): void {
        // AMENDMENT-001: prode_users must NOT have a wp_user_id column.
        InitialSchema::up();

        global $wpdb;
        $pdo  = $wpdb->getPdo();
        $stmt = $pdo->query( 'PRAGMA table_info(wp_prode_users)' );
        $rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
        $cols = array_column( $rows, 'name' );

        $this->assertNotContains(
            'wp_user_id',
            $cols,
            'prode_users must NOT contain wp_user_id (AMENDMENT-001: no wp_users coupling).'
        );

        $this->assertContains(
            'tenant_id',
            $cols,
            'prode_users must have tenant_id column (AMENDMENT-001).'
        );
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Migrations;

use EntreRedes\Credencial\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that InitialSchema::up() creates all 3 credencial_ tables with the
 * expected columns, and that running it twice is idempotent. Adapted from
 * entre-redes-cambios/tests/Migrations/InitialSchemaTest.php.
 *
 * Uses the in-memory SQLite shim from tests/wp-shim.php. The
 * ensurePendingKeyIndex() generated-column ALTER (see InitialSchema's own
 * docblock, mirroring entre-redes-prode's ensureActiveDniIndex) queries
 * information_schema, which does not exist under SQLite — the shim's
 * $wpdb->get_var() catches the resulting PDOException and returns null, so
 * that step is silently skipped here and only exercised against real MySQL.
 */
class InitialSchemaTest extends TestCase {

    /**
     * @return array<string, string[]>
     */
    private static function expectedTables(): array {
        return [
            'wp_credencial_issuance' => [
                'player_id', 'credential_id', 'user_id', 'photo_sha256', 'minted_at', 'updated_at',
            ],
            'wp_credencial_approval_request' => [
                'id', 'type', 'target_player_id', 'requested_by', 'payload', 'status',
                'attachment_id', 'review_note', 'reviewed_by', 'reviewed_at', 'created_at',
            ],
            'wp_credencial_approval_blob' => [
                'request_id', 'photo_binary', 'created_at',
            ],
        ];
    }

    protected function setUp(): void {
        // Unlike entre-redes-cambios's own InitialSchemaTest (which relies on
        // an earlier, alphabetically-preceding test file having already
        // created the cambios_ tables in this same in-memory SQLite
        // connection), this plugin's Auth tests never call InitialSchema, so
        // this class can be the FIRST to touch these tables. Calling up()
        // here first guarantees the DELETE below never hits a missing table
        // and never leaves a stale $wpdb->last_error for
        // test_idempotent_second_run_does_not_error to trip over — the shim's
        // wpdb::query() does not reset last_error on a successful call (see
        // tests/wp-shim.php), so a single failed query anywhere earlier in
        // the process would otherwise poison every later assertNull() check.
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}credencial_issuance" );
        $wpdb->query( "DELETE FROM {$p}credencial_approval_request" );
        $wpdb->query( "DELETE FROM {$p}credencial_approval_blob" );
    }

    public function test_all_three_tables_are_created(): void {
        InitialSchema::up();

        global $wpdb;
        $pdo = $wpdb->getPdo();

        foreach ( self::expectedTables() as $table => $expected_columns ) {
            $stmt    = $pdo->query( "PRAGMA table_info($table)" );
            $rows    = $stmt->fetchAll( \PDO::FETCH_ASSOC );
            $columns = array_column( $rows, 'name' );

            $this->assertNotEmpty( $columns, "Table $table should exist after InitialSchema::up()." );

            foreach ( $expected_columns as $col ) {
                $this->assertContains( $col, $columns, "Column '$col' should exist in $table." );
            }
        }
    }

    public function test_idempotent_second_run_does_not_error(): void {
        InitialSchema::up();
        $results = InitialSchema::up();

        global $wpdb;
        $this->assertNull(
            $wpdb->last_error,
            "Running InitialSchema::up() twice should not produce a DB error. Got: {$wpdb->last_error}"
        );

        $errors = array_filter( $results, static fn( $r ) => str_starts_with( (string) $r, 'Error:' ) );
        $this->assertEmpty(
            $errors,
            'Second run of InitialSchema::up() should not produce error messages. Got: ' . implode( '; ', $errors )
        );
    }

    public function test_approval_request_status_defaults_to_pending(): void {
        InitialSchema::up();

        global $wpdb;
        $pdo  = $wpdb->getPdo();
        $stmt = $pdo->query( 'PRAGMA table_info(wp_credencial_approval_request)' );
        $rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );

        $byName = [];
        foreach ( $rows as $r ) {
            $byName[ $r['name'] ] = $r;
        }

        $this->assertSame( "'pending'", (string) $byName['status']['dflt_value'] );
    }

    public function test_issuance_player_id_is_the_primary_key(): void {
        InitialSchema::up();

        global $wpdb;
        $pdo  = $wpdb->getPdo();
        $stmt = $pdo->query( 'PRAGMA table_info(wp_credencial_issuance)' );
        $rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );

        $byName = [];
        foreach ( $rows as $r ) {
            $byName[ $r['name'] ] = $r;
        }

        $this->assertSame( 1, (int) $byName['player_id']['pk'] );
    }
}

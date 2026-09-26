<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Migrations;

use EntreRedes\Cambios\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that InitialSchema::up() creates all 4 cambios_ tables with the
 * expected key columns, and that running it twice is idempotent.
 *
 * Uses the in-memory SQLite shim from tests/wp-shim.php (copied literally
 * from entre-redes-prode).
 */
class InitialSchemaTest extends TestCase {

    /**
     * @return array<string, string[]>
     */
    private static function expectedTables(): array {
        return [
            'wp_cambios_fecha' => [
                'id', 'season_id', 'orden', 'torneo_liga_ids', 'torneo_label',
                'numero_en_torneo', 'play_date', 'play_date_original', 'veces_postergada',
                'estado', 'estado_origen',
                'estado_actualizado_at', 'estado_actualizado_por', 'created_at', 'updated_at',
            ],
            'wp_cambios_fecha_partido' => [
                'id', 'fecha_id', 'match_id', 'liga_id', 'zona', 'kickoff', 'tiene_resultado',
            ],
            'wp_cambios_settings' => [ 'setting_key', 'setting_value', 'updated_at' ],
            'wp_cambios_capitan'  => [
                'id', 'season_id', 'team_id', 'player_id',
                'designado_por', 'designado_at', 'revocado_at',
            ],
        ];
    }

    protected function setUp(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_fecha_partido" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );
        $wpdb->query( "DELETE FROM {$p}cambios_settings" );
        $wpdb->query( "DELETE FROM {$p}cambios_capitan" );
    }

    public function test_all_four_tables_are_created(): void {
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

    public function test_settings_seeded_with_defaults(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;

        foreach ( InitialSchema::SEED_DEFAULTS as $key => $expectedValue ) {
            $row = $wpdb->get_row(
                $wpdb->prepare( "SELECT setting_value FROM {$p}cambios_settings WHERE setting_key = %s", $key )
            );
            $this->assertNotNull( $row, "'{$key}' should be seeded in cambios_settings." );
            $this->assertSame( $expectedValue, $row['setting_value'] );
        }
    }

    public function test_idempotent_seed_does_not_duplicate_settings_rows(): void {
        InitialSchema::up();
        InitialSchema::up();

        global $wpdb;
        $p     = $wpdb->prefix;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cambios_settings" );

        $this->assertSame( count( InitialSchema::SEED_DEFAULTS ), $count );
    }

    public function test_fecha_estado_defaults_to_programada(): void {
        InitialSchema::up();

        global $wpdb;
        $pdo  = $wpdb->getPdo();
        $stmt = $pdo->query( 'PRAGMA table_info(wp_cambios_fecha)' );
        $rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );

        $byName = [];
        foreach ( $rows as $r ) {
            $byName[ $r['name'] ] = $r;
        }

        $this->assertSame( "'programada'", (string) $byName['estado']['dflt_value'] );
        $this->assertSame( "'derivado'", (string) $byName['estado_origen']['dflt_value'] );
    }
}

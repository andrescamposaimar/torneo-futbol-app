<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Migrations;

use EntreRedes\Campeones\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that InitialSchema::up() creates both campeones_ tables with the
 * expected columns, that running it twice is idempotent, and that both
 * CREATE TABLE statements declare ENGINE=InnoDB explicitly.
 *
 * The ENGINE assertion runs against the raw SQL string returned by
 * InitialSchema::tableDefinitionSql(), BEFORE it reaches the shim's
 * dbDelta translator — the SQLite shim strips ENGINE= clauses entirely
 * (tests/wp-shim.php, _campeones_mysql_to_sqlite()), so this is the only
 * place in composer test where this can be pinned (design §2, §9).
 */
class InitialSchemaTest extends TestCase {

    /**
     * @return array<string, string[]>
     */
    private static function expectedTables(): array {
        return [
            'wp_campeones_titulo'  => [
                'id', 'anio', 'zona', 'posicion', 'equipo_nombre', 'created_at', 'updated_at',
            ],
            'wp_campeones_plantel' => [
                'id', 'titulo_id', 'orden', 'jugador_nombre', 'jugador_nombre_norm',
                'jugador_id', 'es_capitan', 'estado_vinculo', 'candidatos_json', 'resolved_at',
            ],
        ];
    }

    public function test_both_tables_are_created_with_expected_columns(): void {
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

    public function test_up_re_run_preserves_existing_rows_and_columns(): void {
        // tests/wp-shim.php rewrites every CREATE TABLE into
        // CREATE TABLE IF NOT EXISTS unconditionally, so a bare "no error"
        // assertion here would only prove the shim's own forgiveness, not
        // that InitialSchema::up() actually leaves existing data intact.
        // Insert a row, re-run up(), and assert the row and its columns
        // survive — that is the migration's real contract.
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );

        $now = current_time( 'mysql' );
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}campeones_titulo
                    (anio, zona, posicion, equipo_nombre, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s)",
                2016,
                'A',
                'campeon',
                'CHELSEA',
                $now,
                $now
            )
        );

        $results = InitialSchema::up();

        $this->assertNull(
            $wpdb->last_error,
            "Running InitialSchema::up() twice should not produce a DB error. Got: {$wpdb->last_error}"
        );

        $errors = array_filter( $results, static fn( $r ) => str_starts_with( (string) $r, 'Error:' ) );
        $this->assertEmpty(
            $errors,
            'Second run of InitialSchema::up() should not produce error messages. Got: ' . implode( '; ', $errors )
        );

        $row = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}campeones_titulo WHERE anio = 2016 AND zona = 'A' AND posicion = 'campeon'",
            ARRAY_A
        );
        $this->assertNotNull( $row, 'A row inserted before the second up() call must survive it.' );
        $this->assertSame( 'CHELSEA', $row['equipo_nombre'] );

        $pdo     = $wpdb->getPdo();
        $stmt    = $pdo->query( 'PRAGMA table_info(wp_campeones_titulo)' );
        $columns = array_column( $stmt->fetchAll( \PDO::FETCH_ASSOC ), 'name' );
        foreach ( self::expectedTables()['wp_campeones_titulo'] as $col ) {
            $this->assertContains( $col, $columns, "Column '$col' must still exist in wp_campeones_titulo after re-running up()." );
        }
    }

    public function test_both_create_table_statements_declare_engine_innodb(): void {
        global $wpdb;

        $sqls = InitialSchema::tableDefinitionSql( $wpdb->prefix, $wpdb->get_charset_collate() );

        $this->assertCount( 2, $sqls, 'Expected exactly two CREATE TABLE statements (titulo, plantel).' );

        foreach ( $sqls as $sql ) {
            $this->assertStringContainsString(
                'ENGINE=InnoDB',
                $sql,
                'Every CREATE TABLE statement must declare ENGINE=InnoDB explicitly — the SQLite shim ' .
                'strips this clause, so this is the only place composer test can pin it.'
            );
        }
    }

    public function test_jugador_id_is_nullable(): void {
        // REC-3 / REC-6: a title with zero linked squad entries is a valid,
        // complete record — jugador_id must be nullable, never a required FK.
        InitialSchema::up();

        global $wpdb;
        $pdo  = $wpdb->getPdo();
        $stmt = $pdo->query( 'PRAGMA table_info(wp_campeones_plantel)' );
        $rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );

        $byName = [];
        foreach ( $rows as $r ) {
            $byName[ $r['name'] ] = $r;
        }

        $this->assertSame( 0, (int) $byName['jugador_id']['notnull'], 'jugador_id must be nullable.' );
    }

    public function test_default_values_match_the_schema(): void {
        InitialSchema::up();

        global $wpdb;
        $pdo  = $wpdb->getPdo();

        $tituloCols = [];
        foreach ( $pdo->query( 'PRAGMA table_info(wp_campeones_titulo)' )->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
            $tituloCols[ $r['name'] ] = $r;
        }
        $this->assertSame( "'A'", $tituloCols['zona']['dflt_value'], "zona must default to 'A'." );
        $this->assertSame( "'campeon'", $tituloCols['posicion']['dflt_value'], "posicion must default to 'campeon'." );

        $plantelCols = [];
        foreach ( $pdo->query( 'PRAGMA table_info(wp_campeones_plantel)' )->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
            $plantelCols[ $r['name'] ] = $r;
        }
        $this->assertSame( '0', $plantelCols['orden']['dflt_value'], 'orden must default to 0.' );
        $this->assertSame( "'sin_candidato'", $plantelCols['estado_vinculo']['dflt_value'], "estado_vinculo must default to 'sin_candidato'." );
        $this->assertSame( '0', $plantelCols['es_capitan']['dflt_value'], 'es_capitan must default to 0.' );
    }
}

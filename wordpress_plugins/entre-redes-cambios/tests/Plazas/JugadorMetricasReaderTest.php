<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas;

use EntreRedes\Cambios\Plazas\JugadorMetricasReader;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JugadorMetricasReader against the in-memory SQLite shim —
 * same ad hoc `wp_postmeta` table pattern as DictamenContextAssemblerTest,
 * since this class was extracted directly out of that one.
 *
 * The "es padre" assertions below encode the real data verified before this
 * feature was built (see this class's own docblock): the live roster only
 * ever writes `'Padre Activo'` or empty, but the classifier is written to
 * also recognize every "padre*" historical variant found in an old SQL dump,
 * while correctly rejecting every non-padre category found in that SAME
 * dump (`Invitado`, `Docente Activo`, a bare `ExAlumno` with no "Padre"
 * prefix, etc.).
 */
class JugadorMetricasReaderTest extends TestCase {

    private JugadorMetricasReader $reader;

    protected function setUp(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}postmeta (
                meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                meta_key TEXT,
                meta_value TEXT
            )"
        );
        $wpdb->query( "DELETE FROM {$p}postmeta" );

        $this->reader = new JugadorMetricasReader( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}postmeta" );
    }

    /** @param array<string, mixed> $metrics */
    private function putSpMetrics( int $playerId, array $metrics ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'postmeta',
            [
                'post_id'    => $playerId,
                'meta_key'   => 'sp_metrics',
                'meta_value' => serialize( $metrics ),
            ]
        );
    }

    // -------------------------------------------------------------------------
    // esPadre() — the live convention and its historical variants
    // -------------------------------------------------------------------------

    public function test_es_padre_true_for_the_live_convention(): void {
        $this->putSpMetrics( 1, [ 'caracter' => 'Padre Activo' ] );

        $this->assertTrue( $this->reader->resolve( 1 )->esPadre() );
    }

    /**
     * @dataProvider historicalPadreVariants
     */
    public function test_es_padre_true_for_historical_padre_variants( string $caracter ): void {
        $this->putSpMetrics( 2, [ 'caracter' => $caracter ] );

        $this->assertTrue( $this->reader->resolve( 2 )->esPadre(), "'{$caracter}' should read as a padre" );
    }

    /** @return array<int, array{0: string}> */
    public static function historicalPadreVariants(): array {
        return [
            [ 'Padre de Alumno' ],
            [ 'Padre de alumno' ],
            [ 'padre activo' ],
            [ 'Padre exAlumno' ],
            [ 'Padre Exalumno' ],
            [ 'Padre EXALUMNO' ],
            [ 'Padre de Ex-alumno' ],
            [ 'Padre Ex Alumno' ],
            [ '  Padre Activo  ' ], // stray whitespace
        ];
    }

    /**
     * @dataProvider nonPadreValuesFoundInRealData
     */
    public function test_es_padre_false_for_non_padre_categories_found_in_real_data( string $caracter ): void {
        $this->putSpMetrics( 3, [ 'caracter' => $caracter ] );

        $this->assertFalse( $this->reader->resolve( 3 )->esPadre(), "'{$caracter}' should NOT read as a padre" );
    }

    /** @return array<int, array{0: string}> */
    public static function nonPadreValuesFoundInRealData(): array {
        return [
            [ 'Invitado' ],
            [ 'invitado' ],
            [ 'Docente Activo' ],
            [ 'Docente' ],
            [ 'ExDocente' ],
            [ 'Personal Maestranza' ],
            [ 'Personal del Colegio' ],
            [ 'Socio Fundador' ],
            [ 'empleado Activo' ],
            [ 'Reemplazo' ],
            [ 'Defensor' ], // a position value leaked into this field
            // The one genuinely ambiguous historical value — no "Padre"
            // prefix — deliberately reads as NOT a padre. See class
            // docblock: fail toward under-counting padres, never over.
            [ 'ExAlumno' ],
            [ 'Exalumno' ],
        ];
    }

    public function test_es_padre_false_when_caracter_is_empty(): void {
        $this->putSpMetrics( 4, [ 'caracter' => '' ] );

        $this->assertFalse( $this->reader->resolve( 4 )->esPadre() );
    }

    public function test_es_padre_false_when_caracter_is_absent(): void {
        $this->putSpMetrics( 5, [ 'puntaje' => '3' ] );

        $this->assertFalse( $this->reader->resolve( 5 )->esPadre() );
    }

    public function test_es_padre_false_when_the_metrics_row_is_missing_entirely(): void {
        $this->assertFalse( $this->reader->resolve( 999 )->esPadre() );
        $this->assertNull( $this->reader->resolve( 999 )->puntaje() );
    }

    // -------------------------------------------------------------------------
    // puntaje() still behaves exactly as before the extraction
    // -------------------------------------------------------------------------

    public function test_puntaje_reads_the_lowercase_key(): void {
        $this->putSpMetrics( 6, [ 'puntaje' => '2,5' ] );

        $this->assertSame( 2.5, $this->reader->resolve( 6 )->puntaje()->toDecimal() );
    }

    public function test_puntaje_reads_the_uppercase_key_when_lowercase_is_absent(): void {
        $this->putSpMetrics( 7, [ 'Puntaje' => 4.0 ] );

        $this->assertSame( 4.0, $this->reader->resolve( 7 )->puntaje()->toDecimal() );
    }

    // -------------------------------------------------------------------------
    // resolveMuchos() — the batched path used by CandidatosResolver
    // -------------------------------------------------------------------------

    public function test_resolve_muchos_is_total_over_every_requested_id(): void {
        $this->putSpMetrics( 10, [ 'caracter' => 'Padre Activo', 'puntaje' => '3' ] );
        // 11 deliberately has no sp_metrics row at all.

        $resultado = $this->reader->resolveMuchos( [ 10, 11 ] );

        $this->assertTrue( $resultado[10]->esPadre() );
        $this->assertSame( 3.0, $resultado[10]->puntaje()->toDecimal() );
        $this->assertFalse( $resultado[11]->esPadre() );
        $this->assertNull( $resultado[11]->puntaje() );
    }

    public function test_resolve_muchos_returns_an_empty_map_for_an_empty_input(): void {
        $this->assertSame( [], $this->reader->resolveMuchos( [] ) );
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas;

use EntreRedes\Cambios\Observability\InMemoryEventLog;
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

        $this->reader = new JugadorMetricasReader( $wpdb, new InMemoryEventLog() );
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

    // -------------------------------------------------------------------------
    // FIX 4 — determinism when more than one sp_metrics row exists
    // -------------------------------------------------------------------------

    /**
     * WordPress does not enforce uniqueness on `(post_id, meta_key)` in
     * `postmeta` — a player can legitimately end up with two `sp_metrics`
     * rows. resolve() (`ORDER BY meta_id DESC LIMIT 1`) and resolveMuchos()
     * (`ORDER BY meta_id ASC` + last-write-wins) must agree on which one
     * wins — see class docblock, "DETERMINISM WHEN MORE THAN ONE sp_metrics
     * ROW EXISTS".
     */
    public function test_resolve_and_resolve_muchos_agree_when_a_player_has_two_sp_metrics_rows(): void {
        $this->putSpMetrics( 20, [ 'caracter' => 'Invitado', 'puntaje' => '1' ] );        // older row (lower meta_id)
        $this->putSpMetrics( 20, [ 'caracter' => 'Padre Activo', 'puntaje' => '3' ] );    // newest row (higher meta_id) — must win

        $viaResolve = $this->reader->resolve( 20 );
        $viaMuchos  = $this->reader->resolveMuchos( [ 20 ] )[20];

        $this->assertSame( $viaResolve->esPadre(), $viaMuchos->esPadre() );
        $this->assertSame( $viaResolve->puntaje()->toDecimal(), $viaMuchos->puntaje()->toDecimal() );

        // And specifically: the MOST RECENT row (highest meta_id) is the one
        // that wins, per the chosen rule.
        $this->assertTrue( $viaResolve->esPadre() );
        $this->assertSame( 3.0, $viaResolve->puntaje()->toDecimal() );
    }

    // -------------------------------------------------------------------------
    // FIX 3 — read failures must never read as "nobody has metrics"
    // -------------------------------------------------------------------------

    /**
     * A `\wpdb` subclass whose get_var() sets $wpdb->last_error and returns
     * null whenever the SQL contains $mustContain — the get_var() analogue
     * of the get_results() double used elsewhere in this suite (see
     * PlazaRepositoryTest::wpdbThatFailsGetResults()).
     */
    private function wpdbThatFailsGetVar( \wpdb $real, string $mustContain ): \wpdb {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $real );

        return new class( $pdo, $real->prefix, $mustContain ) extends \wpdb {
            private string $mustContain;

            public function __construct( \PDO $pdo, string $prefix, string $mustContain ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix      = $prefix;
                $this->mustContain = $mustContain;
            }

            public function get_var( string $sql ): ?string {
                if ( str_contains( $sql, $this->mustContain ) ) {
                    $this->last_error = 'simulated get_var failure for test';
                    return null;
                }

                return parent::get_var( $sql );
            }
        };
    }

    /**
     * Same pattern, for get_results() — used by resolveMuchos().
     */
    private function wpdbThatFailsGetResults( \wpdb $real, string $mustContain ): \wpdb {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $real );

        return new class( $pdo, $real->prefix, $mustContain ) extends \wpdb {
            private string $mustContain;

            public function __construct( \PDO $pdo, string $prefix, string $mustContain ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix      = $prefix;
                $this->mustContain = $mustContain;
            }

            public function get_results( string $sql, string $output = OBJECT ): array {
                if ( str_contains( $sql, $this->mustContain ) ) {
                    $this->last_error = 'simulated get_results failure for test';
                    return [];
                }

                return parent::get_results( $sql, $output );
            }
        };
    }

    public function test_resolve_throws_when_the_query_fails(): void {
        global $wpdb;

        $failingWpdb   = $this->wpdbThatFailsGetVar( $wpdb, 'sp_metrics' );
        $failingReader = new JugadorMetricasReader( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        $failingReader->resolve( 1 );
    }

    public function test_resolve_records_a_lectura_fallida_event_before_throwing(): void {
        global $wpdb;

        $failingWpdb     = $this->wpdbThatFailsGetVar( $wpdb, 'sp_metrics' );
        $failingEventLog = new InMemoryEventLog();
        $failingReader   = new JugadorMetricasReader( $failingWpdb, $failingEventLog );

        try {
            $failingReader->resolve( 1 );
            $this->fail( 'Expected RuntimeException.' );
        } catch ( \RuntimeException $e ) {
            $this->assertInstanceOf( \RuntimeException::class, $e );
        }

        $this->assertTrue( $failingEventLog->has( 'lectura.fallida' ) );
        $this->assertSame( 'resolve', $failingEventLog->last()['contexto']['operacion'] );
        $this->assertSame( 1, $failingEventLog->last()['contexto']['player_id'] );
        $this->assertNotNull( $failingEventLog->last()['contexto']['last_error'] ?? null );
    }

    public function test_resolve_a_genuinely_absent_row_still_reads_as_no_metrics(): void {
        // No wpdb failure at all here — just confirming the guard did not
        // change the existing, correct meaning of "no row found".
        $resultado = $this->reader->resolve( 999999 );

        $this->assertFalse( $resultado->esPadre() );
        $this->assertNull( $resultado->puntaje() );
    }

    public function test_resolve_muchos_throws_when_the_query_fails(): void {
        global $wpdb;

        $failingWpdb   = $this->wpdbThatFailsGetResults( $wpdb, 'sp_metrics' );
        $failingReader = new JugadorMetricasReader( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        $failingReader->resolveMuchos( [ 1, 2 ] );
    }

    public function test_resolve_muchos_records_a_lectura_fallida_event_before_throwing(): void {
        global $wpdb;

        $failingWpdb     = $this->wpdbThatFailsGetResults( $wpdb, 'sp_metrics' );
        $failingEventLog = new InMemoryEventLog();
        $failingReader   = new JugadorMetricasReader( $failingWpdb, $failingEventLog );

        try {
            $failingReader->resolveMuchos( [ 1, 2 ] );
            $this->fail( 'Expected RuntimeException.' );
        } catch ( \RuntimeException $e ) {
            $this->assertInstanceOf( \RuntimeException::class, $e );
        }

        $this->assertTrue( $failingEventLog->has( 'lectura.fallida' ) );
        $this->assertSame( 'resolveMuchos', $failingEventLog->last()['contexto']['operacion'] );
        $this->assertSame( 2, $failingEventLog->last()['contexto']['player_ids_count'] );
        $this->assertNotNull( $failingEventLog->last()['contexto']['last_error'] ?? null );
    }
}

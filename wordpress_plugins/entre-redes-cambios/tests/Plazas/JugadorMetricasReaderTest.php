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
 * `caracter` now lives in its own ACF postmeta row, separate from
 * `sp_metrics` (see JugadorMetricasReader's class docblock, "Reads TWO
 * independent postmeta values"); `putSpMetrics()` below writes both rows
 * from one call so every existing test keeps its original shape.
 *
 * The "es padre" assertions below encode the real data verified before this
 * feature was built (see this class's own docblock): historically the live
 * roster only ever wrote `'Padre Activo'` or empty in the legacy
 * `sp_metrics['caracter']` field, but the classifier is written to also
 * recognize every "padre*" variant found in an old SQL dump, while correctly
 * rejecting every non-padre category found in that SAME dump (`Invitado`,
 * `Docente Activo`, a bare `ExAlumno` with no "Padre" prefix, etc.). The
 * REAL, CURRENT ACF vocabulary is covered separately below, in
 * `realAcfCaracterVocabulary()`.
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

    /**
     * Writes `sp_metrics` (for `puntaje`) and, if `$metrics` carries a
     * `caracter` key, ALSO writes it as a separate, un-serialized `caracter`
     * postmeta row — exactly how the reader now expects the two facts to be
     * split across two rows (see JugadorMetricasReader's class docblock,
     * "Reads TWO independent postmeta values"). Kept as one helper, with the
     * split done internally, so every existing call site
     * (`putSpMetrics($id, ['caracter' => ..., 'puntaje' => ...])`) keeps
     * working unchanged.
     *
     * @param array<string, mixed> $metrics
     */
    private function putSpMetrics( int $playerId, array $metrics ): void {
        global $wpdb;

        if ( array_key_exists( 'caracter', $metrics ) ) {
            $this->putCaracter( $playerId, (string) $metrics['caracter'] );
            unset( $metrics['caracter'] );
        }

        $wpdb->insert(
            $wpdb->prefix . 'postmeta',
            [
                'post_id'    => $playerId,
                'meta_key'   => 'sp_metrics',
                'meta_value' => serialize( $metrics ),
            ]
        );
    }

    /**
     * Writes the ACF `caracter` field the way ACF itself stores a simple
     * text field: a single, un-serialized string under `meta_key` =
     * `caracter` — a row of its own, never nested inside `sp_metrics`.
     */
    private function putCaracter( int $playerId, string $caracter ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'postmeta',
            [
                'post_id'    => $playerId,
                'meta_key'   => 'caracter',
                'meta_value' => $caracter,
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

    /**
     * PRODUCTION DATA, not assumed: the live ACF `caracter` vocabulary,
     * verified against `wp-json/wp/v2/sp_player/{id}` (`acf.caracter`)
     * across 1105 players — `Padre Alumno` 570, `Padre Ex-Alumno` 268,
     * `Invitado` 129, empty 114, `Personal Colegio` 22, `Socio Fundador` 2.
     * Unlike `historicalPadreVariants()` / `nonPadreValuesFoundInRealData()`
     * above (the legacy `sp_metrics['caracter']` free text this field used
     * to be), these six ARE the current, real values a live player record
     * can hold.
     *
     * @dataProvider realAcfCaracterVocabulary
     */
    public function test_es_padre_matches_the_real_acf_caracter_vocabulary( string $caracter, bool $esperadoPadre ): void {
        $this->putSpMetrics( 30, [ 'caracter' => $caracter ] );

        $this->assertSame(
            $esperadoPadre,
            $this->reader->resolve( 30 )->esPadre(),
            "'{$caracter}' should " . ( $esperadoPadre ? '' : 'NOT ' ) . 'read as a padre'
        );
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function realAcfCaracterVocabulary(): array {
        return [
            'Padre Alumno (570 of 1105, production)'     => [ 'Padre Alumno', true ],
            'Padre Ex-Alumno (268 of 1105, production)'  => [ 'Padre Ex-Alumno', true ],
            'Invitado (129 of 1105, production)'         => [ 'Invitado', false ],
            'empty (114 of 1105, production)'            => [ '', false ],
            'Personal Colegio (22 of 1105, production)'  => [ 'Personal Colegio', false ],
            'Socio Fundador (2 of 1105, production)'     => [ 'Socio Fundador', false ],
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

    /**
     * THE regression this whole fix exists to prevent: `puntaje` and
     * `caracter` come from two DIFFERENT postmeta rows now (`sp_metrics` and
     * the ACF `caracter` field, respectively) — someone merging the two
     * sources back into a single blob read would break this silently. Writes
     * the two rows directly (not via `putSpMetrics()`, so the test does not
     * depend on that helper's own splitting logic) and asserts both facts
     * resolve correctly from their own, independent source.
     */
    public function test_puntaje_comes_from_sp_metrics_while_caracter_comes_from_the_acf_field(): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'postmeta',
            [
                'post_id'    => 8,
                'meta_key'   => 'sp_metrics',
                'meta_value' => serialize( [ 'puntaje' => '3' ] ),
            ]
        );
        $wpdb->insert(
            $wpdb->prefix . 'postmeta',
            [
                'post_id'    => 8,
                'meta_key'   => 'caracter',
                'meta_value' => 'Padre Alumno',
            ]
        );

        $resultado = $this->reader->resolve( 8 );

        $this->assertSame( 3.0, $resultado->puntaje()->toDecimal() );
        $this->assertTrue( $resultado->esPadre() );
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
    // fetchLatestMetaValuesFor() chunking — see JugadorMetricasReader's class
    // docblock, "fetchLatestMetaValuesFor() CHUNKS $playerIds, NEVER ONE
    // UNBOUNDED `IN (...)`". This is what Plazas\CandidatosResolver's
    // `buscarPaginado()` exercises for real when `?seccion=padron_completo`
    // resolves ~1000 player ids BEFORE pagination.
    // -------------------------------------------------------------------------

    /**
     * Reads the private ID_CHUNK_SIZE constant rather than hardcoding it here
     * a second time — these tests must stay correct even if that constant's
     * value is tuned later.
     */
    private static function chunkSize(): int {
        return ( new \ReflectionClassConstant( JugadorMetricasReader::class, 'ID_CHUNK_SIZE' ) )->getValue();
    }

    /**
     * A population strictly larger than one chunk (2 full chunks + a partial
     * third) must resolve to EXACTLY the same map a single, unchunked query
     * would have produced — proving the per-chunk merge is a plain union,
     * never a silent overwrite or drop across chunk boundaries.
     */
    public function test_resolve_muchos_chunks_a_population_larger_than_one_batch_and_merges_results(): void {
        $chunkSize = self::chunkSize();
        $total     = ( $chunkSize * 2 ) + 7; // 2 full chunks + a partial 3rd
        $playerIds = range( 1, $total );

        foreach ( $playerIds as $playerId ) {
            // Deterministic, player-id-derived expectation: even ids are
            // padres with puntaje 3, odd ids are non-padres with puntaje 1.
            $esPadre = 0 === $playerId % 2;
            $this->putSpMetrics( $playerId, [
                'caracter' => $esPadre ? 'Padre Alumno' : 'Invitado',
                'puntaje'  => $esPadre ? '3' : '1',
            ] );
        }

        $resultado = $this->reader->resolveMuchos( $playerIds );

        $this->assertCount( $total, $resultado );

        foreach ( $playerIds as $playerId ) {
            $esperadoPadre   = 0 === $playerId % 2;
            $esperadoPuntaje = $esperadoPadre ? 3.0 : 1.0;

            $this->assertSame( $esperadoPadre, $resultado[ $playerId ]->esPadre(), "player {$playerId} esPadre()" );
            $this->assertSame( $esperadoPuntaje, $resultado[ $playerId ]->puntaje()->toDecimal(), "player {$playerId} puntaje()" );
        }
    }

    /**
     * Same pattern as `wpdbThatFailsGetResults()`, but fails only on the Nth
     * matching call rather than every one — needed to simulate a failure on a
     * LATER chunk while earlier chunks succeed normally.
     */
    private function wpdbThatFailsGetResultsOnNthMatchingCall( \wpdb $real, string $mustContain, int $failOnCallNumber ): \wpdb {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $real );

        return new class( $pdo, $real->prefix, $mustContain, $failOnCallNumber ) extends \wpdb {
            private string $mustContain;
            private int $failOnCallNumber;
            private int $matchingCallCount = 0;

            public function __construct( \PDO $pdo, string $prefix, string $mustContain, int $failOnCallNumber ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix           = $prefix;
                $this->mustContain      = $mustContain;
                $this->failOnCallNumber = $failOnCallNumber;
            }

            public function get_results( string $sql, string $output = OBJECT ): array {
                if ( str_contains( $sql, $this->mustContain ) ) {
                    ++$this->matchingCallCount;

                    if ( $this->matchingCallCount === $this->failOnCallNumber ) {
                        $this->last_error = 'simulated get_results failure for test (later chunk)';
                        return [];
                    }
                }

                return parent::get_results( $sql, $output );
            }
        };
    }

    /**
     * THE regression this whole work unit exists to prevent: a failure in a
     * chunk that is NOT the first must still throw — never silently return
     * the earlier chunks' players as "resolved" while quietly dropping the
     * rest, which would misread a partial DB failure as "these remaining
     * players have no metrics" (see class docblock, "READ FAILURES MUST
     * NEVER READ AS 'NOBODY HAS METRICS'").
     */
    public function test_resolve_muchos_throws_when_a_later_chunk_fails(): void {
        global $wpdb;

        $chunkSize = self::chunkSize();
        $total     = ( $chunkSize * 2 ) + 7; // 3 chunks total
        $playerIds = range( 1, $total );

        foreach ( $playerIds as $playerId ) {
            $this->putSpMetrics( $playerId, [ 'caracter' => 'Padre Alumno', 'puntaje' => '3' ] );
        }

        // Fails on the 2nd call whose SQL contains 'sp_metrics' — i.e. the
        // SECOND chunk's metrics query, not the first.
        $failingWpdb   = $this->wpdbThatFailsGetResultsOnNthMatchingCall( $wpdb, 'sp_metrics', 2 );
        $failingReader = new JugadorMetricasReader( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        $failingReader->resolveMuchos( $playerIds );
    }

    // -------------------------------------------------------------------------
    // FIX 4 — determinism when more than one row exists under a meta_key
    // -------------------------------------------------------------------------

    /**
     * WordPress does not enforce uniqueness on `(post_id, meta_key)` in
     * `postmeta` — a player can legitimately end up with two rows under the
     * SAME meta_key. This applies independently to BOTH `sp_metrics`
     * (puntaje) and `caracter` (padre/guest) — this test seeds two rows of
     * EACH. resolve() (`ORDER BY meta_id DESC LIMIT 1`, per meta_key) and
     * resolveMuchos() (`ORDER BY meta_id ASC` + last-write-wins, per
     * meta_key) must agree on which one wins — see class docblock,
     * "DETERMINISM WHEN MORE THAN ONE ROW EXISTS FOR A PLAYER".
     */
    public function test_resolve_and_resolve_muchos_agree_when_a_player_has_two_rows_per_meta_key(): void {
        $this->putSpMetrics( 20, [ 'caracter' => 'Invitado', 'puntaje' => '1' ] );        // older rows (lower meta_id)
        $this->putSpMetrics( 20, [ 'caracter' => 'Padre Activo', 'puntaje' => '3' ] );    // newest rows (higher meta_id) — must win

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

    /**
     * Same guard, but on the `caracter` read specifically — the ACF field
     * this fix moved `resolve()` onto. A failed read here must throw, never
     * silently resolve to "not a padre" (see class docblock, "READ FAILURES
     * MUST NEVER READ AS 'NOBODY HAS METRICS'").
     */
    public function test_resolve_throws_when_the_caracter_query_fails(): void {
        global $wpdb;

        $failingWpdb   = $this->wpdbThatFailsGetVar( $wpdb, "'caracter'" );
        $failingReader = new JugadorMetricasReader( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        $failingReader->resolve( 1 );
    }

    public function test_resolve_records_a_lectura_fallida_event_before_throwing_on_caracter_failure(): void {
        global $wpdb;

        $failingWpdb     = $this->wpdbThatFailsGetVar( $wpdb, "'caracter'" );
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
        $this->assertSame( 'caracter', $failingEventLog->last()['contexto']['meta_key'] );
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

    /**
     * Same guard as `test_resolve_muchos_throws_when_the_query_fails()`, but
     * on the batched `caracter` read — a failed read here must throw, never
     * silently resolve the whole batch to "nobody is a padre".
     */
    public function test_resolve_muchos_throws_when_the_caracter_query_fails(): void {
        global $wpdb;

        $failingWpdb   = $this->wpdbThatFailsGetResults( $wpdb, "'caracter'" );
        $failingReader = new JugadorMetricasReader( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        $failingReader->resolveMuchos( [ 1, 2 ] );
    }

    public function test_resolve_muchos_records_a_lectura_fallida_event_before_throwing_on_caracter_failure(): void {
        global $wpdb;

        $failingWpdb     = $this->wpdbThatFailsGetResults( $wpdb, "'caracter'" );
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
        $this->assertSame( 'caracter', $failingEventLog->last()['contexto']['meta_key'] );
        $this->assertSame( 2, $failingEventLog->last()['contexto']['player_ids_count'] );
        $this->assertNotNull( $failingEventLog->last()['contexto']['last_error'] ?? null );
    }
}

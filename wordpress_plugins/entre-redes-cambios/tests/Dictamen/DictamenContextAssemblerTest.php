<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Dictamen\DictamenContextAssembler;
use EntreRedes\Cambios\Dictamen\DictamenEngineFactory;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\CandidatosResolver;
use EntreRedes\Cambios\Plazas\Exception\FechaCountUnavailableException;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for DictamenContextAssembler against the in-memory
 * SQLite shim — real PlazaRepository, real FechaRepository, real Settings,
 * plus an ad hoc `wp_postmeta` table (a real WordPress core table this
 * plugin's test schema does not otherwise create) for the entrante puntaje
 * lookup, and `wp_posts` via wp-shim.php's wp_test_create_posts_table() for
 * the padres-viables tests below.
 */
class DictamenContextAssemblerTest extends TestCase {

    private const SEASON_ID = 359;

    private PlazaRepository $plazaRepository;
    private FechaRepository $fechaRepository;
    private Settings $settings;
    private InMemoryEventLog $eventLog;
    private DictamenContextAssembler $assembler;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_ocupacion" );
        $wpdb->query( "DELETE FROM {$p}cambios_plaza" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}postmeta (
                meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                meta_key TEXT,
                meta_value TEXT
            )"
        );
        $wpdb->query( "DELETE FROM {$p}postmeta" );

        // Only needed by the padres-viables tests below (Plazas\CandidatosResolver's
        // own season-roster query) — see CandidatosResolverTest for the same
        // ad hoc tables applied in isolation. Schema owned by wp-shim.php's
        // wp_test_create_posts_table() — see its docblock for why.
        wp_test_create_posts_table( $wpdb );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}term_relationships (
                object_id INTEGER,
                term_taxonomy_id INTEGER
            )"
        );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}term_taxonomy (
                term_taxonomy_id INTEGER PRIMARY KEY,
                term_id INTEGER,
                taxonomy TEXT
            )"
        );
        $wpdb->query( "DELETE FROM {$p}term_relationships" );
        $wpdb->query( "DELETE FROM {$p}term_taxonomy" );
        $wpdb->query( "INSERT OR IGNORE INTO {$p}term_taxonomy (term_taxonomy_id, term_id, taxonomy) VALUES (" . self::SEASON_ID . ', ' . self::SEASON_ID . ", 'sp_season')" );
        $wpdb->query( "DELETE FROM {$p}cambios_settings" );

        $this->eventLog        = new InMemoryEventLog();
        $this->plazaRepository = new PlazaRepository( $wpdb, $this->eventLog );
        $this->fechaRepository = new FechaRepository( $wpdb, new InMemoryEventLog() );
        $this->settings        = new Settings( $wpdb );

        $this->assembler = new DictamenContextAssembler(
            $this->plazaRepository,
            $this->fechaRepository,
            $this->settings,
            $wpdb,
            $this->eventLog
        );

        // Every openPlaza() call below uses fecha_desde_id 1 as the plaza's
        // genesis fecha (PlazaRepository::assertFechaExistsInSeason()
        // requires it to exist) — seeded once here, so individual tests only
        // need to seed the fecha_id their OWN solicitud actually targets.
        $this->seedFecha( 1, self::SEASON_ID, '2026-01-03' );
    }

    protected function tearDown(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_ocupacion" );
        $wpdb->query( "DELETE FROM {$p}cambios_plaza" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );
        $wpdb->query( "DELETE FROM {$p}postmeta" );
        $wpdb->query( "DELETE FROM {$p}posts" );
        $wpdb->query( "DELETE FROM {$p}term_relationships" );
        $wpdb->query( "DELETE FROM {$p}cambios_settings" );
        InitialSchema::up(); // restore seeds for any test that runs after this file
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function seedFecha( int $fechaId, int $seasonId, string $playDate = '2026-05-30', string $estado = 'programada' ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'cambios_fecha',
            [
                'id'                 => $fechaId,
                'season_id'          => $seasonId,
                'orden'              => $fechaId,
                'torneo_liga_ids'    => '1',
                'torneo_label'       => 'Apertura',
                'numero_en_torneo'   => $fechaId,
                'play_date'          => $playDate,
                'play_date_original' => $playDate,
                'estado'             => $estado,
                'created_at'         => '2026-01-01 00:00:00',
                'updated_at'         => '2026-01-01 00:00:00',
            ]
        );
    }

    /**
     * Writes `sp_metrics` (for `puntaje`) and, if `$metrics` carries a
     * `caracter` key, ALSO writes it as a separate, un-serialized `caracter`
     * postmeta row — matching how JugadorMetricasReader now reads it (see
     * its class docblock, "Reads TWO independent postmeta values"). Kept as
     * one helper so every existing call site keeps its original shape.
     *
     * @param array<string, mixed> $metrics
     */
    private function putSpMetrics( int $playerId, array $metrics ): void {
        global $wpdb;

        if ( array_key_exists( 'caracter', $metrics ) ) {
            $wpdb->insert(
                $wpdb->prefix . 'postmeta',
                [
                    'post_id'    => $playerId,
                    'meta_key'   => 'caracter',
                    'meta_value' => (string) $metrics['caracter'],
                ]
            );
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

    private function putSetting( string $key, string $value ): void {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}cambios_settings (setting_key, setting_value, updated_at) VALUES (%s, %s, %s)",
                $key,
                $value,
                '2026-01-01 00:00:00'
            )
        );
    }

    /** Registers a player in SEASON_ID's `sp_season` roster — see CandidatosResolverTest. */
    private function seedPlayerEnTemporada( int $playerId ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( $p . 'posts', [ 'ID' => $playerId, 'post_type' => 'sp_player', 'post_status' => 'publish' ] );
        $wpdb->insert( $p . 'term_relationships', [ 'object_id' => $playerId, 'term_taxonomy_id' => self::SEASON_ID ] );
    }

    // -------------------------------------------------------------------------
    // assemble() — happy path
    // -------------------------------------------------------------------------

    public function test_assemble_builds_a_complete_context_for_a_favorable_sustitucion(): void {
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $this->putSpMetrics( 888, [ 'puntaje' => '2,5' ] );

        $solicitud = SolicitudDeCambio::sustitucion(
            self::SEASON_ID,
            100,
            $plazaId,
            888,
            5,
            ( new \DateTimeImmutable( '2026-05-25 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp()
        );

        $ctx = $this->assembler->assemble( $solicitud );

        $this->assertSame( $plazaId, (int) $ctx->plaza()['id'] );
        $this->assertCount( 1, $ctx->ocupaciones() );
        $this->assertNotNull( $ctx->vigente() );
        $this->assertSame( 777, (int) $ctx->vigente()['player_id'] );
        $this->assertNotNull( $ctx->entrantePuntaje() );
        $this->assertSame( 2.5, $ctx->entrantePuntaje()->toDecimal() );
        $this->assertSame( [], $ctx->entranteOcupacionesEnOtrasPlazas() );
        $this->assertSame( [], $ctx->entrantePlazasConCierreTruncado() );
        $this->assertArrayHasKey( 'apertura_solicitudes', $ctx->plazosUtc() );
        $this->assertIsInt( ( $ctx->countResolvedFechasSinceFn() )( 5 ) );
    }

    public function test_assemble_never_queries_entrante_data_for_a_regreso(): void {
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->seedFecha( 5, self::SEASON_ID );

        $solicitud = SolicitudDeCambio::regreso(
            self::SEASON_ID,
            100,
            $plazaId,
            5,
            ( new \DateTimeImmutable( '2026-05-25 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp()
        );

        $ctx = $this->assembler->assemble( $solicitud );

        $this->assertNull( $ctx->entrantePuntaje() );
        $this->assertSame( [], $ctx->entranteOcupacionesEnOtrasPlazas() );
        $this->assertSame( [], $ctx->entrantePlazasConCierreTruncado() );
    }

    // -------------------------------------------------------------------------
    // assemble() — a half-built context must never be assembled
    // -------------------------------------------------------------------------

    public function test_assemble_throws_when_the_plaza_does_not_exist(): void {
        $this->seedFecha( 5, self::SEASON_ID );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 999999, 888, 5, time() );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'plaza 999999 does not exist' );

        $this->assembler->assemble( $solicitud );
    }

    public function test_assemble_throws_when_the_fecha_does_not_exist(): void {
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 999999, time() );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'fecha 999999 does not exist' );

        $this->assembler->assemble( $solicitud );
    }

    // -------------------------------------------------------------------------
    // assemble() — the plazos frame is UTC (computeUtc, never compute)
    // -------------------------------------------------------------------------

    public function test_assemble_computes_plazos_in_utc_not_civil_time(): void {
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );

        $solicitud = SolicitudDeCambio::regreso( self::SEASON_ID, 100, $plazaId, 5, time() );
        $ctx       = $this->assembler->assemble( $solicitud );

        // Default timezone is America/Argentina/Buenos_Aires (UTC-3, no DST).
        // publicacion offset defaults to {days:-1, time:"00:00:00"} — the
        // civil deadline is 2026-05-29 00:00:00 ART, i.e. 2026-05-29 03:00:00
        // UTC. If this ever comes back as the bare civil string instead,
        // compute() leaked in where computeUtc() belongs.
        $this->assertSame( '2026-05-29 03:00:00', $ctx->plazosUtc()['publicacion'] );
    }

    // -------------------------------------------------------------------------
    // entrantePuntaje() — two keys, never a silent 0
    // -------------------------------------------------------------------------

    public function test_entrante_puntaje_reads_the_lowercase_key(): void {
        $ctx = $this->assembleWithEntrante( [ 'puntaje' => '2,5' ] );

        $this->assertSame( 2.5, $ctx->entrantePuntaje()->toDecimal() );
    }

    public function test_entrante_puntaje_reads_the_uppercase_key(): void {
        $ctx = $this->assembleWithEntrante( [ 'Puntaje' => 4.0 ] );

        $this->assertSame( 4.0, $ctx->entrantePuntaje()->toDecimal() );
    }

    public function test_entrante_puntaje_prefers_the_lowercase_key_when_both_are_present(): void {
        $ctx = $this->assembleWithEntrante( [ 'puntaje' => '1,5', 'Puntaje' => 5.0 ] );

        $this->assertSame( 1.5, $ctx->entrantePuntaje()->toDecimal() );
    }

    public function test_entrante_puntaje_is_null_and_logged_when_neither_key_is_present(): void {
        $ctx = $this->assembleWithEntrante( [ 'otro_campo' => 'x' ] );

        $this->assertNull( $ctx->entrantePuntaje() );
        $this->assertTrue( $this->eventLog->has( 'entrante.puntaje_no_encontrado' ) );
    }

    public function test_entrante_puntaje_is_null_when_sp_metrics_row_is_missing_entirely(): void {
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->seedFecha( 5, self::SEASON_ID );
        // Deliberately no putSpMetrics() call.

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, time() );
        $ctx       = $this->assembler->assemble( $solicitud );

        $this->assertNull( $ctx->entrantePuntaje() );
        $this->assertTrue( $this->eventLog->has( 'entrante.puntaje_no_encontrado' ) );
    }

    /**
     * THE test that proves a missing puntaje never defaults to 0 — a value
     * of 0 is not one of the torneo's 9 valid discrete puntajes, so it can
     * ONLY ever reach this rule as `null` (a legitimate missing-data
     * signal), never as the number zero standing in for "no objection".
     */
    public function test_entrante_puntaje_never_defaults_to_zero(): void {
        $ctx = $this->assembleWithEntrante( [] );

        $this->assertNull( $ctx->entrantePuntaje() );
        // Strict, deliberately: null == 0 is TRUE under loose comparison —
        // exactly the silent coercion this test exists to rule out.
        $this->assertNotSame( 0, $ctx->entrantePuntaje() );
    }

    /**
     * UPDATED by the puntaje-cero production fix (2026-10-04):
     * `JugadorMetricasReader::extractPuntaje()` no longer lets
     * `Puntaje::fromDecimal()`'s `\InvalidArgumentException` escape — see its
     * own class docblock, "AN OTHERWISE-INVALID STORED VALUE DEGRADES TO
     * NULL TOO, BUT IS LOGGED". Before that fix, THIS exact shape (a
     * malformed stored puntaje for a solicitud's own named entrante) threw
     * out of `assemble()` too — a second, previously undiscovered instance
     * of the same "one bad row aborts the whole request" failure mode the
     * candidatos endpoint hit in production, just on the solicitud-creation
     * path instead. It is now treated exactly like a missing puntaje: fails
     * CLOSED via `Dictamen\Reglas\PuntajeDentroDelTecho`'s own
     * `entrante_puntaje_indeterminado` motivo (see that rule's class
     * docblock, "A MISSING PUNTAJE IS NEVER READ AS 'NO OBJECTION'"), logged
     * and visible rather than thrown, never silently approved.
     */
    public function test_entrante_puntaje_resolves_to_null_and_is_logged_when_the_stored_value_is_not_a_valid_puntaje(): void {
        $ctx = $this->assembleWithEntrante( [ 'puntaje' => '2,3' ] );

        $this->assertNull( $ctx->entrantePuntaje() );
        $this->assertTrue( $this->eventLog->has( 'metrics.puntaje_invalido' ) );
        $this->assertTrue( $this->eventLog->has( 'entrante.puntaje_no_encontrado' ) );
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function assembleWithEntrante( array $metrics ): \EntreRedes\Cambios\Dictamen\DictamenContext {
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->seedFecha( 5, self::SEASON_ID );
        $this->putSpMetrics( 888, $metrics );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, time() );

        return $this->assembler->assemble( $solicitud );
    }

    // -------------------------------------------------------------------------
    // entrantePlazasConCierreTruncado() excludes the CURRENT plaza
    // -------------------------------------------------------------------------

    public function test_entrante_plazas_con_cierre_truncado_excludes_the_current_plaza(): void {
        // Player 888 has a trunca closure on the SAME plaza the solicitud is
        // FOR (plazaA) — Reglas\EntranteNoBloqueado must not see it; that
        // plaza's own liberation is what Reglas\RegresoSoloConMinimoCumplido /
        // Reglas\PlazaConOcupacionVigente already read via ctx->ocupaciones().
        $this->seedFecha( 4, self::SEASON_ID, '2026-04-01' );
        $this->seedFecha( 7, self::SEASON_ID, '2026-05-01' );

        $plazaA = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->plazaRepository->succeedOcupacion( $plazaA, 888, 4, 'reemplazada', '2026-04-01 10:00:00' );
        $this->plazaRepository->succeedOcupacion( $plazaA, 999, 7, 'trunca', '2026-05-01 10:00:00' );

        $this->seedFecha( 8, self::SEASON_ID );
        $this->putSpMetrics( 888, [ 'puntaje' => '2,5' ] );

        // 999 is now vigent on plazaA — solicitud proposes 888 back into
        // plazaA itself.
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaA, 888, 8, time() );
        $ctx       = $this->assembler->assemble( $solicitud );

        $this->assertSame( [], $ctx->entrantePlazasConCierreTruncado() );
    }

    public function test_entrante_plazas_con_cierre_truncado_includes_a_genuinely_other_plaza(): void {
        $this->seedFecha( 4, self::SEASON_ID, '2026-04-01' );
        $this->seedFecha( 7, self::SEASON_ID, '2026-05-01' );

        $plazaA = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->plazaRepository->succeedOcupacion( $plazaA, 888, 4, 'reemplazada', '2026-04-01 10:00:00' );
        $this->plazaRepository->succeedOcupacion( $plazaA, 999, 7, 'trunca', '2026-05-01 10:00:00' );

        $plazaB = $this->plazaRepository->openPlaza( self::SEASON_ID, 200, 222, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->seedFecha( 8, self::SEASON_ID );
        $this->putSpMetrics( 888, [ 'puntaje' => '2,5' ] );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 200, $plazaB, 888, 8, time() );
        $ctx       = $this->assembler->assemble( $solicitud );

        $this->assertCount( 1, $ctx->entrantePlazasConCierreTruncado() );
        $this->assertSame( $plazaA, (int) $ctx->entrantePlazasConCierreTruncado()[0][0]['plaza_id'] );
    }

    // -------------------------------------------------------------------------
    // The sanity cap on the injected resolved-fechas counter
    // -------------------------------------------------------------------------

    public function test_count_resolved_fechas_since_fn_throws_when_the_count_exceeds_the_seasons_total(): void {
        $this->seedFecha( 10, self::SEASON_ID, '2026-01-03', 'jugada' );
        $this->seedFecha( 11, self::SEASON_ID, '2026-01-10', 'jugada' );
        // Only 2 resolved fechas exist in the season (plus fecha 1, seeded
        // 'programada' by setUp() — not resolved, so it does not count).

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );

        global $wpdb;
        $inflatingFechaRepository = new class( $wpdb, new InMemoryEventLog() ) extends FechaRepository {
            public function countResolvedFechasSince( int $seasonId, int $fechaId ): int {
                // Deliberately lies upward: the season only has 2 resolved
                // fechas (see the two seeded above), never 999.
                return 999;
            }
        };

        $assembler = new DictamenContextAssembler(
            $this->plazaRepository,
            $inflatingFechaRepository,
            $this->settings,
            $wpdb,
            $this->eventLog
        );

        $this->seedFecha( 5, self::SEASON_ID );
        $solicitud = SolicitudDeCambio::regreso( self::SEASON_ID, 100, $plazaId, 5, time() );
        $ctx       = $assembler->assemble( $solicitud );

        $this->expectException( FechaCountUnavailableException::class );

        ( $ctx->countResolvedFechasSinceFn() )( 1 );
    }

    public function test_count_resolved_fechas_since_fn_allows_a_count_within_the_seasons_total(): void {
        $this->seedFecha( 10, self::SEASON_ID, '2026-01-03', 'jugada' );
        $this->seedFecha( 11, self::SEASON_ID, '2026-01-10', 'jugada' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-02-01' );

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );

        $solicitud = SolicitudDeCambio::regreso( self::SEASON_ID, 100, $plazaId, 5, time() );
        $ctx       = $this->assembler->assemble( $solicitud );

        $this->assertSame( 2, ( $ctx->countResolvedFechasSinceFn() )( 1 ) );
    }

    // -------------------------------------------------------------------------
    // End-to-end: assemble() + DictamenEngineFactory — the four slices meet
    // -------------------------------------------------------------------------

    public function test_end_to_end_a_clean_sustitucion_procede(): void {
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );

        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $this->putSpMetrics( 888, [ 'puntaje' => '2,5' ] );

        $solicitud = SolicitudDeCambio::sustitucion(
            self::SEASON_ID,
            100,
            $plazaId,
            888,
            5,
            ( new \DateTimeImmutable( '2026-05-25 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp()
        );

        $ctx      = $this->assembler->assemble( $solicitud );
        $dictamen = DictamenEngineFactory::create()->evaluate( $ctx );

        $this->assertTrue( $dictamen->procede(), implode( '; ', array_map(
            static fn ( $m ) => $m->codigo(),
            $dictamen->motivos()
        ) ) );
    }

    public function test_end_to_end_a_sustitucion_over_the_techo_and_out_of_plazo_does_not_procede_with_both_motivos(): void {
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );

        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        // A puntaje well above the plaza's techo (3.0, effective techo 3.0).
        $this->putSpMetrics( 888, [ 'puntaje' => '5' ] );

        // Well after cierre_solicitudes (default offset: play_date - 2 days,
        // 23:59:59) — this instant is AFTER the fecha's play_date entirely.
        $solicitud = SolicitudDeCambio::sustitucion(
            self::SEASON_ID,
            100,
            $plazaId,
            888,
            5,
            ( new \DateTimeImmutable( '2026-06-02 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp()
        );

        $ctx      = $this->assembler->assemble( $solicitud );
        $dictamen = DictamenEngineFactory::create()->evaluate( $ctx );

        $this->assertFalse( $dictamen->procede() );

        $codigos = array_map( static fn ( $m ) => $m->codigo(), $dictamen->motivos() );
        $this->assertContains( 'puntaje_excede_techo', $codigos );
        $this->assertContains( 'fuera_de_plazo', $codigos );
    }

    // -------------------------------------------------------------------------
    // entranteEsPadre() / padresViablesParaLaPlaza() — prioridad de padres
    // -------------------------------------------------------------------------

    public function test_entrante_es_padre_is_true_when_caracter_says_so(): void {
        $ctx = $this->assembleWithEntrante( [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );

        $this->assertTrue( $ctx->entranteEsPadre() );
    }

    public function test_entrante_es_padre_is_false_when_caracter_is_absent(): void {
        $ctx = $this->assembleWithEntrante( [ 'puntaje' => '2,5' ] );

        $this->assertFalse( $ctx->entranteEsPadre() );
    }

    public function test_entrante_es_padre_is_false_for_a_regreso(): void {
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->seedFecha( 5, self::SEASON_ID );

        $solicitud = SolicitudDeCambio::regreso( self::SEASON_ID, 100, $plazaId, 5, time() );
        $ctx       = $this->assembler->assemble( $solicitud );

        $this->assertFalse( $ctx->entranteEsPadre() );
    }

    public function test_padres_viables_stays_zero_when_the_policy_is_off_even_with_a_viable_padre_in_the_roster(): void {
        // The policy row is absent -> Settings::prioridadPadresActiva()
        // falls back to its OFF default. A viable padre genuinely exists in
        // the season roster below, but the assembler must never spend the
        // CandidatosResolver query to find it while the policy is off.
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->seedFecha( 5, self::SEASON_ID );
        $this->putSpMetrics( 888, [ 'puntaje' => '2,5' ] ); // non-padre entrante

        $this->seedPlayerEnTemporada( 900 );
        $this->putSpMetrics( 900, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, time() );
        $ctx       = $this->assembler->assemble( $solicitud );

        $this->assertSame( 0, $ctx->padresViablesParaLaPlaza() );
    }

    public function test_padres_viables_counts_a_viable_padre_when_the_policy_is_on(): void {
        $this->putSetting( 'prioridad_padres_activa', '1' );

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->seedFecha( 5, self::SEASON_ID );
        $this->putSpMetrics( 888, [ 'puntaje' => '2,5' ] ); // non-padre entrante

        $this->seedPlayerEnTemporada( 900 );
        $this->putSpMetrics( 900, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, time() );
        $ctx       = $this->assembler->assemble( $solicitud );

        $this->assertSame( 1, $ctx->padresViablesParaLaPlaza() );
    }

    public function test_padres_viables_stays_zero_when_the_entrante_is_already_a_padre(): void {
        $this->putSetting( 'prioridad_padres_activa', '1' );

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->seedFecha( 5, self::SEASON_ID );
        $this->putSpMetrics( 888, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] ); // padre entrante

        $this->seedPlayerEnTemporada( 900 );
        $this->putSpMetrics( 900, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, time() );
        $ctx       = $this->assembler->assemble( $solicitud );

        $this->assertTrue( $ctx->entranteEsPadre() );
        $this->assertSame( 0, $ctx->padresViablesParaLaPlaza() );
    }

    /**
     * THE actual proof behind "NO QUERY WHEN THE POLICY IS OFF, OR WHEN IT
     * WOULD BE WASTED" (see class docblock): the three tests above only ever
     * assert the RESULTING count (0, 1, 0) — never that CandidatosResolver's
     * expensive query was actually left alone. A mock with expects(never())
     * / expects(once()) is what actually proves the assembler skips (or
     * pays) that cost — mirrors the same pattern PlazasControllerTest already
     * uses for `never()`.
     */
    public function test_contar_padres_viables_is_never_called_when_the_policy_is_off(): void {
        global $wpdb;

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->seedFecha( 5, self::SEASON_ID );
        $this->putSpMetrics( 888, [ 'puntaje' => '2,5' ] ); // non-padre entrante

        $candidatosResolver = $this->createMock( CandidatosResolver::class );
        $candidatosResolver->expects( $this->never() )->method( 'contarPadresViables' );

        $assembler = new DictamenContextAssembler(
            $this->plazaRepository,
            $this->fechaRepository,
            $this->settings,
            $wpdb,
            $this->eventLog,
            null,
            $candidatosResolver
        );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, time() );
        $assembler->assemble( $solicitud );
    }

    public function test_contar_padres_viables_is_never_called_when_the_entrante_is_already_a_padre(): void {
        global $wpdb;

        $this->putSetting( 'prioridad_padres_activa', '1' );

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->seedFecha( 5, self::SEASON_ID );
        $this->putSpMetrics( 888, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] ); // padre entrante

        $candidatosResolver = $this->createMock( CandidatosResolver::class );
        $candidatosResolver->expects( $this->never() )->method( 'contarPadresViables' );

        $assembler = new DictamenContextAssembler(
            $this->plazaRepository,
            $this->fechaRepository,
            $this->settings,
            $wpdb,
            $this->eventLog,
            null,
            $candidatosResolver
        );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, time() );
        $assembler->assemble( $solicitud );
    }

    public function test_contar_padres_viables_is_called_exactly_once_when_the_policy_is_on_and_the_entrante_is_not_a_padre(): void {
        global $wpdb;

        $this->putSetting( 'prioridad_padres_activa', '1' );

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->seedFecha( 5, self::SEASON_ID );
        $this->putSpMetrics( 888, [ 'puntaje' => '2,5' ] ); // non-padre entrante

        $candidatosResolver = $this->createMock( CandidatosResolver::class );
        $candidatosResolver->expects( $this->once() )->method( 'contarPadresViables' )->willReturn( 1 );

        $assembler = new DictamenContextAssembler(
            $this->plazaRepository,
            $this->fechaRepository,
            $this->settings,
            $wpdb,
            $this->eventLog,
            null,
            $candidatosResolver
        );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, time() );
        $ctx       = $assembler->assemble( $solicitud );

        $this->assertSame( 1, $ctx->padresViablesParaLaPlaza() );
    }

    /**
     * THE end-to-end agreement test: Plazas\CandidatosResolver, consulted
     * directly, and Reglas\PrioridadDePadresRespetada, consulted through the
     * full assemble() + DictamenEngineFactory pipeline, must reach the exact
     * same verdict for the exact same scenario — see CandidatosResolver's own
     * class docblock, "WHY THIS MUST BE THE ONLY IMPLEMENTATION".
     */
    public function test_the_resolver_and_the_rule_agree_on_the_same_scenario(): void {
        $this->putSetting( 'prioridad_padres_activa', '1' );

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->seedFecha( 5, self::SEASON_ID );
        $this->putSpMetrics( 888, [ 'puntaje' => '2,5' ] ); // non-padre entrante

        $this->seedPlayerEnTemporada( 900 );
        $this->putSpMetrics( 900, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, time() );
        $ctx       = $this->assembler->assemble( $solicitud );

        global $wpdb;
        $plaza          = $this->plazaRepository->findPlaza( $plazaId );
        $resolverDirecto = new CandidatosResolver( $wpdb, $this->plazaRepository, $this->eventLog );
        $conteoDirecto   = $resolverDirecto->contarPadresViables(
            $plaza,
            \EntreRedes\Cambios\Dictamen\BloqueoReemplazoPolicy::topeTresFechas(),
            $ctx->countResolvedFechasSinceFn()
        );

        $this->assertSame( $conteoDirecto, $ctx->padresViablesParaLaPlaza() );
        $this->assertGreaterThan( 0, $conteoDirecto );

        $dictamen = DictamenEngineFactory::create( null, true )->evaluate( $ctx );

        $this->assertFalse( $dictamen->procede() );
        $codigos = array_map( static fn ( $m ) => $m->codigo(), $dictamen->motivos() );
        $this->assertContains( 'prioridad_de_padres_no_respetada', $codigos );
    }

    /**
     * FIX 1's end-to-end proof: with the padre-priority policy ON and a
     * non-padre entrante, a failing candidate-pool read must NEVER produce a
     * silent approval. Before this fix, `CandidatosResolver::
     * playerIdsRegistradosEnTemporada()` degraded a failed
     * `$wpdb->get_results()` into `[]` via `$rows ?: []` — zero candidates
     * — so `contarPadresViables()` returned 0, `Reglas\
     * PrioridadDePadresRespetada::evaluate()` hit its
     * `$padresViables <= 0 -> return null` shortcut, and the non-padre
     * entrante was APPROVED purely because the system could not count. This
     * asserts assemble() now THROWS instead — the failure propagates, no
     * `DictamenContext` (and therefore no `Dictamen`, no approval) is ever
     * produced.
     */
    public function test_prioridad_de_padres_never_silently_approves_when_the_candidate_pool_read_fails(): void {
        global $wpdb;

        $this->putSetting( 'prioridad_padres_activa', '1' );

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->seedFecha( 5, self::SEASON_ID );
        $this->putSpMetrics( 888, [ 'puntaje' => '2,5' ] ); // non-padre entrante

        // A viable padre genuinely exists in the season roster — if the read
        // failure below silently became "zero candidates" (the pre-fix
        // bug), this scenario would wrongly approve 888.
        $this->seedPlayerEnTemporada( 900 );
        $this->putSpMetrics( 900, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );

        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $wpdb );

        $failingWpdb = new class( $pdo, $wpdb->prefix ) extends \wpdb {
            public function __construct( \PDO $pdo, string $prefix ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix = $prefix;
            }

            public function get_results( string $sql, string $output = OBJECT ): array {
                if ( str_contains( $sql, 'sp_season' ) ) {
                    $this->last_error = 'simulated get_results failure for test';
                    return [];
                }

                return parent::get_results( $sql, $output );
            }
        };

        $failingCandidatosResolver = new CandidatosResolver( $failingWpdb, $this->plazaRepository, new InMemoryEventLog() );

        $assembler = new DictamenContextAssembler(
            $this->plazaRepository,
            $this->fechaRepository,
            $this->settings,
            $wpdb,
            $this->eventLog,
            null,
            $failingCandidatosResolver
        );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, time() );

        try {
            $assembler->assemble( $solicitud );
            $this->fail( 'assemble() must propagate the candidate-pool read failure — never silently approve.' );
        } catch ( \RuntimeException $e ) {
            // expected: the failure surfaced, so no DictamenContext (and
            // therefore no approving Dictamen) was ever produced.
            $this->assertInstanceOf( \RuntimeException::class, $e );
        }
    }
}

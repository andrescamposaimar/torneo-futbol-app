<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Calendario;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\SeedTemporadaService;
use EntreRedes\Cambios\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for SeedTemporadaService against the in-memory SQLite
 * shim, via a REAL FechaRepository (not a mock) so idempotency and the
 * estado_origen guard are exercised end-to-end, exactly as production would.
 */
class SeedTemporadaServiceTest extends TestCase {

    /** Fixed clock: every fixture in this file predates it. */
    private const NOW = '2026-12-31 23:59:59';

    private const LIGA_TO_TORNEO = [
        371 => 'Clasificacion',
        372 => 'Clasificacion',
        373 => 'Apertura',
        374 => 'Apertura',
        375 => 'Apertura',
        377 => 'Clausura',
        378 => 'Clausura',
        379 => 'Clausura',
    ];

    private FechaRepository $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_fecha_partido" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );

        $this->repo = new FechaRepository( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_fecha_partido" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );
        $wpdb->query( "DELETE FROM {$p}cambios_settings" );
        InitialSchema::up();
    }

    /**
     * Builds one matchday of 15 partidos: 5 matches per zone across the 3
     * Apertura zonas (373/374/375), all kicking off the same Saturday.
     *
     * @return array<int, array{match_id:int, liga_id:int, zona:string, kickoff:string, tiene_resultado:bool}>
     */
    private function buildMatchday( string $playDate, array $ligaIds, int $matchIdStart ): array {
        $partidos = [];
        $matchId  = $matchIdStart;

        foreach ( $ligaIds as $ligaId ) {
            for ( $i = 0; $i < 5; $i++ ) {
                $partidos[] = [
                    'match_id'        => $matchId++,
                    'liga_id'         => $ligaId,
                    'zona'            => 'Zona ' . $ligaId,
                    'kickoff'         => $playDate . ' 1' . $i . ':00:00',
                    'tiene_resultado' => false,
                ];
            }
        }

        return $partidos;
    }

    private function service( callable $fetcherFn ): SeedTemporadaService {
        return new SeedTemporadaService( $this->repo, $fetcherFn, self::LIGA_TO_TORNEO );
    }

    // -------------------------------------------------------------------------
    // Grouping: 15 partidos across 3 zonas collapse into ONE fecha row
    // -------------------------------------------------------------------------

    public function test_one_matchday_of_15_partidos_across_3_zonas_becomes_one_fecha(): void {
        $partidos = $this->buildMatchday( '2026-05-30', [ 373, 374, 375 ], 1000 );

        $service = $this->service( fn() => $partidos );
        $service->seed( 359, self::NOW );

        global $wpdb;
        $p = $wpdb->prefix;

        $this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cambios_fecha" ) );
        $this->assertSame( 15, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cambios_fecha_partido" ) );

        $fecha = $this->repo->findByOrden( 359, 1 );
        $this->assertNotNull( $fecha );
        $this->assertSame( '373,374,375', $fecha['torneo_liga_ids'] );
        $this->assertSame( 'Apertura', $fecha['torneo_label'] );
        $this->assertSame( 1, (int) $fecha['numero_en_torneo'] );
    }

    // -------------------------------------------------------------------------
    // orden continuous across Apertura -> Clausura; numero_en_torneo resets
    // -------------------------------------------------------------------------

    public function test_orden_is_continuous_across_apertura_and_clausura_while_numero_en_torneo_resets(): void {
        $partidos = array_merge(
            $this->buildMatchday( '2026-05-30', [ 373, 374, 375 ], 1000 ), // Apertura fecha 1 -> orden 1
            $this->buildMatchday( '2026-06-06', [ 373, 374, 375 ], 2000 ), // Apertura fecha 2 -> orden 2
            $this->buildMatchday( '2026-08-01', [ 377, 378, 379 ], 3000 ), // Clausura fecha 1 -> orden 3
            $this->buildMatchday( '2026-08-08', [ 377, 378, 379 ], 4000 )  // Clausura fecha 2 -> orden 4
        );

        $service = $this->service( fn() => $partidos );
        $created = $service->seed( 359, self::NOW );

        $this->assertCount( 4, $created );

        $ordenByPlayDate = array_column( $created, 'orden', 'play_date' );
        $this->assertSame(
            [
                '2026-05-30' => 1,
                '2026-06-06' => 2,
                '2026-08-01' => 3,
                '2026-08-08' => 4,
            ],
            $ordenByPlayDate
        );

        $numeroByPlayDate = array_column( $created, 'numero_en_torneo', 'play_date' );
        $this->assertSame(
            [
                '2026-05-30' => 1, // Apertura fecha 1
                '2026-06-06' => 2, // Apertura fecha 2
                '2026-08-01' => 1, // Clausura fecha 1 -- RESET
                '2026-08-08' => 2, // Clausura fecha 2
            ],
            $numeroByPlayDate
        );

        $torneoByPlayDate = array_column( $created, 'torneo_label', 'play_date' );
        $this->assertSame(
            [
                '2026-05-30' => 'Apertura',
                '2026-06-06' => 'Apertura',
                '2026-08-01' => 'Clausura',
                '2026-08-08' => 'Clausura',
            ],
            $torneoByPlayDate
        );
    }

    public function test_days_are_ordered_chronologically_regardless_of_input_order(): void {
        // Feed the fetcher out of chronological order on purpose.
        $partidos = array_merge(
            $this->buildMatchday( '2026-06-06', [ 373, 374, 375 ], 2000 ),
            $this->buildMatchday( '2026-05-30', [ 373, 374, 375 ], 1000 )
        );

        $service = $this->service( fn() => $partidos );
        $created = $service->seed( 359, self::NOW );

        $this->assertSame(
            [ '2026-05-30', '2026-06-06' ],
            array_column( $created, 'play_date' )
        );
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function test_running_twice_does_not_duplicate_fechas_or_partidos(): void {
        $partidos = array_merge(
            $this->buildMatchday( '2026-05-30', [ 373, 374, 375 ], 1000 ),
            $this->buildMatchday( '2026-06-06', [ 373, 374, 375 ], 2000 )
        );

        $service = $this->service( fn() => $partidos );
        $service->seed( 359, self::NOW );
        $service->seed( 359, self::NOW );

        global $wpdb;
        $p = $wpdb->prefix;

        $this->assertSame( 2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cambios_fecha" ) );
        $this->assertSame( 30, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cambios_fecha_partido" ) );
    }

    public function test_reseed_does_not_clobber_a_manually_set_estado(): void {
        $partidos = $this->buildMatchday( '2026-05-30', [ 373, 374, 375 ], 1000 );

        $service = $this->service( fn() => $partidos );
        $service->seed( 359, self::NOW );

        $fecha = $this->repo->findByOrden( 359, 1 );
        $this->repo->setEstadoManual( (int) $fecha['id'], 'suspendida', 1, '2026-05-30 09:00:00' );

        // Re-run the seed with the same (still unresolved) partidos.
        $service->seed( 359, self::NOW );

        $refetched = $this->repo->findByOrden( 359, 1 );
        $this->assertSame( 'suspendida', $refetched['estado'] );
        $this->assertSame( 'manual', $refetched['estado_origen'] );
    }

    // -------------------------------------------------------------------------
    // Unresolvable liga_id
    // -------------------------------------------------------------------------

    public function test_throws_when_liga_id_is_not_in_the_injected_map(): void {
        $partidos = $this->buildMatchday( '2026-05-30', [ 999 ], 1000 );

        $service = $this->service( fn() => $partidos );

        $this->expectException( \InvalidArgumentException::class );
        $service->seed( 359, self::NOW );
    }

    // -------------------------------------------------------------------------
    // Postponement surfaces as 'status' => 'postergada', never a duplicate row
    // -------------------------------------------------------------------------

    public function test_reseeding_with_a_later_kickoff_reports_postergada_and_moves_the_fecha(): void {
        $original = $this->buildMatchday( '2026-05-30', [ 373, 374, 375 ], 1000 );

        $service = $this->service( fn() => $original );
        $first   = $service->seed( 359, self::NOW );
        $this->assertSame( 'creada', $first[0]['status'] );

        $postponed = array_map(
            static function ( array $partido ): array {
                $partido['kickoff'] = '2026-06-06' . substr( $partido['kickoff'], 10 );
                return $partido;
            },
            $original
        );

        $service = $this->service( fn() => $postponed );
        $second  = $service->seed( 359, self::NOW );

        $this->assertCount( 1, $second );
        $this->assertSame( $first[0]['fecha_id'], $second[0]['fecha_id'] );
        $this->assertSame( 'postergada', $second[0]['status'] );

        $row = $this->repo->findById( $second[0]['fecha_id'] );
        $this->assertSame( '2026-06-06', $row['play_date'] );
        $this->assertSame( '2026-05-30', $row['play_date_original'] );
        $this->assertSame( 1, (int) $row['veces_postergada'] );

        global $wpdb;
        $p = $wpdb->prefix;
        $this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cambios_fecha" ) );
    }

    // -------------------------------------------------------------------------
    // Clasificacion participates in the same continuous orden/counting scheme
    // -------------------------------------------------------------------------

    public function test_clasificacion_fecha_gets_orden_one_and_counts_towards_resueltas(): void {
        $partidos = $this->buildMatchday( '2026-03-07', [ 371, 372 ], 500 );

        $service = $this->service( fn() => $partidos );
        $created = $service->seed( 359, self::NOW );

        $this->assertSame( 'Clasificacion', $created[0]['torneo_label'] );
        $this->assertSame( 1, $created[0]['orden'] );

        $this->repo->setEstadoManual( $created[0]['fecha_id'], 'jugada', null, '2026-03-07 20:00:00' );

        $this->assertSame(
            1,
            $this->repo->countResolvedFechasSince( 359, $created[0]['fecha_id'] )
        );
    }

    // -------------------------------------------------------------------------
    // A chronologically-earlier fecha arriving late renumbers orden, but
    // NEVER changes any existing fecha_id
    // -------------------------------------------------------------------------

    public function test_a_chronologically_intermediate_fecha_loaded_late_renumbers_orden_without_changing_fecha_ids(): void {
        // Apertura is seeded first (the Clasificacion fecha isn't loaded yet).
        $apertura = $this->buildMatchday( '2026-05-30', [ 373, 374, 375 ], 1000 );
        $service  = $this->service( fn() => $apertura );
        $first    = $service->seed( 359, self::NOW );

        $this->assertSame( 1, $first[0]['orden'] );
        $aperturaFechaId = $first[0]['fecha_id'];

        // Now a Clasificacion fecha — chronologically BEFORE the Apertura
        // fecha already seeded — shows up, together with the same Apertura
        // partidos (the fetcher always returns everything loaded so far).
        $clasificacion = $this->buildMatchday( '2026-03-07', [ 371, 372 ], 500 );
        $service       = $this->service( fn() => array_merge( $clasificacion, $apertura ) );
        $second        = $service->seed( 359, self::NOW );

        $this->assertCount( 2, $second );

        $byPlayDate = [];
        foreach ( $second as $item ) {
            $byPlayDate[ $item['play_date'] ] = $item;
        }

        // orden was renumbered: Clasificacion (earlier day) is now orden 1,
        // Apertura is now orden 2.
        $this->assertSame( 1, $byPlayDate['2026-03-07']['orden'] );
        $this->assertSame( 2, $byPlayDate['2026-05-30']['orden'] );

        // The Apertura fecha's fecha_id must be UNCHANGED despite its orden
        // moving from 1 to 2.
        $this->assertSame( $aperturaFechaId, $byPlayDate['2026-05-30']['fecha_id'] );
        $this->assertSame( 'sin_cambios', $byPlayDate['2026-05-30']['status'] );
        $this->assertSame( 'creada', $byPlayDate['2026-03-07']['status'] );
    }

    // -------------------------------------------------------------------------
    // numero_en_torneo resets at every torneo_label change, across all 3
    // -------------------------------------------------------------------------

    public function test_numero_en_torneo_resets_across_clasificacion_apertura_clausura(): void {
        $partidos = array_merge(
            $this->buildMatchday( '2026-03-07', [ 371, 372 ], 100 ),           // Clasificacion 1 -> orden 1
            $this->buildMatchday( '2026-03-14', [ 371, 372 ], 200 ),           // Clasificacion 2 -> orden 2
            $this->buildMatchday( '2026-05-30', [ 373, 374, 375 ], 1000 ),     // Apertura 1      -> orden 3
            $this->buildMatchday( '2026-06-06', [ 373, 374, 375 ], 2000 ),     // Apertura 2      -> orden 4
            $this->buildMatchday( '2026-08-01', [ 377, 378, 379 ], 3000 ),     // Clausura 1      -> orden 5
            $this->buildMatchday( '2026-08-08', [ 377, 378, 379 ], 4000 )      // Clausura 2      -> orden 6
        );

        $service = $this->service( fn() => $partidos );
        $created = $service->seed( 359, self::NOW );

        $numeroByPlayDate = array_column( $created, 'numero_en_torneo', 'play_date' );
        $this->assertSame(
            [
                '2026-03-07' => 1,
                '2026-03-14' => 2,
                '2026-05-30' => 1,
                '2026-06-06' => 2,
                '2026-08-01' => 1,
                '2026-08-08' => 2,
            ],
            $numeroByPlayDate
        );

        $ordenByPlayDate = array_column( $created, 'orden', 'play_date' );
        $this->assertSame(
            [
                '2026-03-07' => 1,
                '2026-03-14' => 2,
                '2026-05-30' => 3,
                '2026-06-06' => 4,
                '2026-08-01' => 5,
                '2026-08-08' => 6,
            ],
            $ordenByPlayDate
        );
    }

    // -------------------------------------------------------------------------
    // The WIRE: seed() must actually derive estado, not leave the default
    // -------------------------------------------------------------------------

    /**
     * Regression test for a real integration bug. EstadoDeriver, FechaRepository
     * and SeedTemporadaService were each fully tested in isolation and all green,
     * but NOTHING called EstadoDeriver: seed() never set `estado`, so upsertFecha()
     * fell back to the 'programada' default for every fecha, forever.
     *
     * A dry run against the live 2026 fixture exposed it — all 23 fechas came back
     * 'programada', including the 18 already played with 270 results loaded. The
     * consequence was silent and total: countResolvedFechasSince() would
     * always return 0, no ocupacion would ever reach the 3-fecha minimum, and no
     * titular could ever return to their plaza.
     */
    public function test_seed_derives_jugada_when_every_partido_has_a_result(): void {
        $partidos = $this->buildMatchday( '2026-05-30', [ 373, 374, 375 ], 5000 );
        foreach ( $partidos as &$p ) {
            $p['tiene_resultado'] = true;
        }
        unset( $p );

        $this->service( fn() => $partidos )->seed( 359, self::NOW );

        global $wpdb;
        $estado = $wpdb->get_var(
            "SELECT estado FROM {$wpdb->prefix}cambios_fecha WHERE play_date = '2026-05-30'"
        );

        $this->assertSame( 'jugada', $estado );
    }

    public function test_seed_leaves_programada_when_results_are_incomplete(): void {
        $partidos = $this->buildMatchday( '2026-05-30', [ 373, 374, 375 ], 6000 );
        foreach ( $partidos as $i => $unused ) {
            $partidos[ $i ]['tiene_resultado'] = ( $i > 0 ); // one still missing
        }

        $this->service( fn() => $partidos )->seed( 359, self::NOW );

        global $wpdb;
        $estado = $wpdb->get_var(
            "SELECT estado FROM {$wpdb->prefix}cambios_fecha WHERE play_date = '2026-05-30'"
        );

        $this->assertSame( 'programada', $estado );
    }

    /**
     * THE Saturday-night race (see PartidosApiClient::fetchAll()'s docblock
     * for the full scenario): a partido's result gets loaded into SportsPress
     * while a fetch is mid-flight, so the same match_id comes back twice in
     * one `$fetcherFn` result — once resolved, once not. If the last one in
     * the array wins (a plain overwrite in FechaRepository::syncPartidos()),
     * the fecha is left with `tiene_resultado = 0` for an actually-played
     * match and never derives to 'jugada' — the same bug commit 1ed1e6a7
     * closed, entering through duplicate match_ids instead of a stale re-seed.
     */
    public function test_duplicate_match_id_with_conflicting_tiene_resultado_does_not_downgrade_the_fecha(): void {
        $partidos = $this->buildMatchday( '2026-05-30', [ 373, 374, 375 ], 8000 );
        foreach ( $partidos as &$p ) {
            $p['tiene_resultado'] = true;
        }
        unset( $p );

        // Duplicate the last partido with tiene_resultado = false, appended
        // LAST — simulating a stale /partidos-programados copy that hasn't
        // caught up with the result yet.
        $duplicate                    = $partidos[ count( $partidos ) - 1 ];
        $duplicate['tiene_resultado'] = false;
        $partidos[]                   = $duplicate;

        $this->service( fn() => $partidos )->seed( 359, self::NOW );

        global $wpdb;
        $p     = $wpdb->prefix;
        $fecha = $wpdb->get_row(
            "SELECT id, estado FROM {$p}cambios_fecha WHERE play_date = '2026-05-30'",
            ARRAY_A
        );

        $this->assertSame( 'jugada', $fecha['estado'], 'A stale duplicate must never downgrade an already-resolved fecha.' );

        $partidoCount = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cambios_fecha_partido WHERE fecha_id = %d", (int) $fecha['id'] )
        );
        $this->assertSame( 15, $partidoCount, 'The duplicate match_id must collapse into one row, not sixteen.' );
    }

    /**
     * The derived estado must never win over a human decision, even though
     * seed() now computes one on every run.
     */
    public function test_derived_estado_never_overwrites_a_manual_one(): void {
        $partidos = $this->buildMatchday( '2026-05-30', [ 373, 374, 375 ], 7000 );
        $service  = $this->service( fn() => $partidos );
        $service->seed( 359, self::NOW );

        global $wpdb;
        $fechaId = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}cambios_fecha WHERE play_date = '2026-05-30'"
        );
        $this->repo->setEstadoManual( $fechaId, 'dirimida', 7, self::NOW );

        // Results arrive afterwards; a naive reseed would derive 'jugada'.
        foreach ( $partidos as &$p ) {
            $p['tiene_resultado'] = true;
        }
        unset( $p );
        $this->service( fn() => $partidos )->seed( 359, self::NOW );

        $row = $this->repo->findById( $fechaId );
        $this->assertSame( 'dirimida', (string) $row['estado'] );
        $this->assertSame( 'manual', (string) $row['estado_origen'] );
    }

}

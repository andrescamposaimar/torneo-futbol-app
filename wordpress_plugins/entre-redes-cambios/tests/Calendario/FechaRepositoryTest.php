<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Calendario;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for FechaRepository against the in-memory SQLite shim.
 *
 * NOTE — SQLite shim gap (see InitialSchema's class docblock): the dbDelta
 * shim drops UNIQUE KEY lines, so uq_season_orden / uq_fecha_match / uq_match
 * are NOT enforced by the test DB. FechaRepository implements
 * SELECT-then-insert guards in code, and these tests verify idempotency by
 * asserting ROW COUNTS rather than relying on a DB constraint.
 */
class FechaRepositoryTest extends TestCase {

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
        InitialSchema::up(); // restore seeds
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array{season_id:int, orden:int, torneo_liga_ids:string, torneo_label:string, numero_en_torneo:int, play_date:string} */
    private function sampleFecha( int $seasonId = 359, int $orden = 1, string $playDate = '2026-05-30' ): array {
        return [
            'season_id'        => $seasonId,
            'orden'            => $orden,
            'torneo_liga_ids'  => '373,374,375',
            'torneo_label'     => 'Apertura',
            'numero_en_torneo' => $orden,
            'play_date'        => $playDate,
        ];
    }

    /** @return array<int, array{match_id:int, liga_id:int, zona:string, kickoff:string, tiene_resultado:bool}> */
    private function samplePartidos(): array {
        return [
            [ 'match_id' => 100, 'liga_id' => 373, 'zona' => 'Zona A', 'kickoff' => '2026-05-30 13:45:00', 'tiene_resultado' => false ],
            [ 'match_id' => 101, 'liga_id' => 374, 'zona' => 'Zona B', 'kickoff' => '2026-05-30 15:10:00', 'tiene_resultado' => false ],
        ];
    }

    /**
     * Builds one matchday of 15 partidos across the 3 Apertura zonas
     * (373/374/375), 5 matches per zone, all kicking off on $playDate.
     *
     * @return array<int, array{match_id:int, liga_id:int, zona:string, kickoff:string, tiene_resultado:bool}>
     */
    private function fifteenPartidos( string $playDate, int $matchIdStart ): array {
        $partidos = [];
        $matchId  = $matchIdStart;

        foreach ( [ 373, 374, 375 ] as $ligaId ) {
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

    private function countFechas(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_fecha" );
    }

    private function countPartidos(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_fecha_partido" );
    }

    private function fetchFecha( int $fechaId ): array {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cambios_fecha WHERE id = %d", $fechaId ),
            ARRAY_A
        );
    }

    // -------------------------------------------------------------------------
    // upsertFecha — creates
    // -------------------------------------------------------------------------

    public function test_upsert_creates_fecha_and_partido_rows(): void {
        $fechaId = $this->repo->upsertFecha( $this->sampleFecha(), $this->samplePartidos() );

        $this->assertGreaterThan( 0, $fechaId );
        $this->assertSame( 1, $this->countFechas() );
        $this->assertSame( 2, $this->countPartidos() );
    }

    public function test_new_fecha_defaults_to_derivado_and_programada(): void {
        $fechaId = $this->repo->upsertFecha( $this->sampleFecha(), $this->samplePartidos() );

        $row = $this->fetchFecha( $fechaId );

        $this->assertSame( 'programada', $row['estado'] );
        $this->assertSame( 'derivado', $row['estado_origen'] );
    }

    // -------------------------------------------------------------------------
    // upsertFecha — idempotency
    // -------------------------------------------------------------------------

    public function test_second_upsert_same_season_and_play_date_reuses_the_row(): void {
        $firstId  = $this->repo->upsertFecha( $this->sampleFecha(), $this->samplePartidos() );
        $secondId = $this->repo->upsertFecha( $this->sampleFecha(), $this->samplePartidos() );

        $this->assertSame( $firstId, $secondId );
        $this->assertSame( 1, $this->countFechas() );
    }

    public function test_second_upsert_does_not_duplicate_partido_rows(): void {
        $this->repo->upsertFecha( $this->sampleFecha(), $this->samplePartidos() );
        $this->repo->upsertFecha( $this->sampleFecha(), $this->samplePartidos() );

        $this->assertSame( 2, $this->countPartidos() );
    }

    public function test_second_upsert_updates_tiene_resultado_on_existing_partido(): void {
        $this->repo->upsertFecha( $this->sampleFecha(), $this->samplePartidos() );

        $updated = $this->samplePartidos();
        $updated[0]['tiene_resultado'] = true;

        $this->repo->upsertFecha( $this->sampleFecha(), $updated );

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT tiene_resultado FROM {$wpdb->prefix}cambios_fecha_partido WHERE match_id = %d",
                100
            ),
            ARRAY_A
        );
        $this->assertSame( 1, (int) $row['tiene_resultado'] );
        $this->assertSame( 2, $this->countPartidos(), 'Row count must stay at 2, not grow.' );
    }

    // -------------------------------------------------------------------------
    // upsertFecha — identity is the match_id SET, a postponement MOVES the row
    // -------------------------------------------------------------------------

    /**
     * THE central test for this fix: a fecha with 15 partidos is seeded,
     * then the exact same 15 match_ids come back a week later with a new
     * kickoff (the committee edited every sp_event's date in WordPress —
     * nothing marks the suspension anywhere else). The fecha must MOVE, not
     * duplicate.
     */
    public function test_same_match_ids_with_a_later_kickoff_moves_the_fecha_instead_of_duplicating(): void {
        $partidos = $this->fifteenPartidos( '2026-10-03', 5000 );

        $firstId = $this->repo->upsertFecha(
            $this->sampleFecha( 359, 1, '2026-10-03' ),
            $partidos
        );

        $postponed = array_map(
            static function ( array $partido ): array {
                $partido['kickoff'] = '2026-10-10' . substr( $partido['kickoff'], 10 );
                return $partido;
            },
            $partidos
        );

        $secondId = $this->repo->upsertFecha(
            $this->sampleFecha( 359, 1, '2026-10-10' ),
            $postponed
        );

        $this->assertSame( $firstId, $secondId, 'A postponement must move the existing fecha, not create a new one.' );
        $this->assertSame( 1, $this->countFechas(), 'No second row should exist after the postponement.' );
        $this->assertSame( 15, $this->countPartidos(), 'Partido rows must be updated in place, not duplicated.' );

        $row = $this->fetchFecha( $firstId );
        $this->assertSame( '2026-10-10', $row['play_date'] );
        $this->assertSame( '2026-10-03', $row['play_date_original'], 'play_date_original must never change.' );
        $this->assertSame( 1, (int) $row['veces_postergada'] );
    }

    public function test_manual_estado_survives_a_postponement(): void {
        $partidos = $this->fifteenPartidos( '2026-10-03', 5100 );
        $fechaId  = $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-10-03' ), $partidos );

        $this->repo->setEstadoManual( $fechaId, 'suspendida', 7, '2026-10-03 09:00:00' );

        $postponed = array_map(
            static function ( array $partido ): array {
                $partido['kickoff'] = '2026-10-10' . substr( $partido['kickoff'], 10 );
                return $partido;
            },
            $partidos
        );
        $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-10-10' ), $postponed );

        $row = $this->fetchFecha( $fechaId );
        $this->assertSame( 'suspendida', $row['estado'], 'A manually-set estado must survive a postponement.' );
        $this->assertSame( 'manual', $row['estado_origen'] );
        $this->assertSame( 1, (int) $row['veces_postergada'], 'The postponement must still be recorded.' );
    }

    public function test_upsert_throws_when_incoming_match_ids_belong_to_two_distinct_fechas(): void {
        $fechaA = $this->repo->upsertFecha(
            $this->sampleFecha( 359, 1, '2026-10-03' ),
            [ [ 'match_id' => 200, 'liga_id' => 373, 'zona' => 'Zona A', 'kickoff' => '2026-10-03 13:00:00', 'tiene_resultado' => false ] ]
        );
        $fechaB = $this->repo->upsertFecha(
            $this->sampleFecha( 359, 2, '2026-10-10' ),
            [ [ 'match_id' => 201, 'liga_id' => 373, 'zona' => 'Zona A', 'kickoff' => '2026-10-10 13:00:00', 'tiene_resultado' => false ] ]
        );
        $this->assertNotSame( $fechaA, $fechaB );

        $this->expectException( \RuntimeException::class );

        // One match_id from each existing fecha — this collision cannot be
        // resolved automatically (see FechaRepository's class docblock).
        $this->repo->upsertFecha(
            $this->sampleFecha( 359, 3, '2026-10-17' ),
            [
                [ 'match_id' => 200, 'liga_id' => 373, 'zona' => 'Zona A', 'kickoff' => '2026-10-17 13:00:00', 'tiene_resultado' => false ],
                [ 'match_id' => 201, 'liga_id' => 373, 'zona' => 'Zona A', 'kickoff' => '2026-10-17 15:00:00', 'tiene_resultado' => false ],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // upsertFecha — THE most important test: manual estado survives a re-seed
    // -------------------------------------------------------------------------

    public function test_manual_estado_survives_a_reseed(): void {
        $fechaId = $this->repo->upsertFecha( $this->sampleFecha(), $this->samplePartidos() );

        $ok = $this->repo->setEstadoManual( $fechaId, 'suspendida', 7, '2026-05-30 09:00:00' );
        $this->assertTrue( $ok );

        // A re-seed pass comes through later (e.g. the nightly cron) with the
        // fecha's derived estado now claiming 'jugada' — it must NOT win.
        $reseeded = $this->sampleFecha();
        $reseeded['estado'] = 'jugada';
        $secondId = $this->repo->upsertFecha( $reseeded, $this->samplePartidos() );

        $this->assertSame( $fechaId, $secondId );

        $row = $this->fetchFecha( $fechaId );
        $this->assertSame( 'suspendida', $row['estado'], 'A manually-set estado must survive a reseed.' );
        $this->assertSame( 'manual', $row['estado_origen'] );
    }

    public function test_reseed_of_a_derivado_fecha_does_update_estado(): void {
        $fechaId = $this->repo->upsertFecha( $this->sampleFecha(), $this->samplePartidos() );

        $reseeded = $this->sampleFecha();
        $reseeded['estado'] = 'jugada';
        $this->repo->upsertFecha( $reseeded, $this->samplePartidos() );

        $row = $this->fetchFecha( $fechaId );
        $this->assertSame( 'jugada', $row['estado'] );
        $this->assertSame( 'derivado', $row['estado_origen'] );
    }

    public function test_set_estado_manual_records_actor_and_timestamp(): void {
        $fechaId = $this->repo->upsertFecha( $this->sampleFecha(), $this->samplePartidos() );

        $this->repo->setEstadoManual( $fechaId, 'dirimida', 42, '2026-06-01 10:30:00' );

        $row = $this->fetchFecha( $fechaId );
        $this->assertSame( 'dirimida', $row['estado'] );
        $this->assertSame( 'manual', $row['estado_origen'] );
        $this->assertSame( '2026-06-01 10:30:00', $row['estado_actualizado_at'] );
        $this->assertSame( 42, (int) $row['estado_actualizado_por'] );
    }

    // -------------------------------------------------------------------------
    // findByOrden / listBySeason
    // -------------------------------------------------------------------------

    public function test_find_by_orden_returns_null_when_not_found(): void {
        $this->assertNull( $this->repo->findByOrden( 359, 99 ) );
    }

    public function test_find_by_orden_returns_the_row(): void {
        // The `orden` passed into upsertFecha() is ignored on purpose: the
        // repository assigns a provisional value and recalcularOrden() writes
        // the real one. So look the row up by the orden it actually got.
        $fechaId = $this->repo->upsertFecha( $this->sampleFecha( 359, 3 ), $this->samplePartidos() );
        $this->repo->recalcularOrden( 359 );

        $row = $this->repo->findByOrden( 359, 1 );

        $this->assertNotNull( $row );
        $this->assertSame( $fechaId, (int) $row['id'] );
    }

    public function test_list_by_season_orders_by_orden_ascending(): void {
        $this->repo->upsertFecha( $this->sampleFecha( 359, 2, '2026-06-06' ), [] );
        $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), [] );
        $this->repo->upsertFecha( $this->sampleFecha( 359, 3, '2026-06-13' ), [] );

        $this->repo->recalcularOrden( 359 );

        $list = $this->repo->listBySeason( 359 );

        $this->assertCount( 3, $list );
        $this->assertSame( [ 1, 2, 3 ], array_map( static fn( $r ) => (int) $r['orden'], $list ) );
        // `orden` must follow chronology, not insertion order: these three
        // were inserted 06-06, 05-30, 06-13.
        $this->assertSame(
            [ '2026-05-30', '2026-06-06', '2026-06-13' ],
            array_map( static fn( $r ) => substr( (string) $r['play_date'], 0, 10 ), $list )
        );
    }

    public function test_list_by_season_is_scoped_to_the_season(): void {
        $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), [] );
        $this->repo->upsertFecha( $this->sampleFecha( 999, 1, '2026-05-30' ), [] );

        $this->assertCount( 1, $this->repo->listBySeason( 359 ) );
    }

    // -------------------------------------------------------------------------
    // countFechasResueltasDesdeFecha
    // -------------------------------------------------------------------------

    public function test_count_resueltas_counts_jugada_and_dirimida_only(): void {
        $idA = $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), [] );
        $idB = $this->repo->upsertFecha( $this->sampleFecha( 359, 2, '2026-06-06' ), [] );
        $idC = $this->repo->upsertFecha( $this->sampleFecha( 359, 3, '2026-06-13' ), [] );
        $idD = $this->repo->upsertFecha( $this->sampleFecha( 359, 4, '2026-06-20' ), [] );

        $this->repo->setEstadoManual( $idA, 'jugada', null, '2026-05-30 20:00:00' );
        $this->repo->setEstadoManual( $idB, 'dirimida', null, '2026-06-06 20:00:00' );
        $this->repo->setEstadoManual( $idC, 'suspendida', null, '2026-06-13 20:00:00' );
        // $idD stays 'programada'.

        $this->assertSame( 2, $this->repo->countFechasResueltasDesdeFecha( 359, $idA ) );
    }

    public function test_count_resueltas_respects_the_floor_fechas_orden(): void {
        $idA = $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), [] );
        $idB = $this->repo->upsertFecha( $this->sampleFecha( 359, 2, '2026-06-06' ), [] );

        $this->repo->setEstadoManual( $idA, 'jugada', null, '2026-05-30 20:00:00' );
        $this->repo->setEstadoManual( $idB, 'jugada', null, '2026-06-06 20:00:00' );

        // Floor excludes idA's orden (1).
        $this->assertSame( 1, $this->repo->countFechasResueltasDesdeFecha( 359, $idB ) );
    }

    public function test_count_resueltas_throws_when_fecha_id_does_not_belong_to_the_season(): void {
        $idA = $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), [] );

        $this->expectException( \InvalidArgumentException::class );
        $this->repo->countFechasResueltasDesdeFecha( 999, $idA );
    }

    /**
     * The reason countFechasResueltasDesdeFecha() resolves `orden` fresh on
     * every call instead of accepting one: a postponement in the middle of
     * the counted window reshuffles `orden` values via recalcularOrden(), and
     * the count must still be correct afterwards.
     */
    public function test_count_resueltas_stays_correct_across_a_postponement_mid_window(): void {
        $idA = $this->repo->upsertFecha(
            $this->sampleFecha( 359, 1, '2026-05-30' ),
            [ [ 'match_id' => 300, 'liga_id' => 373, 'zona' => 'Zona A', 'kickoff' => '2026-05-30 13:00:00', 'tiene_resultado' => false ] ]
        );
        $idB = $this->repo->upsertFecha(
            $this->sampleFecha( 359, 2, '2026-06-06' ),
            [ [ 'match_id' => 301, 'liga_id' => 373, 'zona' => 'Zona A', 'kickoff' => '2026-06-06 13:00:00', 'tiene_resultado' => false ] ]
        );
        $idC = $this->repo->upsertFecha(
            $this->sampleFecha( 359, 3, '2026-06-13' ),
            [ [ 'match_id' => 302, 'liga_id' => 373, 'zona' => 'Zona A', 'kickoff' => '2026-06-13 13:00:00', 'tiene_resultado' => false ] ]
        );

        $this->repo->setEstadoManual( $idA, 'jugada', null, '2026-05-30 20:00:00' );
        $this->repo->setEstadoManual( $idC, 'jugada', null, '2026-06-13 20:00:00' );
        // $idB stays 'programada' for now.

        // fechaB is suspended and postponed past fechaC's original day.
        $this->repo->upsertFecha(
            $this->sampleFecha( 359, 2, '2026-06-20' ),
            [ [ 'match_id' => 301, 'liga_id' => 373, 'zona' => 'Zona A', 'kickoff' => '2026-06-20 13:00:00', 'tiene_resultado' => false ] ]
        );
        $this->repo->recalcularOrden( 359 );

        // fecha_id's are stable, so counting "resolved fechas from idA
        // onwards" must still see both idA and idC as resolved, regardless
        // of how `orden` was reshuffled by the postponement.
        $this->assertSame( 2, $this->repo->countFechasResueltasDesdeFecha( 359, $idA ) );
    }

    // -------------------------------------------------------------------------
    // orden uniqueness under reordering
    // -------------------------------------------------------------------------

    /**
     * UNIQUE(season_id, orden) exists in MySQL but is STRIPPED by the SQLite
     * shim (tests/wp-shim.php removes every UNIQUE KEY), so no test here can
     * fail on the constraint itself. This asserts the property the constraint
     * protects instead: after a reorder that shifts rows into slots their
     * neighbours still hold, every `orden` in the season is distinct — which
     * is what recalcularOrden()'s two-pass park-then-write is for.
     */
    public function test_recalcular_orden_never_leaves_duplicate_orden_values(): void {
        $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), [] );
        $this->repo->upsertFecha( $this->sampleFecha( 359, 2, '2026-06-06' ), [] );
        $this->repo->upsertFecha( $this->sampleFecha( 359, 3, '2026-06-13' ), [] );
        $this->repo->recalcularOrden( 359 );

        // A chronologically earlier fecha arrives late — every existing row
        // has to shift up by one.
        $this->repo->upsertFecha( $this->sampleFecha( 359, 0, '2026-05-23' ), [] );
        $this->repo->recalcularOrden( 359 );

        $ordenes = array_map(
            static fn( $r ) => (int) $r['orden'],
            $this->repo->listBySeason( 359 )
        );

        $this->assertSame( [ 1, 2, 3, 4 ], $ordenes );
        $this->assertSame( count( $ordenes ), count( array_unique( $ordenes ) ) );
    }

    public function test_recalcular_orden_is_idempotent(): void {
        $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), [] );
        $this->repo->upsertFecha( $this->sampleFecha( 359, 2, '2026-06-06' ), [] );
        $this->repo->recalcularOrden( 359 );

        $this->assertSame( 0, $this->repo->recalcularOrden( 359 ), 'second run must move nothing' );
    }

}

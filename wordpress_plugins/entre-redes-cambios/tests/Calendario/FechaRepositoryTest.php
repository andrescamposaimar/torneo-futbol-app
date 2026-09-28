<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Calendario;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
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

        $this->repo = new FechaRepository( $wpdb, new InMemoryEventLog() );
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

    public function test_set_estado_manual_rejects_an_invalid_estado(): void {
        $fechaId = $this->repo->upsertFecha( $this->sampleFecha(), $this->samplePartidos() );

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/programada, jugada, dirimida, suspendida/' );

        $this->repo->setEstadoManual( $fechaId, 'sospendida', 7, '2026-06-01 10:30:00' );
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
        // repository assigns a provisional value and recalculateOrden() writes
        // the real one. So look the row up by the orden it actually got.
        $fechaId = $this->repo->upsertFecha( $this->sampleFecha( 359, 3 ), $this->samplePartidos() );
        $this->repo->recalculateOrden( 359 );

        $row = $this->repo->findByOrden( 359, 1 );

        $this->assertNotNull( $row );
        $this->assertSame( $fechaId, (int) $row['id'] );
    }

    public function test_list_by_season_orders_by_orden_ascending(): void {
        $this->repo->upsertFecha( $this->sampleFecha( 359, 2, '2026-06-06' ), [] );
        $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), [] );
        $this->repo->upsertFecha( $this->sampleFecha( 359, 3, '2026-06-13' ), [] );

        $this->repo->recalculateOrden( 359 );

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
    // countResolvedFechasSince
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

        $this->assertSame( 2, $this->repo->countResolvedFechasSince( 359, $idA ) );
    }

    public function test_count_resueltas_respects_the_floor_fechas_orden(): void {
        $idA = $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), [] );
        $idB = $this->repo->upsertFecha( $this->sampleFecha( 359, 2, '2026-06-06' ), [] );

        $this->repo->setEstadoManual( $idA, 'jugada', null, '2026-05-30 20:00:00' );
        $this->repo->setEstadoManual( $idB, 'jugada', null, '2026-06-06 20:00:00' );

        // Floor excludes idA's orden (1).
        $this->assertSame( 1, $this->repo->countResolvedFechasSince( 359, $idB ) );
    }

    public function test_count_resueltas_throws_when_fecha_id_does_not_belong_to_the_season(): void {
        $idA = $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), [] );

        $this->expectException( \InvalidArgumentException::class );
        $this->repo->countResolvedFechasSince( 999, $idA );
    }

    /**
     * The reason countResolvedFechasSince() resolves `orden` fresh on
     * every call instead of accepting one: a postponement in the middle of
     * the counted window reshuffles `orden` values via recalculateOrden(), and
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
        $this->repo->recalculateOrden( 359 );

        // fecha_id's are stable, so counting "resolved fechas from idA
        // onwards" must still see both idA and idC as resolved, regardless
        // of how `orden` was reshuffled by the postponement.
        $this->assertSame( 2, $this->repo->countResolvedFechasSince( 359, $idA ) );
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
     * is what recalculateOrden()'s two-pass park-then-write is for.
     */
    public function test_recalcular_orden_never_leaves_duplicate_orden_values(): void {
        $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), [] );
        $this->repo->upsertFecha( $this->sampleFecha( 359, 2, '2026-06-06' ), [] );
        $this->repo->upsertFecha( $this->sampleFecha( 359, 3, '2026-06-13' ), [] );
        $this->repo->recalculateOrden( 359 );

        // A chronologically earlier fecha arrives late — every existing row
        // has to shift up by one.
        $this->repo->upsertFecha( $this->sampleFecha( 359, 0, '2026-05-23' ), [] );
        $this->repo->recalculateOrden( 359 );

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
        $this->repo->recalculateOrden( 359 );

        $this->assertSame( 0, $this->repo->recalculateOrden( 359 ), 'second run must move nothing' );
    }

    // -------------------------------------------------------------------------
    // Read-failure audit — every read in this class must fail loud, never
    // silently read a wpdb-level failure as "no rows" / "not found". See
    // class docblock, "READ FAILURES MUST NEVER READ AS 'NO ROWS' / 'NOT
    // FOUND'".
    // -------------------------------------------------------------------------

    /**
     * A `\wpdb` subclass whose get_results() sets $wpdb->last_error and
     * returns [] whenever the SQL contains $mustContain — same double as
     * `Plazas\PlazaRepositoryTest::wpdbThatFailsGetResults()`.
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

    /**
     * A `\wpdb` subclass whose get_row() sets $wpdb->last_error and returns
     * null whenever the SQL contains $mustContain — the get_row() analogue of
     * wpdbThatFailsGetResults() above.
     */
    private function wpdbThatFailsGetRow( \wpdb $real, string $mustContain ): \wpdb {
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

            public function get_row( string $sql, string $output = OBJECT ): ?array {
                if ( str_contains( $sql, $this->mustContain ) ) {
                    $this->last_error = 'simulated get_row failure for test';
                    return null;
                }

                return parent::get_row( $sql, $output );
            }
        };
    }

    /**
     * A `\wpdb` subclass whose get_var() sets $wpdb->last_error and returns
     * null whenever the SQL contains $mustContain — the get_var() analogue,
     * same double as `Plazas\JugadorMetricasReaderTest::wpdbThatFailsGetVar()`.
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

    // --- listBySeason (CRITICAL — the sole source for Rest\FechaController) --

    public function test_list_by_season_throws_when_the_query_fails(): void {
        global $wpdb;

        $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), [] );

        $failingWpdb = $this->wpdbThatFailsGetResults( $wpdb, 'ORDER BY orden ASC' );
        $failingRepo = new FechaRepository( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        $failingRepo->listBySeason( 359 );
    }

    public function test_list_by_season_records_a_lectura_fallida_event_before_throwing(): void {
        global $wpdb;

        $failingWpdb     = $this->wpdbThatFailsGetResults( $wpdb, 'ORDER BY orden ASC' );
        $failingEventLog = new InMemoryEventLog();
        $failingRepo     = new FechaRepository( $failingWpdb, $failingEventLog );

        try {
            $failingRepo->listBySeason( 359 );
            $this->fail( 'Expected RuntimeException.' );
        } catch ( \RuntimeException $e ) {
            // expected
        }

        $this->assertTrue( $failingEventLog->has( 'lectura.fallida' ) );
        $this->assertSame( 'listBySeason', $failingEventLog->last()['contexto']['operacion'] );
    }

    // --- findFechaIdByMatchIds ------------------------------------------------

    public function test_find_fecha_id_by_match_ids_throws_when_the_query_fails(): void {
        global $wpdb;

        $failingWpdb = $this->wpdbThatFailsGetResults( $wpdb, 'cambios_fecha_partido' );
        $failingRepo = new FechaRepository( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        $failingRepo->findFechaIdByMatchIds( [ 100, 101 ] );
    }

    // --- upsertFecha's re-read of an existing fecha ---------------------------

    public function test_upsert_fecha_throws_when_the_reread_of_an_existing_fecha_fails(): void {
        global $wpdb;

        $fechaId = $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), $this->samplePartidos() );

        $failingWpdb = $this->wpdbThatFailsGetRow( $wpdb, 'play_date, veces_postergada, estado_origen' );
        $failingRepo = new FechaRepository( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        // Same match_ids as $fechaId — findFechaIdByMatchIds() resolves it,
        // then the immediate re-read (the one under test) fails.
        $failingRepo->upsertFecha( $this->sampleFecha( 359, 1, '2026-06-06' ), $this->samplePartidos() );
    }

    // --- recalculateOrden ------------------------------------------------------

    public function test_recalculate_orden_throws_when_the_query_fails(): void {
        global $wpdb;

        $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), [] );

        $failingWpdb = $this->wpdbThatFailsGetResults( $wpdb, 'ORDER BY play_date ASC, id ASC' );
        $failingRepo = new FechaRepository( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        $failingRepo->recalculateOrden( 359 );
    }

    // --- nextFreeOrden (private — exercised via upsertFecha()'s INSERT path) --

    public function test_upsert_fecha_throws_when_next_free_orden_lookup_fails_on_insert(): void {
        global $wpdb;

        $failingWpdb = $this->wpdbThatFailsGetVar( $wpdb, 'MAX(orden)' );
        $failingRepo = new FechaRepository( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        // Brand-new match_ids — findFechaIdByMatchIds() resolves null, so
        // upsertFecha() takes the INSERT path, which calls nextFreeOrden().
        $failingRepo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), $this->samplePartidos() );
    }

    // --- upsertPartido's SELECT-then-insert guard ---------------------------

    public function test_upsert_fecha_throws_when_the_partido_existence_lookup_fails(): void {
        global $wpdb;

        $failingWpdb = $this->wpdbThatFailsGetVar( $wpdb, 'SELECT id FROM' );
        $failingRepo = new FechaRepository( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        $failingRepo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), $this->samplePartidos() );
    }

    // --- findById --------------------------------------------------------------

    public function test_find_by_id_throws_when_the_query_fails(): void {
        global $wpdb;

        $fechaId = $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), [] );

        $failingWpdb = $this->wpdbThatFailsGetRow( $wpdb, 'SELECT * FROM' );
        $failingRepo = new FechaRepository( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        $failingRepo->findById( $fechaId );
    }

    public function test_find_by_id_still_returns_null_when_genuinely_not_found(): void {
        $this->assertNull( $this->repo->findById( 999999 ) );
    }

    // --- findByOrden -------------------------------------------------------

    public function test_find_by_orden_throws_when_the_query_fails(): void {
        global $wpdb;

        $failingWpdb = $this->wpdbThatFailsGetRow( $wpdb, 'AND orden =' );
        $failingRepo = new FechaRepository( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        $failingRepo->findByOrden( 359, 1 );
    }

    // --- countResolvedFechasSince ------------------------------------------

    public function test_count_resueltas_throws_when_the_orden_lookup_fails(): void {
        global $wpdb;

        $idA = $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), [] );

        $failingWpdb = $this->wpdbThatFailsGetRow( $wpdb, 'SELECT orden FROM' );
        $failingRepo = new FechaRepository( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        $failingRepo->countResolvedFechasSince( 359, $idA );
    }

    public function test_count_resueltas_throws_when_the_count_query_fails(): void {
        global $wpdb;

        $idA = $this->repo->upsertFecha( $this->sampleFecha( 359, 1, '2026-05-30' ), [] );

        $failingWpdb = $this->wpdbThatFailsGetVar( $wpdb, 'SELECT COUNT(*)' );
        $failingRepo = new FechaRepository( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        $failingRepo->countResolvedFechasSince( 359, $idA );
    }

    public function test_count_resueltas_still_throws_invalid_argument_when_genuinely_not_found(): void {
        // No wpdb failure involved here — this must stay an
        // \InvalidArgumentException, not be swallowed into the new
        // \RuntimeException path (see countResolvedFechasSince()'s docblock).
        $this->expectException( \InvalidArgumentException::class );

        $this->repo->countResolvedFechasSince( 359, 999999 );
    }

}

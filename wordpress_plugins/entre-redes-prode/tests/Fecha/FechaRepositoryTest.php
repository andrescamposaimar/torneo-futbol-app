<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Fecha;

use EntreRedes\Prode\Fecha\FechaRepository;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for FechaRepository against the in-memory SQLite shim.
 *
 * NOTE — SQLite shim gap (ADR-G0-3):
 *   The dbDelta shim drops UNIQUE KEY lines from the DDL translation, so the
 *   uq_fecha_match (fecha_id, match_id) unique index is NOT enforced by the
 *   test DB. Therefore FechaRepository implements a SELECT-then-insert guard
 *   for match deduplication in code, and these tests verify idempotency by
 *   asserting ROW COUNTS rather than relying on a DB constraint.
 *
 * setUp/tearDown pattern reused from SettingsTest — the shared SQLite DB has
 * no per-test rollback, so we delete rows and re-run InitialSchema::up() on
 * tearDown to restore seeds for other tests.
 */
class FechaRepositoryTest extends TestCase {

    private FechaRepository $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        // Clear fecha-related rows for test isolation.
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );

        $this->repo = new FechaRepository( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_settings" );
        InitialSchema::up(); // restore seeds
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function sampleMatches(): array {
        return [
            [ 'match_id' => 10, 'kickoff' => '2026-05-30 13:45', 'home_team' => 'Home A', 'away_team' => 'Away A' ],
            [ 'match_id' => 11, 'kickoff' => '2026-05-30 15:10', 'home_team' => 'Home B', 'away_team' => 'Away B' ],
        ];
    }

    private function countFechas(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}prode_fechas" );
    }

    private function countFechaMatches(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}prode_fecha_matches" );
    }

    // -------------------------------------------------------------------------
    // upsertFecha — first run creates rows
    // -------------------------------------------------------------------------

    public function test_upsert_fecha_creates_fecha_row_and_match_rows(): void {
        $tenantId = 'test_tenant';
        $seasonId = 359;
        $lockedAt = '2026-05-29 13:45:00';
        $matches  = $this->sampleMatches();

        $fechaId = $this->repo->upsertFecha( $tenantId, $seasonId, $lockedAt, $matches );

        $this->assertGreaterThan( 0, $fechaId );
        $this->assertSame( 1, $this->countFechas() );
        $this->assertSame( 2, $this->countFechaMatches() );
    }

    public function test_upsert_fecha_returns_int_fecha_id(): void {
        $fechaId = $this->repo->upsertFecha( 'test_tenant', 359, '2026-05-29 13:45:00', $this->sampleMatches() );
        $this->assertIsInt( $fechaId );
        $this->assertGreaterThan( 0, $fechaId );
    }

    // -------------------------------------------------------------------------
    // upsertFecha — idempotency (ADR-G0-3)
    // -------------------------------------------------------------------------

    public function test_second_upsert_same_play_date_does_not_create_new_fecha_row(): void {
        $tenantId = 'test_tenant';
        $seasonId = 359;
        $lockedAt = '2026-05-29 13:45:00';
        $matches  = $this->sampleMatches();

        $firstId  = $this->repo->upsertFecha( $tenantId, $seasonId, $lockedAt, $matches );
        $secondId = $this->repo->upsertFecha( $tenantId, $seasonId, $lockedAt, $matches );

        // Same fecha_id reused; only one prode_fechas row.
        $this->assertSame( $firstId, $secondId );
        $this->assertSame( 1, $this->countFechas() );
    }

    public function test_second_upsert_same_play_date_does_not_duplicate_match_rows(): void {
        $tenantId = 'test_tenant';
        $seasonId = 359;
        $lockedAt = '2026-05-29 13:45:00';
        $matches  = $this->sampleMatches();

        $this->repo->upsertFecha( $tenantId, $seasonId, $lockedAt, $matches );
        $this->repo->upsertFecha( $tenantId, $seasonId, $lockedAt, $matches );

        // Row count must stay at 2, not 4.
        $this->assertSame( 2, $this->countFechaMatches() );
    }

    public function test_upsert_different_play_date_creates_new_fecha_row(): void {
        $tenantId  = 'test_tenant';
        $seasonId  = 359;

        $matchesA = [
            [ 'match_id' => 10, 'kickoff' => '2026-05-30 13:45', 'home_team' => 'A', 'away_team' => 'B' ],
        ];
        $matchesB = [
            [ 'match_id' => 20, 'kickoff' => '2026-06-06 13:45', 'home_team' => 'C', 'away_team' => 'D' ],
        ];

        $idA = $this->repo->upsertFecha( $tenantId, $seasonId, '2026-05-29 13:45:00', $matchesA );
        $idB = $this->repo->upsertFecha( $tenantId, $seasonId, '2026-06-05 13:45:00', $matchesB );

        $this->assertNotSame( $idA, $idB );
        $this->assertSame( 2, $this->countFechas() );
        $this->assertSame( 2, $this->countFechaMatches() );
    }

    public function test_team_names_are_not_written_to_db(): void {
        // The schema has no home_team / away_team columns — this test verifies
        // the repository does NOT attempt to write them (would cause a SQL error
        // if attempted). Success = no exception and row was inserted.
        global $wpdb;

        $matches = [
            [ 'match_id' => 10, 'kickoff' => '2026-05-30 13:45', 'home_team' => 'Should Not Persist', 'away_team' => 'Either' ],
        ];

        $fechaId = $this->repo->upsertFecha( 'test_tenant', 359, '2026-05-29 13:45:00', $matches );

        $this->assertGreaterThan( 0, $fechaId );

        // Confirm match row was inserted with only the schema columns.
        $row = $wpdb->get_row(
            "SELECT fecha_id, match_id, match_kickoff FROM {$wpdb->prefix}prode_fecha_matches LIMIT 1",
            ARRAY_A
        );
        $this->assertNotNull( $row );
        $this->assertArrayNotHasKey( 'home_team', $row );
        $this->assertArrayNotHasKey( 'away_team', $row );
    }

    // -------------------------------------------------------------------------
    // findActiveFecha
    // -------------------------------------------------------------------------

    public function test_find_active_fecha_returns_null_when_no_rows(): void {
        $result = $this->repo->findActiveFecha( 'test_tenant', 359 );
        $this->assertNull( $result );
    }

    public function test_find_active_fecha_returns_fecha_and_matches(): void {
        $matches = $this->sampleMatches();
        $fechaId = $this->repo->upsertFecha( 'test_tenant', 359, '2026-05-29 13:45:00', $matches );

        $result = $this->repo->findActiveFecha( 'test_tenant', 359 );

        $this->assertNotNull( $result );
        $this->assertArrayHasKey( 'fecha', $result );
        $this->assertArrayHasKey( 'matches', $result );
        $this->assertSame( $fechaId, (int) $result['fecha']['id'] );
        $this->assertCount( 2, $result['matches'] );
    }

    public function test_find_active_fecha_returns_null_when_only_evaluated_fecha_exists(): void {
        global $wpdb;

        // Manually insert an evaluated fecha (state not writable by G0 code).
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}prode_fechas (tenant_id, season_id, locked_at, state, created_at)
                 VALUES (%s, %d, %s, 'evaluated', %s)",
                'test_tenant',
                359,
                '2026-05-29 13:45:00',
                current_time( 'mysql' )
            )
        );

        $result = $this->repo->findActiveFecha( 'test_tenant', 359 );
        $this->assertNull( $result );
    }

    public function test_find_active_fecha_returns_open_fecha(): void {
        $this->repo->upsertFecha( 'test_tenant', 359, '2026-05-29 13:45:00', $this->sampleMatches() );

        $result = $this->repo->findActiveFecha( 'test_tenant', 359 );

        $this->assertNotNull( $result );
        $this->assertSame( 'open', $result['fecha']['state'] );
    }

    // -------------------------------------------------------------------------
    // G6-b: listFechasBySeasonId
    // -------------------------------------------------------------------------

    public function test_list_fechas_by_season_returns_empty_array_when_no_fechas(): void {
        $result = $this->repo->listFechasBySeasonId( 'test_tenant', 359 );
        $this->assertSame( [], $result );
    }

    public function test_list_fechas_by_season_returns_all_states_ordered_by_locked_at_asc(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Insert three fechas: evaluated (oldest locked_at), open (mid), open (future).
        // Inserted out of order on purpose to prove ORDER BY locked_at ASC works.
        $wpdb->insert( $p . 'prode_fechas', [
            'tenant_id'  => 'test_tenant',
            'season_id'  => 359,
            'locked_at'  => '2026-04-01 13:00:00',
            'state'      => 'evaluated',
            'created_at' => '2026-03-01 00:00:00',
        ] );
        $idA = (int) $wpdb->insert_id;

        $wpdb->insert( $p . 'prode_fechas', [
            'tenant_id'  => 'test_tenant',
            'season_id'  => 359,
            'locked_at'  => '2099-06-15 13:00:00',
            'state'      => 'open',
            'created_at' => '2026-04-01 00:00:00',
        ] );
        $idC = (int) $wpdb->insert_id;

        $wpdb->insert( $p . 'prode_fechas', [
            'tenant_id'  => 'test_tenant',
            'season_id'  => 359,
            'locked_at'  => '2026-05-01 13:00:00',
            'state'      => 'open',
            'created_at' => '2026-04-15 00:00:00',
        ] );
        $idB = (int) $wpdb->insert_id;

        $list = $this->repo->listFechasBySeasonId( 'test_tenant', 359 );

        $this->assertCount( 3, $list );

        // Ordered by locked_at ASC: A (Apr 1) → B (May 1) → C (Jun 15).
        $ids = array_column( $list, 'fecha_id' );
        $this->assertSame( [ $idA, $idB, $idC ], $ids );
    }

    public function test_list_fechas_by_season_returns_correct_item_shape(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( $p . 'prode_fechas', [
            'tenant_id'  => 'test_tenant',
            'season_id'  => 359,
            'locked_at'  => '2099-12-31 23:59:00',
            'state'      => 'open',
            'created_at' => '2026-01-01 00:00:00',
        ] );
        $fechaId = (int) $wpdb->insert_id;

        // Add two matches so match_count = 2.
        $wpdb->insert( $p . 'prode_fecha_matches', [
            'fecha_id'      => $fechaId,
            'match_id'      => 10,
            'match_kickoff' => '2099-12-31 13:45:00',
        ] );
        $wpdb->insert( $p . 'prode_fecha_matches', [
            'fecha_id'      => $fechaId,
            'match_id'      => 11,
            'match_kickoff' => '2099-12-31 15:10:00',
        ] );

        $list = $this->repo->listFechasBySeasonId( 'test_tenant', 359 );

        $this->assertCount( 1, $list );
        $item = $list[0];

        $this->assertArrayHasKey( 'fecha_id', $item );
        $this->assertArrayHasKey( 'season_id', $item );
        $this->assertArrayHasKey( 'state', $item );
        $this->assertArrayHasKey( 'locked_at', $item );
        $this->assertArrayHasKey( 'match_count', $item );

        $this->assertSame( $fechaId, $item['fecha_id'] );
        $this->assertSame( 359, $item['season_id'] );
        $this->assertSame( '2099-12-31 23:59:00', $item['locked_at'] );
        $this->assertSame( 2, $item['match_count'] );
    }

    public function test_list_fechas_by_season_derives_state_via_lock_computer(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Insert a fecha with locked_at in the past but DB state = 'open'.
        // deriveState should return 'locked'.
        $wpdb->insert( $p . 'prode_fechas', [
            'tenant_id'  => 'test_tenant',
            'season_id'  => 359,
            'locked_at'  => '2000-01-01 00:00:00',
            'state'      => 'open',
            'created_at' => '1999-12-01 00:00:00',
        ] );

        $lock = new \EntreRedes\Prode\Fecha\LockComputer();
        $list = $this->repo->listFechasBySeasonId( 'test_tenant', 359, $lock );

        $this->assertCount( 1, $list );
        $this->assertSame( 'locked', $list[0]['state'] );
    }

    public function test_list_fechas_by_season_tenant_isolation(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Insert for test_tenant and other_tenant.
        $wpdb->insert( $p . 'prode_fechas', [
            'tenant_id'  => 'test_tenant',
            'season_id'  => 359,
            'locked_at'  => '2099-01-01 00:00:00',
            'state'      => 'open',
            'created_at' => '2026-01-01 00:00:00',
        ] );
        $wpdb->insert( $p . 'prode_fechas', [
            'tenant_id'  => 'other_tenant',
            'season_id'  => 359,
            'locked_at'  => '2099-01-02 00:00:00',
            'state'      => 'open',
            'created_at' => '2026-01-01 00:00:00',
        ] );

        $list = $this->repo->listFechasBySeasonId( 'test_tenant', 359 );

        $this->assertCount( 1, $list );
    }

    // -------------------------------------------------------------------------
    // G6-b: findFechaById
    // -------------------------------------------------------------------------

    public function test_find_fecha_by_id_returns_null_when_not_found(): void {
        $result = $this->repo->findFechaById( 'test_tenant', 9999 );
        $this->assertNull( $result );
    }

    public function test_find_fecha_by_id_returns_null_for_wrong_tenant(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( $p . 'prode_fechas', [
            'tenant_id'  => 'other_tenant',
            'season_id'  => 359,
            'locked_at'  => '2099-01-01 00:00:00',
            'state'      => 'open',
            'created_at' => '2026-01-01 00:00:00',
        ] );
        $fechaId = (int) $wpdb->insert_id;

        $result = $this->repo->findFechaById( 'test_tenant', $fechaId );
        $this->assertNull( $result );
    }

    public function test_find_fecha_by_id_returns_any_state(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Insert an evaluated fecha (findActiveFecha would return null for this).
        $wpdb->insert( $p . 'prode_fechas', [
            'tenant_id'    => 'test_tenant',
            'season_id'    => 359,
            'locked_at'    => '2026-01-01 00:00:00',
            'state'        => 'evaluated',
            'created_at'   => '2025-12-01 00:00:00',
            'evaluated_at' => '2026-01-02 00:00:00',
        ] );
        $fechaId = (int) $wpdb->insert_id;

        $result = $this->repo->findFechaById( 'test_tenant', $fechaId );

        $this->assertNotNull( $result );
        $this->assertSame( $fechaId, (int) $result['fecha']['id'] );
        $this->assertSame( 'evaluated', $result['fecha']['state'] );
    }

    public function test_find_fecha_by_id_returns_same_struct_as_find_active(): void {
        $fechaId = $this->repo->upsertFecha( 'test_tenant', 359, '2099-12-31 23:59:00', $this->sampleMatches() );

        $byActive = $this->repo->findActiveFecha( 'test_tenant', 359 );
        $byId     = $this->repo->findFechaById( 'test_tenant', $fechaId );

        $this->assertNotNull( $byId );
        $this->assertArrayHasKey( 'fecha', $byId );
        $this->assertArrayHasKey( 'matches', $byId );
        $this->assertSame( $byActive['fecha']['id'], $byId['fecha']['id'] );
        $this->assertCount( count( $byActive['matches'] ), $byId['matches'] );
    }

    public function test_find_fecha_by_id_includes_matches(): void {
        $fechaId = $this->repo->upsertFecha(
            'test_tenant',
            359,
            '2099-12-31 23:59:00',
            $this->sampleMatches()
        );

        $result = $this->repo->findFechaById( 'test_tenant', $fechaId );

        $this->assertNotNull( $result );
        $this->assertCount( 2, $result['matches'] );
    }
}

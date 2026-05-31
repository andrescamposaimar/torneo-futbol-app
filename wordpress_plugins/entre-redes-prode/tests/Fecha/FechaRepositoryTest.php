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
}

<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Scoring;

use EntreRedes\Prode\Migrations\InitialSchema;
use EntreRedes\Prode\Scoring\RankingRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for RankingRepository (SQLite shim).
 *
 * Isolation: setUp/tearDown DELETE all prode_* rows. No test relies on prior state.
 *
 * SQLite shim notes:
 *  - UNIQUE constraint on prode_ranking_fecha_cache NOT enforced → dedup is code-level.
 *  - get_var/get_results return numeric strings → always cast (int) at boundary.
 *  - insert() returns false silently for unknown columns (but canonical columns work).
 *
 * Canonical prode_users seed: (id, tenant_id, dni, provider, provider_id, display_name, created_at).
 *
 * Spec coverage: RR-01..05, listEvaluatedFechaIds.
 */
class RankingRepositoryTest extends TestCase {

    private RankingRepository $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_ranking_fecha_cache" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );

        $this->repo = new RankingRepository( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_ranking_fecha_cache" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );
    }

    // -------------------------------------------------------------------------
    // Seeding helpers
    // -------------------------------------------------------------------------

    /**
     * Canonical prode_users seed (uses only columns that exist in the DDL).
     */
    private function seedUser( int $userId, string $displayName = '' ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_users',
            [
                'id'           => $userId,
                'tenant_id'    => PRODE_TENANT_ID,
                'dni'          => "dni_{$userId}",
                'provider'     => 'google',
                'provider_id'  => "gid_{$userId}",
                'display_name' => $displayName !== '' ? $displayName : "User {$userId}",
                'created_at'   => '2026-01-01 00:00:00',
            ]
        );
    }

    /**
     * Seed a prode_fechas row. Returns the inserted fecha_id.
     */
    private function seedFecha( string $state, int $seasonId = 10 ): int {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_fechas',
            [
                'tenant_id'    => PRODE_TENANT_ID,
                'season_id'    => $seasonId,
                'locked_at'    => '2026-05-30 10:00:00',
                'state'        => $state,
                'created_at'   => '2026-05-28 00:00:00',
                'evaluated_at' => $state === 'evaluated' ? '2026-05-31 00:00:00' : null,
            ]
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * Seed a prode_scores row.
     */
    private function seedScore( int $fechaId, int $userId, int $points, string $method = 'result_only' ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_scores',
            [
                'user_id'           => $userId,
                'fecha_id'          => $fechaId,
                'match_id'          => ( $fechaId * 100 ) + $userId, // unique match per user/fecha
                'prediction_id'     => null,
                'points'            => $points,
                'evaluation_method' => $method,
                'evaluated_at'      => '2026-06-01 00:00:00',
            ]
        );
    }

    // -------------------------------------------------------------------------
    // RR-01 — Per-fecha aggregation
    // -------------------------------------------------------------------------

    public function test_aggregate_by_fecha_returns_correct_sum_and_exact_count(): void {
        $fechaId = $this->seedFecha( 'evaluated' );
        $this->seedUser( 1 );
        $this->seedUser( 2 );

        // User 1: 3pts exact_score + 1pt result_only = 4 pts, exact_count=1
        $this->seedScore( $fechaId, 1, 3, 'exact_score' );

        global $wpdb;
        // Insert a second score row for user 1 (different match_id)
        $wpdb->insert(
            $wpdb->prefix . 'prode_scores',
            [
                'user_id'           => 1,
                'fecha_id'          => $fechaId,
                'match_id'          => 999,
                'prediction_id'     => null,
                'points'            => 1,
                'evaluation_method' => 'result_only',
                'evaluated_at'      => '2026-06-01 00:00:00',
            ]
        );

        // User 2: 0pts no_prediction, exact_count=0
        $this->seedScore( $fechaId, 2, 0, 'no_prediction' );

        $rows = $this->repo->aggregateByFecha( $fechaId );

        $byUser = [];
        foreach ( $rows as $row ) {
            $byUser[ (int) $row['user_id'] ] = $row;
        }

        $this->assertArrayHasKey( 1, $byUser );
        $this->assertSame( 4, $byUser[1]['total_points'] );
        $this->assertSame( 1, $byUser[1]['exact_count'] );
        $this->assertIsInt( $byUser[1]['total_points'] );
        $this->assertIsInt( $byUser[1]['exact_count'] );

        $this->assertArrayHasKey( 2, $byUser );
        $this->assertSame( 0, $byUser[2]['total_points'] );
        $this->assertSame( 0, $byUser[2]['exact_count'] );
    }

    // -------------------------------------------------------------------------
    // RR-02 — Season aggregation spans only evaluated fechas
    // -------------------------------------------------------------------------

    public function test_aggregate_by_season_includes_only_evaluated_fechas(): void {
        $this->seedUser( 1 );

        $fechaEvaluated = $this->seedFecha( 'evaluated', 10 );
        $fechaLocked    = $this->seedFecha( 'locked', 10 );

        // User 1 has points in both fechas.
        $this->seedScore( $fechaEvaluated, 1, 3, 'exact_score' );
        $this->seedScore( $fechaLocked, 1, 1, 'result_only' );

        $rows = $this->repo->aggregateBySeason( 10 );

        $this->assertCount( 1, $rows );
        // Only scores from the evaluated fecha (3pts) should be included.
        $this->assertSame( 1, (int) $rows[0]['user_id'] );
        $this->assertSame( 3, (int) $rows[0]['total_points'] );
        $this->assertSame( 1, (int) $rows[0]['exact_count'] );
    }

    // -------------------------------------------------------------------------
    // RR-03 — Cache upsert idempotency
    // -------------------------------------------------------------------------

    public function test_upsert_fecha_cache_is_idempotent(): void {
        $fechaId = $this->seedFecha( 'evaluated' );
        $this->seedUser( 1 );

        $rankedRows = [
            [ 'user_id' => 1, 'total_points' => 5, 'rank' => 1, 'exact_count' => 2 ],
        ];

        $now = '2026-06-01 00:00:00';

        // Call twice.
        $this->repo->upsertFechaCache( $fechaId, $rankedRows, $now );
        $this->repo->upsertFechaCache( $fechaId, $rankedRows, $now );

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}prode_ranking_fecha_cache
                  WHERE fecha_id = %d AND user_id = %d",
                $fechaId,
                1
            )
        );

        $this->assertSame( 1, $count, 'Exactly one row per (fecha_id, user_id) after double upsert.' );
    }

    // -------------------------------------------------------------------------
    // RR-04 — Cache read returns rows ordered by rank with display_name
    // -------------------------------------------------------------------------

    public function test_find_fecha_cache_returns_rows_ordered_by_rank(): void {
        $fechaId = $this->seedFecha( 'evaluated' );
        $this->seedUser( 1, 'Alice' );
        $this->seedUser( 2, 'Bob' );
        $this->seedUser( 3, 'Charlie' );

        $now        = '2026-06-01 00:00:00';
        $rankedRows = [
            [ 'user_id' => 1, 'total_points' => 10, 'rank' => 1, 'exact_count' => 2 ],
            [ 'user_id' => 2, 'total_points' => 7,  'rank' => 2, 'exact_count' => 1 ],
            [ 'user_id' => 3, 'total_points' => 5,  'rank' => 3, 'exact_count' => 0 ],
        ];
        $this->repo->upsertFechaCache( $fechaId, $rankedRows, $now );

        $cacheRows = $this->repo->findFechaCache( $fechaId );
        $total     = $this->repo->countFechaCache( $fechaId );

        $this->assertSame( 3, $total );
        $this->assertCount( 3, $cacheRows );

        // Rows must be ordered by rank ASC.
        $this->assertSame( 1, (int) $cacheRows[0]['rank'] );
        $this->assertSame( 2, (int) $cacheRows[1]['rank'] );
        $this->assertSame( 3, (int) $cacheRows[2]['rank'] );

        // int casts.
        $this->assertIsInt( $cacheRows[0]['rank'] );
        $this->assertIsInt( $cacheRows[0]['total_points'] );
    }

    // -------------------------------------------------------------------------
    // RR-05 — Zero-points user appears in cache
    // -------------------------------------------------------------------------

    public function test_zero_points_user_appears_in_cache(): void {
        $fechaId = $this->seedFecha( 'evaluated' );
        $this->seedUser( 5 );

        $rankedRows = [
            [ 'user_id' => 5, 'total_points' => 0, 'rank' => 1, 'exact_count' => 0 ],
        ];
        $this->repo->upsertFechaCache( $fechaId, $rankedRows, '2026-06-01 00:00:00' );

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}prode_ranking_fecha_cache
                  WHERE fecha_id = %d AND user_id = %d",
                $fechaId,
                5
            ),
            ARRAY_A
        );

        $this->assertNotNull( $row );
        $this->assertSame( 0, (int) $row['total_points'] );
        $this->assertSame( 1, (int) $row['rank'] );
    }

    // -------------------------------------------------------------------------
    // resolveDisplayNames
    // -------------------------------------------------------------------------

    public function test_resolve_display_names_returns_map_by_user_id(): void {
        $this->seedUser( 1, 'Alice' );
        $this->seedUser( 2, 'Bob' );

        $names = $this->repo->resolveDisplayNames( [ 1, 2 ] );

        $this->assertSame( 'Alice', $names[1] );
        $this->assertSame( 'Bob', $names[2] );
    }

    public function test_resolve_display_names_empty_input_returns_empty(): void {
        $result = $this->repo->resolveDisplayNames( [] );
        $this->assertSame( [], $result );
    }

    // -------------------------------------------------------------------------
    // listEvaluatedFechaIds
    // -------------------------------------------------------------------------

    public function test_list_evaluated_fecha_ids_returns_only_evaluated(): void {
        $this->seedFecha( 'evaluated', 10 ); // should appear
        $evaluatedId2 = $this->seedFecha( 'evaluated', 10 ); // should appear
        $this->seedFecha( 'locked', 10 );   // should NOT appear
        $this->seedFecha( 'open', 10 );     // should NOT appear

        $ids = $this->repo->listEvaluatedFechaIds( PRODE_TENANT_ID );

        $this->assertCount( 2, $ids );
        $this->assertContains( $evaluatedId2, $ids );
        foreach ( $ids as $id ) {
            $this->assertIsInt( $id );
        }
    }
}

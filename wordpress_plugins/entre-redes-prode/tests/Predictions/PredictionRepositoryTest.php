<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Predictions;

use EntreRedes\Prode\Migrations\InitialSchema;
use EntreRedes\Prode\Predictions\PredictionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for PredictionRepository against the in-memory SQLite shim.
 *
 * NOTE — SQLite shim gap:
 *   The dbDelta shim drops UNIQUE KEY lines from the DDL translation, so the
 *   uq_user_match (user_id, match_id) unique index is NOT enforced by the
 *   test DB. PredictionRepository uses SELECT-then-INSERT/UPDATE as the
 *   authoritative dedup mechanism. Tests verify idempotency by asserting ROW
 *   COUNTS and column values, not by relying on DB constraint violations.
 *
 * setUp/tearDown pattern mirrors FechaRepositoryTest — the shared SQLite DB
 * has no per-test rollback, so we delete rows in setUp and tearDown.
 */
class PredictionRepositoryTest extends TestCase {

    private PredictionRepository $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        // Clear prediction-related rows for test isolation.
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );

        $this->repo = new PredictionRepository( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function countPredictions(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}prode_predictions" );
    }

    /**
     * Fetch a raw prediction row by (user_id, match_id).
     *
     * @return array<string, mixed>|null
     */
    private function fetchRow( int $userId, int $matchId ): ?array {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}prode_predictions WHERE user_id = %d AND match_id = %d LIMIT 1",
                $userId,
                $matchId
            ),
            ARRAY_A
        );
    }

    // -------------------------------------------------------------------------
    // deriveResult — pure helper (A1-1)
    // -------------------------------------------------------------------------

    public function test_derive_result_returns_1_for_home_win(): void {
        $this->assertSame( '1', $this->repo->deriveResult( 3, 1 ) );
    }

    public function test_derive_result_returns_x_for_draw(): void {
        $this->assertSame( 'X', $this->repo->deriveResult( 0, 0 ) );
    }

    public function test_derive_result_returns_2_for_away_win(): void {
        $this->assertSame( '2', $this->repo->deriveResult( 0, 2 ) );
    }

    public function test_derive_result_handles_max_score_boundary(): void {
        // Scores are TINYINT UNSIGNED [0, 255]; the comparator must hold at the bound.
        $this->assertSame( '1', $this->repo->deriveResult( 255, 0 ) );
        $this->assertSame( 'X', $this->repo->deriveResult( 255, 255 ) );
        $this->assertSame( '2', $this->repo->deriveResult( 0, 255 ) );
    }

    // -------------------------------------------------------------------------
    // upsert — insert on first call (A1-2)
    // -------------------------------------------------------------------------

    public function test_upsert_inserts_row_for_new_user_match_pair(): void {
        $this->repo->upsert(
            userId:            1,
            fechaId:           10,
            matchId:           5,
            scoreHome:         2,
            scoreAway:         1,
            lockedAtSnapshot:  '2026-06-01 10:00:00'
        );

        $this->assertSame( 1, $this->countPredictions() );
    }

    public function test_upsert_sets_correct_scores_and_derived_result_on_insert(): void {
        $this->repo->upsert(
            userId:            1,
            fechaId:           10,
            matchId:           5,
            scoreHome:         2,
            scoreAway:         1,
            lockedAtSnapshot:  '2026-06-01 10:00:00'
        );

        $row = $this->fetchRow( 1, 5 );

        $this->assertNotNull( $row );
        $this->assertSame( 2, (int) $row['score_home'] );
        $this->assertSame( 1, (int) $row['score_away'] );
        $this->assertSame( '1', $row['result'] );
    }

    public function test_upsert_sets_locked_at_snapshot_on_insert(): void {
        $this->repo->upsert(
            userId:            1,
            fechaId:           10,
            matchId:           5,
            scoreHome:         0,
            scoreAway:         0,
            lockedAtSnapshot:  '2026-06-01 10:00:00'
        );

        $row = $this->fetchRow( 1, 5 );
        $this->assertSame( '2026-06-01 10:00:00', $row['locked_at_snapshot'] );
    }

    public function test_upsert_sets_created_at_on_insert(): void {
        $this->repo->upsert(
            userId:            1,
            fechaId:           10,
            matchId:           5,
            scoreHome:         1,
            scoreAway:         0,
            lockedAtSnapshot:  '2026-06-01 10:00:00'
        );

        $row = $this->fetchRow( 1, 5 );
        $this->assertNotEmpty( $row['created_at'] );
    }

    // -------------------------------------------------------------------------
    // upsert — update on second call for same (user, match) (A1-3)
    // -------------------------------------------------------------------------

    public function test_upsert_second_call_does_not_add_new_row(): void {
        $this->repo->upsert( 1, 10, 5, 2, 1, '2026-06-01 10:00:00' );
        $this->repo->upsert( 1, 10, 5, 3, 0, '2026-06-01 10:00:00' );

        $this->assertSame( 1, $this->countPredictions() );
    }

    public function test_upsert_second_call_updates_scores_and_result(): void {
        $this->repo->upsert( 1, 10, 5, 2, 1, '2026-06-01 10:00:00' );
        $this->repo->upsert( 1, 10, 5, 0, 0, '2026-06-01 10:00:00' );

        $row = $this->fetchRow( 1, 5 );

        $this->assertSame( 0, (int) $row['score_home'] );
        $this->assertSame( 0, (int) $row['score_away'] );
        $this->assertSame( 'X', $row['result'] );
    }

    public function test_upsert_second_call_does_not_change_created_at(): void {
        $this->repo->upsert( 1, 10, 5, 2, 1, '2026-06-01 10:00:00' );
        $rowAfterInsert = $this->fetchRow( 1, 5 );

        // Small sleep to ensure time moves if any timestamp resolution issues exist.
        usleep( 100000 ); // 0.1 seconds

        $this->repo->upsert( 1, 10, 5, 3, 1, '2026-06-01 10:00:00' );
        $rowAfterUpdate = $this->fetchRow( 1, 5 );

        $this->assertSame( $rowAfterInsert['created_at'], $rowAfterUpdate['created_at'] );
    }

    public function test_upsert_second_call_bumps_updated_at(): void {
        $this->repo->upsert( 1, 10, 5, 2, 1, '2026-06-01 10:00:00' );
        $rowAfterInsert = $this->fetchRow( 1, 5 );

        // current_time('mysql') has 1-second resolution; sleep >1s to guarantee
        // the formatted timestamp changes, so a dropped 'updated_at' in the UPDATE
        // map would be caught instead of silently passing.
        usleep( 1100000 ); // 1.1 seconds

        $this->repo->upsert( 1, 10, 5, 3, 1, '2026-06-01 10:00:00' );
        $rowAfterUpdate = $this->fetchRow( 1, 5 );

        $this->assertNotSame( $rowAfterInsert['updated_at'], $rowAfterUpdate['updated_at'] );
        $this->assertGreaterThan( $rowAfterUpdate['created_at'], $rowAfterUpdate['updated_at'] );
    }

    public function test_upsert_second_call_updates_locked_at_snapshot(): void {
        $this->repo->upsert( 1, 10, 5, 2, 1, '2026-06-01 10:00:00' );
        $this->repo->upsert( 1, 10, 5, 2, 1, '2026-06-02 10:00:00' );

        $row = $this->fetchRow( 1, 5 );
        $this->assertSame( '2026-06-02 10:00:00', $row['locked_at_snapshot'] );
    }

    // -------------------------------------------------------------------------
    // findByUserAndFecha — returns user's predictions for fecha (A1-2 cont.)
    // -------------------------------------------------------------------------

    public function test_find_by_user_and_fecha_returns_empty_when_no_predictions(): void {
        $results = $this->repo->findByUserAndFecha( 10, 1 );
        $this->assertSame( [], $results );
    }

    public function test_find_by_user_and_fecha_returns_predictions_for_user(): void {
        $this->repo->upsert( 1, 10, 5, 2, 1, '2026-06-01 10:00:00' );
        $this->repo->upsert( 1, 10, 6, 0, 0, '2026-06-01 10:00:00' );

        $results = $this->repo->findByUserAndFecha( 10, 1 );

        $this->assertCount( 2, $results );
    }

    public function test_find_by_user_and_fecha_returns_correct_fields(): void {
        $this->repo->upsert( 1, 10, 5, 2, 1, '2026-06-01 10:00:00' );

        $results = $this->repo->findByUserAndFecha( 10, 1 );

        $this->assertCount( 1, $results );
        $this->assertSame( 5, (int) $results[0]['match_id'] );
        $this->assertSame( 2, (int) $results[0]['score_home'] );
        $this->assertSame( 1, (int) $results[0]['score_away'] );
    }

    public function test_find_by_user_and_fecha_does_not_return_other_users_predictions(): void {
        // User 1 inserts for fecha 10
        $this->repo->upsert( 1, 10, 5, 2, 1, '2026-06-01 10:00:00' );
        // User 2 inserts for fecha 10, different match
        $this->repo->upsert( 2, 10, 6, 1, 0, '2026-06-01 10:00:00' );

        $results = $this->repo->findByUserAndFecha( 10, 1 );

        // User 1 should only see their own prediction
        $this->assertCount( 1, $results );
        $this->assertSame( 5, (int) $results[0]['match_id'] );
    }

    public function test_find_by_user_and_fecha_does_not_return_other_fecha_predictions(): void {
        // User 1 inserts for fecha 10 (match 5) and fecha 20 (match 7)
        $this->repo->upsert( 1, 10, 5, 2, 1, '2026-06-01 10:00:00' );
        $this->repo->upsert( 1, 20, 7, 0, 1, '2026-06-08 10:00:00' );

        $results = $this->repo->findByUserAndFecha( 10, 1 );

        $this->assertCount( 1, $results );
        $this->assertSame( 5, (int) $results[0]['match_id'] );
    }

    // -------------------------------------------------------------------------
    // aggregatePopulares — percentage breakdown per match (G6-c)
    // -------------------------------------------------------------------------

    /**
     * Helper: insert a raw prediction row directly (bypasses upsert dedup logic,
     * useful for seeding multiple users' predictions quickly).
     */
    private function insertPrediction( int $userId, int $fechaId, int $matchId, string $result ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_predictions',
            [
                'user_id'            => $userId,
                'fecha_id'           => $fechaId,
                'match_id'           => $matchId,
                'result'             => $result,
                'score_home'         => 1,
                'score_away'         => 0,
                'created_at'         => '2026-01-01 00:00:00',
                'updated_at'         => '2026-01-01 00:00:00',
                'locked_at_snapshot' => '2026-01-01 00:00:00',
            ]
        );
    }

    public function test_aggregate_populares_returns_empty_map_when_no_predictions(): void {
        // fecha_id 99 has no predictions at all.
        $result = $this->repo->aggregatePopulares( 99 );

        $this->assertSame( [], $result );
    }

    public function test_aggregate_populares_computes_percentages_for_single_match(): void {
        // Match 5, fecha 10: 2×'1', 1×'X', 1×'2' → 50.0 / 25.0 / 25.0.
        $this->insertPrediction( 1, 10, 5, '1' );
        $this->insertPrediction( 2, 10, 5, '1' );
        $this->insertPrediction( 3, 10, 5, 'X' );
        $this->insertPrediction( 4, 10, 5, '2' );

        $result = $this->repo->aggregatePopulares( 10 );

        $this->assertArrayHasKey( 5, $result );
        $this->assertSame( 50.0, $result[5]['1'] );
        $this->assertSame( 25.0, $result[5]['X'] );
        $this->assertSame( 25.0, $result[5]['2'] );
    }

    public function test_aggregate_populares_result_with_zero_votes_appears_as_zero_point_zero(): void {
        // Match 6, fecha 10: only '1' predictions → X and 2 must appear as 0.0.
        $this->insertPrediction( 1, 10, 6, '1' );
        $this->insertPrediction( 2, 10, 6, '1' );
        $this->insertPrediction( 3, 10, 6, '1' );

        $result = $this->repo->aggregatePopulares( 10 );

        $this->assertArrayHasKey( 6, $result );
        $this->assertSame( 100.0, $result[6]['1'] );
        $this->assertSame( 0.0, $result[6]['X'] );
        $this->assertSame( 0.0, $result[6]['2'] );
    }

    public function test_aggregate_populares_always_has_all_three_result_keys(): void {
        // Even when only one result type exists, all three keys ('1', 'X', '2') must be present.
        $this->insertPrediction( 1, 10, 7, 'X' );

        $result = $this->repo->aggregatePopulares( 10 );

        $this->assertArrayHasKey( 7, $result );
        $this->assertArrayHasKey( '1', $result[7] );
        $this->assertArrayHasKey( 'X', $result[7] );
        $this->assertArrayHasKey( '2', $result[7] );
    }

    public function test_aggregate_populares_spans_multiple_matches(): void {
        // Match 10: 2 predictions (1, X). Match 11: 1 prediction (2).
        $this->insertPrediction( 1, 20, 10, '1' );
        $this->insertPrediction( 2, 20, 10, 'X' );
        $this->insertPrediction( 3, 20, 11, '2' );

        $result = $this->repo->aggregatePopulares( 20 );

        $this->assertArrayHasKey( 10, $result );
        $this->assertArrayHasKey( 11, $result );

        // Match 10: 50/50.
        $this->assertSame( 50.0, $result[10]['1'] );
        $this->assertSame( 50.0, $result[10]['X'] );
        $this->assertSame( 0.0, $result[10]['2'] );

        // Match 11: 100% for '2'.
        $this->assertSame( 0.0, $result[11]['1'] );
        $this->assertSame( 0.0, $result[11]['X'] );
        $this->assertSame( 100.0, $result[11]['2'] );
    }

    public function test_aggregate_populares_excludes_other_fecha_predictions(): void {
        // Predictions in fecha 30 must NOT appear in fecha 40's aggregate.
        $this->insertPrediction( 1, 30, 5, '1' );
        $this->insertPrediction( 2, 30, 5, '1' );
        $this->insertPrediction( 3, 40, 5, 'X' );

        $resultFecha30 = $this->repo->aggregatePopulares( 30 );
        $resultFecha40 = $this->repo->aggregatePopulares( 40 );

        // Fecha 30: match 5 all '1'.
        $this->assertSame( 100.0, $resultFecha30[5]['1'] );
        $this->assertSame( 0.0, $resultFecha30[5]['X'] );

        // Fecha 40: match 5 all 'X'.
        $this->assertSame( 0.0, $resultFecha40[5]['1'] );
        $this->assertSame( 100.0, $resultFecha40[5]['X'] );
    }

    public function test_aggregate_populares_rounds_to_one_decimal(): void {
        // 1/3 = 33.333... → must round to 33.3.
        // 3 predictions: one for each result.
        $this->insertPrediction( 1, 50, 5, '1' );
        $this->insertPrediction( 2, 50, 5, 'X' );
        $this->insertPrediction( 3, 50, 5, '2' );

        $result = $this->repo->aggregatePopulares( 50 );

        // Each is 33.3%.
        $this->assertSame( 33.3, $result[5]['1'] );
        $this->assertSame( 33.3, $result[5]['X'] );
        $this->assertSame( 33.3, $result[5]['2'] );
    }

    public function test_aggregate_populares_match_absent_when_no_predictions_for_it(): void {
        // Fecha 60: match 5 has predictions, match 9 has none.
        // match 9 must NOT appear in the result map.
        $this->insertPrediction( 1, 60, 5, '1' );

        $result = $this->repo->aggregatePopulares( 60 );

        $this->assertArrayHasKey( 5, $result );
        $this->assertArrayNotHasKey( 9, $result );
    }

    // -------------------------------------------------------------------------
    // T-06 — findByUserAndFecha: LEFT JOIN prode_scores for points + method
    // -------------------------------------------------------------------------

    /**
     * Helper: insert a prode_scores row for a given (user, match).
     */
    private function insertScore( int $userId, int $fechaId, int $matchId, int $points, string $method ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_scores',
            [
                'user_id'           => $userId,
                'fecha_id'          => $fechaId,
                'match_id'          => $matchId,
                'prediction_id'     => null,
                'points'            => $points,
                'evaluation_method' => $method,
                'evaluated_at'      => '2026-06-01 00:00:00',
            ]
        );
    }

    public function test_findByUserAndFecha_returns_points_when_scored(): void {
        // Seed a prediction + a matching prode_scores row → assert points and method present.
        $this->repo->upsert( 1, 10, 5, 2, 0, '2026-06-01 10:00:00' );
        $this->insertScore( 1, 10, 5, 3, 'exact_score' );

        $results = $this->repo->findByUserAndFecha( 10, 1 );

        $this->assertCount( 1, $results );
        $this->assertSame( 5, (int) $results[0]['match_id'] );
        $this->assertSame( 3, (int) $results[0]['points'] );
        $this->assertSame( 'exact_score', $results[0]['evaluation_method'] );
    }

    public function test_findByUserAndFecha_returns_null_points_when_not_scored(): void {
        // Prediction exists but no prode_scores row → points and method must be null.
        $this->repo->upsert( 1, 10, 5, 1, 1, '2026-06-01 10:00:00' );
        // No insertScore call.

        $results = $this->repo->findByUserAndFecha( 10, 1 );

        $this->assertCount( 1, $results );
        $this->assertNull( $results[0]['points'] );
        $this->assertNull( $results[0]['evaluation_method'] );
    }

    public function test_findByUserAndFecha_backward_compat_pre_change_evaluated(): void {
        // prode_scores row exists (evaluated) but real_score_* columns are NULL on
        // prode_fecha_matches — points must still be returned correctly.
        // The LEFT JOIN is on prode_scores only, independent of real_score columns.
        $this->repo->upsert( 1, 10, 5, 1, 0, '2026-06-01 10:00:00' );
        $this->insertScore( 1, 10, 5, 1, 'result_only' );

        $results = $this->repo->findByUserAndFecha( 10, 1 );

        $this->assertCount( 1, $results );
        $this->assertSame( 1, (int) $results[0]['points'] );
        $this->assertSame( 'result_only', $results[0]['evaluation_method'] );
    }

    public function test_findByUserAndFecha_mixed_scored_and_unscored_predictions(): void {
        // match 5 scored, match 6 not scored → correct nulls on each.
        $this->repo->upsert( 1, 10, 5, 2, 0, '2026-06-01 10:00:00' );
        $this->repo->upsert( 1, 10, 6, 1, 1, '2026-06-01 10:00:00' );
        $this->insertScore( 1, 10, 5, 3, 'exact_score' );
        // match 6 has no score row.

        $results = $this->repo->findByUserAndFecha( 10, 1 );
        $this->assertCount( 2, $results );

        $byMatchId = [];
        foreach ( $results as $row ) {
            $byMatchId[ (int) $row['match_id'] ] = $row;
        }

        $this->assertSame( 3, (int) $byMatchId[5]['points'] );
        $this->assertSame( 'exact_score', $byMatchId[5]['evaluation_method'] );
        $this->assertNull( $byMatchId[6]['points'] );
        $this->assertNull( $byMatchId[6]['evaluation_method'] );
    }

    // -------------------------------------------------------------------------
    // aggregateForMatch — per-match split used by the match detail screen
    // -------------------------------------------------------------------------

    /**
     * Helper: seed a fecha row so aggregateForMatch's join has something to hit.
     */
    private function insertFecha( int $id, string $state ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_fechas',
            [
                'id'         => $id,
                'tenant_id'  => 'test_tenant',
                'season_id'  => 300,
                'locked_at'  => '2026-01-01 00:00:00',
                'state'      => $state,
                'created_at' => '2026-01-01 00:00:00',
            ]
        );
    }

    public function test_aggregate_for_match_returns_zeroes_when_match_has_no_predictions(): void {
        $result = $this->repo->aggregateForMatch( 999 );

        $this->assertSame( 0, $result['total'] );
        $this->assertFalse( $result['open'] );
        $this->assertSame( 0.0, $result['populares']['1'] );
        $this->assertSame( 0.0, $result['populares']['X'] );
        $this->assertSame( 0.0, $result['populares']['2'] );
    }

    public function test_aggregate_for_match_computes_percentages(): void {
        $this->insertFecha( 40, 'evaluated' );
        // Match 5: 2x'1', 1x'X', 1x'2' -> 50 / 25 / 25.
        $this->insertPrediction( 1, 40, 5, '1' );
        $this->insertPrediction( 2, 40, 5, '1' );
        $this->insertPrediction( 3, 40, 5, 'X' );
        $this->insertPrediction( 4, 40, 5, '2' );

        $result = $this->repo->aggregateForMatch( 5 );

        $this->assertSame( 4, $result['total'] );
        $this->assertFalse( $result['open'] );
        $this->assertSame( 50.0, $result['populares']['1'] );
        $this->assertSame( 25.0, $result['populares']['X'] );
        $this->assertSame( 25.0, $result['populares']['2'] );
    }

    public function test_aggregate_for_match_always_returns_all_three_keys(): void {
        $this->insertFecha( 41, 'locked' );
        $this->insertPrediction( 1, 41, 8, 'X' );

        $result = $this->repo->aggregateForMatch( 8 );

        $this->assertSame( 100.0, $result['populares']['X'] );
        $this->assertSame( 0.0, $result['populares']['1'] );
        $this->assertSame( 0.0, $result['populares']['2'] );
    }

    public function test_aggregate_for_match_flags_open_fecha(): void {
        // The gate depends on this flag: an open round must not publish a split.
        $this->insertFecha( 42, 'open' );
        $this->insertPrediction( 1, 42, 9, '1' );

        $result = $this->repo->aggregateForMatch( 9 );

        $this->assertTrue( $result['open'] );
        $this->assertSame( 1, $result['total'] );
    }

    public function test_aggregate_for_match_ignores_other_matches(): void {
        $this->insertFecha( 43, 'evaluated' );
        $this->insertPrediction( 1, 43, 30, '1' );
        $this->insertPrediction( 2, 43, 31, '2' );
        $this->insertPrediction( 3, 43, 31, '2' );

        $result = $this->repo->aggregateForMatch( 30 );

        $this->assertSame( 1, $result['total'] );
        $this->assertSame( 100.0, $result['populares']['1'] );
    }
}

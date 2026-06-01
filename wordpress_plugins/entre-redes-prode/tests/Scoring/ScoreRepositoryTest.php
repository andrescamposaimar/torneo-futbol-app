<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Scoring;

use EntreRedes\Prode\Migrations\InitialSchema;
use EntreRedes\Prode\Scoring\ScoreRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ScoreRepository against the in-memory SQLite shim.
 *
 * NOTE — SQLite shim gap:
 *   The dbDelta shim drops UNIQUE KEY lines from the DDL translation, so the
 *   uq_user_match (user_id, match_id) unique index is NOT enforced by the test DB.
 *   ScoreRepository uses SELECT-then-INSERT/UPDATE as the authoritative dedup.
 *   Tests verify idempotency by asserting ROW COUNTS and column values, not by
 *   relying on DB constraint violations.
 *
 * setUp/tearDown pattern mirrors PredictionRepositoryTest.
 *
 * Spec coverage: SR-1..SR-6, R2.1..R2.7, R8.5.
 */
class ScoreRepositoryTest extends TestCase {

    private ScoreRepository $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        // Clear relevant rows for test isolation.
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );

        $this->repo = new ScoreRepository( $wpdb );
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

    private function countScores(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}prode_scores" );
    }

    /**
     * Fetch a raw score row by (user_id, match_id).
     *
     * @return array<string, mixed>|null
     */
    private function fetchRow( int $userId, int $matchId ): ?array {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}prode_scores WHERE user_id = %d AND match_id = %d LIMIT 1",
                $userId,
                $matchId
            ),
            ARRAY_A
        );
    }

    // -------------------------------------------------------------------------
    // upsertScore — insert on first call (SR-1)
    // -------------------------------------------------------------------------

    /** SR-1: first upsert → exactly one row inserted with all correct columns. */
    public function test_insert_first_evaluation(): void {
        $this->repo->upsertScore(
            userId:       10,
            fechaId:      5,
            matchId:      101,
            predictionId: 77,
            points:       3,
            method:       'exact_score',
            evaluatedAt:  '2026-06-01 12:00:00'
        );

        $this->assertSame( 1, $this->countScores() );

        $row = $this->fetchRow( 10, 101 );
        $this->assertNotNull( $row );
        $this->assertSame( 10, (int) $row['user_id'] );
        $this->assertSame( 5, (int) $row['fecha_id'] );
        $this->assertSame( 101, (int) $row['match_id'] );
        $this->assertSame( 77, (int) $row['prediction_id'] );
        $this->assertSame( 3, (int) $row['points'] );
        $this->assertSame( 'exact_score', $row['evaluation_method'] );
        $this->assertSame( '2026-06-01 12:00:00', $row['evaluated_at'] );
    }

    // -------------------------------------------------------------------------
    // upsertScore — update in place on re-evaluation (SR-2)
    // -------------------------------------------------------------------------

    /** SR-2: second upsert with same (user_id, match_id) → still 1 row, updated values. */
    public function test_update_in_place_on_re_evaluation(): void {
        $this->repo->upsertScore( 10, 5, 101, 77, 3, 'exact_score', '2026-06-01 12:00:00' );
        $this->repo->upsertScore( 10, 5, 101, 77, 1, 'result_only', '2026-06-01 13:00:00' );

        $this->assertSame( 1, $this->countScores() );

        $row = $this->fetchRow( 10, 101 );
        $this->assertSame( 1, (int) $row['points'] );
        $this->assertSame( 'result_only', $row['evaluation_method'] );
        $this->assertSame( '2026-06-01 13:00:00', $row['evaluated_at'] );
    }

    // -------------------------------------------------------------------------
    // upsertScore — upgrade from no_match_score (SR-3)
    // -------------------------------------------------------------------------

    /** SR-3: upsert no_match_score then result_only → 1 row updated, no duplicate. */
    public function test_upgrade_from_no_match_score(): void {
        $this->repo->upsertScore( 10, 5, 101, null, 0, 'no_match_score', '2026-06-01 12:00:00' );
        $this->repo->upsertScore( 10, 5, 101, 77, 1, 'result_only', '2026-06-01 14:00:00' );

        $this->assertSame( 1, $this->countScores() );

        $row = $this->fetchRow( 10, 101 );
        $this->assertSame( 'result_only', $row['evaluation_method'] );
        $this->assertSame( 1, (int) $row['points'] );
    }

    // -------------------------------------------------------------------------
    // upsertScore — no_prediction row with NULL prediction_id (SR-4)
    // -------------------------------------------------------------------------

    /** SR-4: upsert with no prediction → row has prediction_id IS NULL, points=0. */
    public function test_no_prediction_row_null_prediction_id(): void {
        $this->repo->upsertScore( 10, 5, 202, null, 0, 'no_prediction', '2026-06-01 12:00:00' );

        $row = $this->fetchRow( 10, 202 );
        $this->assertNotNull( $row );
        $this->assertNull( $row['prediction_id'] );
        $this->assertSame( 0, (int) $row['points'] );
        $this->assertSame( 'no_prediction', $row['evaluation_method'] );
    }

    // -------------------------------------------------------------------------
    // countUnscoredMatches (SR-5, SR-6)
    // -------------------------------------------------------------------------

    /** SR-5: 2 no_match_score rows + 1 exact_score → countUnscoredMatches = 2. */
    public function test_count_unscored_matches_partial(): void {
        // 2 different matches with no_match_score for the same fecha.
        $this->repo->upsertScore( 10, 5, 101, null, 0, 'no_match_score', '2026-06-01 12:00:00' );
        $this->repo->upsertScore( 10, 5, 102, null, 0, 'no_match_score', '2026-06-01 12:00:00' );
        // 1 scored match.
        $this->repo->upsertScore( 10, 5, 103, 77, 3, 'exact_score', '2026-06-01 12:00:00' );

        $count = $this->repo->countUnscoredMatches( 5 );
        $this->assertSame( 2, $count );
    }

    /** SR-6: 0 no_match_score rows → countUnscoredMatches = 0; return type is int. */
    public function test_count_unscored_matches_zero(): void {
        $this->repo->upsertScore( 10, 5, 101, 77, 3, 'exact_score', '2026-06-01 12:00:00' );

        $count = $this->repo->countUnscoredMatches( 5 );
        $this->assertSame( 0, $count );
        $this->assertIsInt( $count ); // get_var returns string — must be cast (R2.7, design §B quirk).
    }

    // -------------------------------------------------------------------------
    // findByFecha
    // -------------------------------------------------------------------------

    /** findByFecha with no rows → returns empty array. */
    public function test_find_by_fecha_returns_empty_array(): void {
        $rows = $this->repo->findByFecha( 999 );
        $this->assertSame( [], $rows );
    }

    /** findByFecha returns all rows for the fecha_id. */
    public function test_find_by_fecha_returns_rows(): void {
        $this->repo->upsertScore( 10, 5, 101, 77, 3, 'exact_score', '2026-06-01 12:00:00' );
        $this->repo->upsertScore( 20, 5, 101, 88, 1, 'result_only', '2026-06-01 12:00:00' );

        $rows = $this->repo->findByFecha( 5 );
        $this->assertCount( 2, $rows );
    }

    /** findByFecha does not return rows from other fechas. */
    public function test_find_by_fecha_does_not_cross_contaminate(): void {
        $this->repo->upsertScore( 10, 5, 101, 77, 3, 'exact_score', '2026-06-01 12:00:00' );
        $this->repo->upsertScore( 10, 99, 101, 77, 1, 'result_only', '2026-06-01 12:00:00' );

        $rows = $this->repo->findByFecha( 5 );
        $this->assertCount( 1, $rows );
        $this->assertSame( 5, (int) $rows[0]['fecha_id'] );
    }
}

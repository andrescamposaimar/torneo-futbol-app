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
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );

        $this->repo = new PredictionRepository( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
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
}

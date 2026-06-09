<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Predictions;

use EntreRedes\Prode\Migrations\InitialSchema;
use EntreRedes\Prode\Predictions\PredictionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PredictionRepository::findAllByUser() and countByUser().
 *
 * T-13 (Strict TDD — RED written first).
 *
 * Design constraints:
 *   - No wp_users JOIN anywhere.
 *   - JOIN: prode_predictions p
 *           LEFT JOIN prode_fecha_matches fm ON fm.match_id = p.match_id AND fm.fecha_id = p.fecha_id
 *           LEFT JOIN prode_scores s ON s.user_id = p.user_id AND s.match_id = p.match_id
 *   - ORDER BY p.fecha_id ASC, p.match_id ASC.
 *   - Results include: fecha_id, match_id, home_team, away_team, score_home, score_away,
 *     real_score_home, real_score_away, is_final, points, evaluation_method.
 *   - countByUser(int) returns the total row count for that user.
 *   - SQLite shim compatible: no ON DUPLICATE KEY UPDATE, no window functions.
 */
class PredictionRepositoryFindAllByUserTest extends TestCase {

    private PredictionRepository $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        // Isolation: clear all rows that touch the tables involved.
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

    private function insertPrediction(
        int    $userId,
        int    $fechaId,
        int    $matchId,
        int    $scoreHome,
        int    $scoreAway
    ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_predictions',
            [
                'user_id'            => $userId,
                'fecha_id'           => $fechaId,
                'match_id'           => $matchId,
                'result'             => 'X',
                'score_home'         => $scoreHome,
                'score_away'         => $scoreAway,
                'created_at'         => '2026-01-01 00:00:00',
                'updated_at'         => '2026-01-01 00:00:00',
                'locked_at_snapshot' => '2026-01-01 00:00:00',
            ]
        );
    }

    private function insertFechaMatch(
        int    $fechaId,
        int    $matchId,
        string $homeTeam = 'Home FC',
        string $awayTeam = 'Away FC',
        ?int   $realHome = null,
        ?int   $realAway = null,
        int    $isFinal = 0
    ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_fecha_matches',
            [
                'fecha_id'        => $fechaId,
                'match_id'        => $matchId,
                'match_kickoff'   => '2026-06-01 18:00:00',
                'home_team'       => $homeTeam,
                'away_team'       => $awayTeam,
                'real_score_home' => $realHome,
                'real_score_away' => $realAway,
                'is_final'        => $isFinal,
            ]
        );
    }

    private function insertScore(
        int    $userId,
        int    $fechaId,
        int    $matchId,
        int    $points,
        string $method
    ): void {
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

    // -------------------------------------------------------------------------
    // countByUser
    // -------------------------------------------------------------------------

    public function test_countByUser_returns_zero_when_no_predictions(): void {
        $this->assertSame( 0, $this->repo->countByUser( 99 ) );
    }

    public function test_countByUser_returns_count_for_user(): void {
        $this->insertPrediction( 1, 10, 5, 2, 0 );
        $this->insertPrediction( 1, 10, 6, 1, 1 );
        $this->insertPrediction( 2, 10, 5, 0, 0 ); // different user — must not count.

        $this->assertSame( 2, $this->repo->countByUser( 1 ) );
    }

    public function test_countByUser_isolates_per_user(): void {
        $this->insertPrediction( 1, 10, 5, 2, 0 );
        $this->insertPrediction( 2, 10, 6, 0, 0 );

        $this->assertSame( 1, $this->repo->countByUser( 1 ) );
        $this->assertSame( 1, $this->repo->countByUser( 2 ) );
    }

    // -------------------------------------------------------------------------
    // findAllByUser — basic retrieval
    // -------------------------------------------------------------------------

    public function test_findAllByUser_returns_empty_when_no_predictions(): void {
        $this->assertSame( [], $this->repo->findAllByUser( 99 ) );
    }

    public function test_findAllByUser_returns_prediction_rows_for_user(): void {
        $this->insertPrediction( 1, 10, 5, 2, 0 );
        $this->insertFechaMatch( 10, 5, 'River Plate', 'Boca Juniors' );

        $rows = $this->repo->findAllByUser( 1 );

        $this->assertCount( 1, $rows );
        $this->assertSame( 10, (int) $rows[0]['fecha_id'] );
        $this->assertSame( 5,  (int) $rows[0]['match_id'] );
        $this->assertSame( 2,  (int) $rows[0]['score_home'] );
        $this->assertSame( 0,  (int) $rows[0]['score_away'] );
    }

    public function test_findAllByUser_includes_home_and_away_team_from_fecha_matches(): void {
        $this->insertPrediction( 1, 10, 5, 1, 0 );
        $this->insertFechaMatch( 10, 5, 'River Plate', 'Boca Juniors' );

        $rows = $this->repo->findAllByUser( 1 );

        $this->assertSame( 'River Plate', $rows[0]['home_team'] );
        $this->assertSame( 'Boca Juniors', $rows[0]['away_team'] );
    }

    public function test_findAllByUser_includes_null_team_names_when_no_fecha_match_row(): void {
        // Prediction exists but no prode_fecha_matches row → LEFT JOIN returns NULL.
        $this->insertPrediction( 1, 10, 5, 1, 0 );

        $rows = $this->repo->findAllByUser( 1 );

        $this->assertCount( 1, $rows );
        $this->assertNull( $rows[0]['home_team'] );
        $this->assertNull( $rows[0]['away_team'] );
    }

    // -------------------------------------------------------------------------
    // findAllByUser — real-score columns
    // -------------------------------------------------------------------------

    public function test_findAllByUser_includes_real_score_when_final(): void {
        $this->insertPrediction( 1, 10, 5, 1, 0 );
        $this->insertFechaMatch( 10, 5, 'H', 'A', 2, 1, 1 );

        $rows = $this->repo->findAllByUser( 1 );

        $this->assertSame( 2, (int) $rows[0]['real_score_home'] );
        $this->assertSame( 1, (int) $rows[0]['real_score_away'] );
        $this->assertSame( 1, (int) $rows[0]['is_final'] );
    }

    public function test_findAllByUser_real_score_is_null_when_not_final(): void {
        $this->insertPrediction( 1, 10, 5, 1, 0 );
        $this->insertFechaMatch( 10, 5, 'H', 'A', null, null, 0 );

        $rows = $this->repo->findAllByUser( 1 );

        $this->assertNull( $rows[0]['real_score_home'] );
        $this->assertNull( $rows[0]['real_score_away'] );
        $this->assertSame( 0, (int) $rows[0]['is_final'] );
    }

    // -------------------------------------------------------------------------
    // findAllByUser — points and evaluation_method
    // -------------------------------------------------------------------------

    public function test_findAllByUser_includes_points_when_scored(): void {
        $this->insertPrediction( 1, 10, 5, 2, 0 );
        $this->insertScore( 1, 10, 5, 3, 'exact_score' );

        $rows = $this->repo->findAllByUser( 1 );

        $this->assertSame( 3, (int) $rows[0]['points'] );
        $this->assertSame( 'exact_score', $rows[0]['evaluation_method'] );
    }

    public function test_findAllByUser_points_null_when_not_scored(): void {
        $this->insertPrediction( 1, 10, 5, 1, 1 );

        $rows = $this->repo->findAllByUser( 1 );

        $this->assertNull( $rows[0]['points'] );
        $this->assertNull( $rows[0]['evaluation_method'] );
    }

    // -------------------------------------------------------------------------
    // findAllByUser — ordering: fecha_id ASC, match_id ASC
    // -------------------------------------------------------------------------

    public function test_findAllByUser_orders_by_fecha_then_match_ascending(): void {
        // Insert in reverse order to ensure ORDER BY is actually applied.
        $this->insertPrediction( 1, 20, 9, 0, 0 );
        $this->insertPrediction( 1, 10, 7, 1, 0 );
        $this->insertPrediction( 1, 10, 5, 2, 0 );

        $rows = $this->repo->findAllByUser( 1 );

        $this->assertCount( 3, $rows );
        $this->assertSame( 10, (int) $rows[0]['fecha_id'] );
        $this->assertSame( 5,  (int) $rows[0]['match_id'] );
        $this->assertSame( 10, (int) $rows[1]['fecha_id'] );
        $this->assertSame( 7,  (int) $rows[1]['match_id'] );
        $this->assertSame( 20, (int) $rows[2]['fecha_id'] );
        $this->assertSame( 9,  (int) $rows[2]['match_id'] );
    }

    // -------------------------------------------------------------------------
    // findAllByUser — user isolation
    // -------------------------------------------------------------------------

    public function test_findAllByUser_does_not_return_other_users_predictions(): void {
        $this->insertPrediction( 1, 10, 5, 2, 0 );
        $this->insertPrediction( 2, 10, 5, 1, 1 ); // different user, same match/fecha.

        $rows = $this->repo->findAllByUser( 1 );

        $this->assertCount( 1, $rows );
        // User 1's prediction: score_home=2, score_away=0.
        $this->assertSame( 2, (int) $rows[0]['score_home'] );
        $this->assertSame( 0, (int) $rows[0]['score_away'] );
    }
}

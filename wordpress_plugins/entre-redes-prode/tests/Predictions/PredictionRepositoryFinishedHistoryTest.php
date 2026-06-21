<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Predictions;

use EntreRedes\Prode\Migrations\InitialSchema;
use EntreRedes\Prode\Predictions\PredictionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PredictionRepository::findFinishedByUserPaginated() and
 * countFinishedByUser() — the paginated "Anteriores" (past predictions) history.
 *
 * Design constraints:
 *   - Only matches with prode_fecha_matches.is_final = 1 are returned.
 *   - ORDER BY match_kickoff DESC, match_id DESC (most recent first).
 *   - Includes season_id (from prode_fechas), kickoff/zona/escudos/real scores
 *     (snapshot from prode_fecha_matches) and points/evaluation_method (scores).
 *   - SQLite-shim compatible: no window functions, LIMIT/OFFSET via %d.
 */
class PredictionRepositoryFinishedHistoryTest extends TestCase {

    private PredictionRepository $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
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

    private function insertFecha( int $fechaId, int $seasonId = 359 ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_fechas',
            [
                'id'         => $fechaId,
                'tenant_id'  => 'test_tenant',
                'season_id'  => $seasonId,
                'locked_at'  => '2026-05-30 10:00:00',
                'state'      => 'evaluated',
                'created_at' => '2026-05-28 00:00:00',
            ]
        );
    }

    private function insertPrediction( int $userId, int $fechaId, int $matchId, int $scoreHome, int $scoreAway ): void {
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
        int $fechaId,
        int $matchId,
        string $kickoff,
        int $isFinal,
        ?int $realHome = null,
        ?int $realAway = null,
        string $homeTeam = 'Home FC',
        string $awayTeam = 'Away FC',
        string $zona = 'Apertura Zona A'
    ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_fecha_matches',
            [
                'fecha_id'        => $fechaId,
                'match_id'        => $matchId,
                'match_kickoff'   => $kickoff,
                'home_team'       => $homeTeam,
                'away_team'       => $awayTeam,
                'zona'            => $zona,
                'home_escudo'     => 'https://e/h.png',
                'away_escudo'     => 'https://e/a.png',
                'real_score_home' => $realHome,
                'real_score_away' => $realAway,
                'is_final'        => $isFinal,
            ]
        );
    }

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

    // -------------------------------------------------------------------------
    // countFinishedByUser
    // -------------------------------------------------------------------------

    public function test_count_zero_when_no_predictions(): void {
        $this->assertSame( 0, $this->repo->countFinishedByUser( 1 ) );
    }

    public function test_count_excludes_non_final_matches(): void {
        $this->insertFecha( 10 );
        // Final match → counts.
        $this->insertPrediction( 1, 10, 5, 1, 0 );
        $this->insertFechaMatch( 10, 5, '2026-06-01 18:00:00', 1, 2, 0 );
        // Non-final match → excluded.
        $this->insertPrediction( 1, 10, 6, 0, 0 );
        $this->insertFechaMatch( 10, 6, '2026-06-08 18:00:00', 0 );

        $this->assertSame( 1, $this->repo->countFinishedByUser( 1 ) );
    }

    public function test_count_isolates_per_user(): void {
        $this->insertFecha( 10 );
        $this->insertPrediction( 1, 10, 5, 1, 0 );
        $this->insertPrediction( 2, 10, 5, 0, 1 );
        $this->insertFechaMatch( 10, 5, '2026-06-01 18:00:00', 1, 2, 0 );

        $this->assertSame( 1, $this->repo->countFinishedByUser( 1 ) );
        $this->assertSame( 1, $this->repo->countFinishedByUser( 2 ) );
    }

    // -------------------------------------------------------------------------
    // findFinishedByUserPaginated
    // -------------------------------------------------------------------------

    public function test_returns_empty_when_no_finished_predictions(): void {
        $this->assertSame( [], $this->repo->findFinishedByUserPaginated( 1, 15, 0 ) );
    }

    public function test_excludes_non_final_and_other_users(): void {
        $this->insertFecha( 10 );
        $this->insertPrediction( 1, 10, 5, 1, 0 );
        $this->insertFechaMatch( 10, 5, '2026-06-01 18:00:00', 1, 2, 0 );
        // Non-final → excluded.
        $this->insertPrediction( 1, 10, 6, 0, 0 );
        $this->insertFechaMatch( 10, 6, '2026-06-08 18:00:00', 0 );
        // Other user → excluded.
        $this->insertPrediction( 2, 10, 5, 0, 1 );

        $rows = $this->repo->findFinishedByUserPaginated( 1, 15, 0 );

        $this->assertCount( 1, $rows );
        $this->assertSame( 5, (int) $rows[0]['match_id'] );
    }

    public function test_orders_by_kickoff_desc(): void {
        $this->insertFecha( 10 );
        // Insert oldest first to prove ordering is applied.
        $this->insertPrediction( 1, 10, 5, 1, 0 );
        $this->insertFechaMatch( 10, 5, '2026-06-01 18:00:00', 1, 1, 0 );
        $this->insertPrediction( 1, 10, 6, 2, 2 );
        $this->insertFechaMatch( 10, 6, '2026-06-15 18:00:00', 1, 2, 2 );
        $this->insertPrediction( 1, 10, 7, 0, 1 );
        $this->insertFechaMatch( 10, 7, '2026-06-08 18:00:00', 1, 0, 0 );

        $rows = $this->repo->findFinishedByUserPaginated( 1, 15, 0 );

        $this->assertCount( 3, $rows );
        $this->assertSame( 6, (int) $rows[0]['match_id'] ); // 06-15 (newest)
        $this->assertSame( 7, (int) $rows[1]['match_id'] ); // 06-08
        $this->assertSame( 5, (int) $rows[2]['match_id'] ); // 06-01 (oldest)
    }

    public function test_pagination_slices_correctly(): void {
        $this->insertFecha( 10 );
        // 4 finished matches, kickoff ascending by match id.
        for ( $i = 1; $i <= 4; $i++ ) {
            $this->insertPrediction( 1, 10, $i, 1, 0 );
            $this->insertFechaMatch( 10, $i, sprintf( '2026-06-0%d 18:00:00', $i ), 1, 1, 0 );
        }

        // per_page 2, page 1 → newest two (match 4, 3).
        $page1 = $this->repo->findFinishedByUserPaginated( 1, 2, 0 );
        $this->assertCount( 2, $page1 );
        $this->assertSame( 4, (int) $page1[0]['match_id'] );
        $this->assertSame( 3, (int) $page1[1]['match_id'] );

        // page 2 → match 2, 1.
        $page2 = $this->repo->findFinishedByUserPaginated( 1, 2, 2 );
        $this->assertCount( 2, $page2 );
        $this->assertSame( 2, (int) $page2[0]['match_id'] );
        $this->assertSame( 1, (int) $page2[1]['match_id'] );
    }

    public function test_row_carries_season_snapshot_and_scores(): void {
        $this->insertFecha( 10, 2026 );
        $this->insertPrediction( 1, 10, 5, 2, 0 );
        $this->insertFechaMatch( 10, 5, '2026-06-01 18:00:00', 1, 3, 0, 'River', 'Boca', 'Apertura Zona B' );
        $this->insertScore( 1, 10, 5, 1, 'result_only' );

        $rows = $this->repo->findFinishedByUserPaginated( 1, 15, 0 );
        $row  = $rows[0];

        $this->assertSame( 2026, (int) $row['season_id'] );
        $this->assertSame( 'River', $row['home_team'] );
        $this->assertSame( 'Boca', $row['away_team'] );
        $this->assertSame( 'Apertura Zona B', $row['zona'] );
        $this->assertSame( '2026-06-01 18:00:00', $row['match_kickoff'] );
        $this->assertSame( 2, (int) $row['score_home'] );
        $this->assertSame( 0, (int) $row['score_away'] );
        $this->assertSame( 3, (int) $row['real_score_home'] );
        $this->assertSame( 0, (int) $row['real_score_away'] );
        $this->assertSame( 1, (int) $row['points'] );
        $this->assertSame( 'result_only', $row['evaluation_method'] );
    }

    public function test_points_null_when_final_but_not_evaluated(): void {
        $this->insertFecha( 10 );
        $this->insertPrediction( 1, 10, 5, 1, 0 );
        $this->insertFechaMatch( 10, 5, '2026-06-01 18:00:00', 1, 2, 0 );
        // No score row inserted.

        $rows = $this->repo->findFinishedByUserPaginated( 1, 15, 0 );

        $this->assertNull( $rows[0]['points'] );
        $this->assertNull( $rows[0]['evaluation_method'] );
    }
}

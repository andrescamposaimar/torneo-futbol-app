<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Scoring;

use EntreRedes\Prode\Fecha\FechaRepository;
use EntreRedes\Prode\Migrations\InitialSchema;
use EntreRedes\Prode\Predictions\PredictionRepository;
use EntreRedes\Prode\Scoring\FechaEvaluator;
use EntreRedes\Prode\Scoring\ScoreRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for FechaEvaluator.
 *
 * All tests inject a stub dispatcher returning canned /partidos envelopes.
 * No real rest_do_request is called (ADR-G3-5).
 *
 * Payload envelope shape: { total: int, items: [{ id, goles_local, goles_visitante },...] }
 * — mirrors FechaResolverTest::stubEnvelopeDispatcher.
 *
 * SQLite shim: no UNIQUE constraint enforcement; idempotency proven via row count assertions.
 *
 * Spec coverage: RF-1..RF-6, FS-1..FS-3, RH-1..RH-3, EC-1..EC-3, R3.1..R3.8, R4.1..R4.4, R5.1..R5.3.
 */
class FechaEvaluatorTest extends TestCase {

    private ScoreRepository     $scoreRepo;
    private PredictionRepository $predRepo;
    private FechaRepository     $fechaRepo;

    /** fecha_id seeded by seedLockedFecha(). */
    private int $fechaId;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );

        // Reset action counter for each test.
        $GLOBALS['_prode_test_actions'] = [];

        $this->scoreRepo = new ScoreRepository( $wpdb );
        $this->predRepo  = new PredictionRepository( $wpdb );
        $this->fechaRepo = new FechaRepository( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );
    }

    // -------------------------------------------------------------------------
    // Helpers — dispatcher stubs
    // -------------------------------------------------------------------------

    /**
     * Returns a dispatcher stub returning the given items as a paginated envelope.
     *
     * The callable receives a WP_REST_Request and returns a WP_REST_Response
     * whose data is { total: int, items: [...] }. FechaEvaluator reads `total`
     * from the envelope data key (not from HTTP headers — shim has no headers).
     *
     * Mirrors FechaResolverTest::stubEnvelopeDispatcher but adapted for
     * /partidos (needs pagination via total+items envelope).
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function stubDispatcher( array $items, int $total = 0 ): callable {
        $total = $total ?: count( $items );
        return static function ( \WP_REST_Request $req ) use ( $items, $total ): \WP_REST_Response {
            return new \WP_REST_Response( [ 'total' => $total, 'items' => $items ] );
        };
    }

    /**
     * Make a /partidos item shape.
     *
     * @param int      $id             match_id
     * @param int|null $golesLocal     null = not yet played
     * @param int|null $golesVisitante null = not yet played
     */
    private function makeMatchItem( int $id, ?int $golesLocal, ?int $golesVisitante ): array {
        return [
            'id'              => $id,
            'goles_local'     => $golesLocal,
            'goles_visitante' => $golesVisitante,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers — DB seeding
    // -------------------------------------------------------------------------

    private function seedUser( int $userId ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_users',
            [
                'id'               => $userId,
                'tenant_id'        => 'test',
                'wp_user_id'       => $userId,
                'display_name'     => "User {$userId}",
                'provider'         => 'google',
                'provider_user_id' => "gid_{$userId}",
                'status'           => 'active',
                'created_at'       => '2026-01-01 00:00:00',
            ]
        );
    }

    /**
     * Insert a locked fecha with the given match_ids.
     *
     * @param array<int> $matchIds
     */
    private function seedLockedFecha( array $matchIds, int $seasonId = 359 ): int {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_fechas',
            [
                'tenant_id'   => 'test',
                'season_id'   => $seasonId,
                'locked_at'   => '2026-05-30 10:00:00',
                'state'       => 'locked',
                'created_at'  => '2026-05-28 00:00:00',
                'evaluated_at' => null,
            ]
        );
        $fechaId = $wpdb->insert_id;

        foreach ( $matchIds as $matchId ) {
            $wpdb->insert(
                $wpdb->prefix . 'prode_fecha_matches',
                [
                    'fecha_id'       => $fechaId,
                    'match_id'       => $matchId,
                    'match_kickoff'  => '2026-05-30 13:00:00',
                ]
            );
        }

        $this->fechaId = $fechaId;
        return $fechaId;
    }

    /**
     * Insert a prode_prediction for (userId, fechaId, matchId, scoreHome, scoreAway).
     */
    private function seedPrediction( int $userId, int $matchId, int $scoreHome = 1, int $scoreAway = 0 ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_predictions',
            [
                'user_id'            => $userId,
                'fecha_id'           => $this->fechaId,
                'match_id'           => $matchId,
                'result'             => $scoreHome > $scoreAway ? '1' : ( $scoreHome === $scoreAway ? 'X' : '2' ),
                'score_home'         => $scoreHome,
                'score_away'         => $scoreAway,
                'created_at'         => '2026-05-29 10:00:00',
                'updated_at'         => '2026-05-29 10:00:00',
                'locked_at_snapshot' => '2026-05-30 10:00:00',
            ]
        );
    }

    private function countScores(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}prode_scores" );
    }

    private function getFechaState( int $fechaId ): string {
        global $wpdb;
        return (string) $wpdb->get_var(
            $wpdb->prepare( "SELECT state FROM {$wpdb->prefix}prode_fechas WHERE id = %d", $fechaId )
        );
    }

    private function buildEvaluator( callable $dispatcher ): FechaEvaluator {
        return new FechaEvaluator(
            $this->scoreRepo,
            $this->predRepo,
            $this->fechaRepo,
            $dispatcher
        );
    }

    // -------------------------------------------------------------------------
    // EC-1: no locked fecha → nothing happens
    // -------------------------------------------------------------------------

    /** EC-1: fecha not found → evaluateFecha returns early; hook NOT fired. */
    public function test_no_locked_fecha_exits_cleanly(): void {
        $evaluator = $this->buildEvaluator( $this->stubDispatcher( [] ) );

        // fechaId 999 does not exist.
        $summary = $evaluator->evaluateFecha( 999 );

        $this->assertSame( 0, $this->countScores() );
        $this->assertSame( 0, did_action( 'prode_recompute_rankings_cron' ) );
        // Summary should reflect no-op.
        $this->assertSame( 999, $summary['fecha_id'] );
        $this->assertSame( 0, $summary['scored_rows'] );
    }

    // -------------------------------------------------------------------------
    // EC-2 + FS-1 + RH-1: all matches final → scores written, state flips, hook fires
    // -------------------------------------------------------------------------

    /** EC-2 + FS-1 + RH-1: all 2 matches final, 3 users → 6 rows, state='evaluated', hook=1. */
    public function test_all_matches_final_flips_state_and_fires_hook(): void {
        $fechaId = $this->seedLockedFecha( [ 101, 102 ] );
        $this->seedUser( 1 );
        $this->seedUser( 2 );
        $this->seedUser( 3 );

        // All 3 users predict both matches.
        foreach ( [ 1, 2, 3 ] as $uid ) {
            $this->seedPrediction( $uid, 101, 2, 1 );
            $this->seedPrediction( $uid, 102, 0, 1 );
        }

        $items = [
            $this->makeMatchItem( 101, 2, 1 ), // exact for those who predicted 2-1
            $this->makeMatchItem( 102, 0, 1 ), // exact for those who predicted 0-1
        ];
        $evaluator = $this->buildEvaluator( $this->stubDispatcher( $items ) );
        $summary   = $evaluator->evaluateFecha( $fechaId );

        $this->assertSame( 6, $this->countScores() );
        $this->assertSame( 'evaluated', $this->getFechaState( $fechaId ) );
        $this->assertSame( 1, did_action( 'prode_recompute_rankings_cron' ) );
        $this->assertSame( 6, $summary['scored_rows'] );
        $this->assertSame( 'evaluated', $summary['fecha_state'] );
    }

    // -------------------------------------------------------------------------
    // EC-3 + FS-2 + RH-2: partial — match B null scores → stays locked, hook fires
    // -------------------------------------------------------------------------

    /** EC-3 + FS-2 + RH-2: match A final, match B null → no_match_score rows; locked; hook fires once. */
    public function test_partial_matches_stays_locked_and_fires_hook(): void {
        $fechaId = $this->seedLockedFecha( [ 101, 102 ] );
        $this->seedUser( 1 );
        $this->seedUser( 2 );

        $this->seedPrediction( 1, 101, 2, 1 );
        $this->seedPrediction( 2, 101, 1, 0 );
        $this->seedPrediction( 1, 102, 0, 2 );
        $this->seedPrediction( 2, 102, 1, 1 );

        $items = [
            $this->makeMatchItem( 101, 1, 0 ), // match A final
            $this->makeMatchItem( 102, null, null ), // match B not played
        ];
        $evaluator = $this->buildEvaluator( $this->stubDispatcher( $items ) );
        $evaluator->evaluateFecha( $fechaId );

        $this->assertSame( 'locked', $this->getFechaState( $fechaId ) );
        $this->assertSame( 1, did_action( 'prode_recompute_rankings_cron' ) );

        // match B rows should be no_match_score.
        global $wpdb;
        $noMatchCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}prode_scores WHERE match_id = %d AND evaluation_method = 'no_match_score'",
                102
            )
        );
        $this->assertSame( 2, $noMatchCount );
    }

    // -------------------------------------------------------------------------
    // FS-3: re-run after match B gets scores → state flips to 'evaluated'
    // -------------------------------------------------------------------------

    /** FS-3: second pass after match B is final → countUnscoredMatches=0 → state='evaluated'. */
    public function test_re_run_after_match_b_final_flips_state(): void {
        $fechaId = $this->seedLockedFecha( [ 101, 102 ] );
        $this->seedUser( 1 );

        $this->seedPrediction( 1, 101, 2, 1 );
        $this->seedPrediction( 1, 102, 0, 2 );

        // Pass 1: match B not played.
        $pass1Items = [
            $this->makeMatchItem( 101, 2, 1 ),
            $this->makeMatchItem( 102, null, null ),
        ];
        $evaluator = $this->buildEvaluator( $this->stubDispatcher( $pass1Items ) );
        $evaluator->evaluateFecha( $fechaId );

        $this->assertSame( 'locked', $this->getFechaState( $fechaId ) );

        // Pass 2: match B now final.
        $GLOBALS['_prode_test_actions'] = []; // reset hook counter.
        $pass2Items = [
            $this->makeMatchItem( 101, 2, 1 ),
            $this->makeMatchItem( 102, 1, 0 ),
        ];
        $evaluator2 = $this->buildEvaluator( $this->stubDispatcher( $pass2Items ) );
        $evaluator2->evaluateFecha( $fechaId );

        $this->assertSame( 'evaluated', $this->getFechaState( $fechaId ) );
        $this->assertSame( 1, did_action( 'prode_recompute_rankings_cron' ) );
    }

    // -------------------------------------------------------------------------
    // RF-3: match absent from API → no_match_score for all participants
    // -------------------------------------------------------------------------

    /** RF-3: match_id=103 in prode_fecha_matches but absent from dispatcher → no_match_score. */
    public function test_match_absent_from_api_treated_as_no_match_score(): void {
        $fechaId = $this->seedLockedFecha( [ 101, 103 ] );
        $this->seedUser( 1 );

        $this->seedPrediction( 1, 101, 2, 1 );
        $this->seedPrediction( 1, 103, 0, 1 );

        // Dispatcher returns only match 101; match 103 is absent.
        $items     = [ $this->makeMatchItem( 101, 2, 1 ) ];
        $evaluator = $this->buildEvaluator( $this->stubDispatcher( $items ) );
        $evaluator->evaluateFecha( $fechaId );

        global $wpdb;
        $method = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT evaluation_method FROM {$wpdb->prefix}prode_scores WHERE match_id = %d AND user_id = %d",
                103,
                1
            )
        );
        $this->assertSame( 'no_match_score', $method );
    }

    // -------------------------------------------------------------------------
    // RF-4: pagination — dispatcher called per page
    // -------------------------------------------------------------------------

    /** RF-4: total=3, per_page=2 → evaluator paginates; all 3 matches in result map. */
    public function test_pagination_fetches_all_pages(): void {
        $fechaId = $this->seedLockedFecha( [ 101, 102, 103 ] );
        $this->seedUser( 1 );

        $this->seedPrediction( 1, 101, 2, 1 );
        $this->seedPrediction( 1, 102, 1, 0 );
        $this->seedPrediction( 1, 103, 0, 0 );

        // Paginating dispatcher: page 1 → 2 items, page 2 → 1 item; total=3.
        $page1 = [ $this->makeMatchItem( 101, 2, 1 ), $this->makeMatchItem( 102, 1, 0 ) ];
        $page2 = [ $this->makeMatchItem( 103, 0, 0 ) ];
        $pages = [ 1 => $page1, 2 => $page2 ];

        $callCount = 0;
        $dispatcher = static function ( \WP_REST_Request $req ) use ( $pages, &$callCount ): \WP_REST_Response {
            $callCount++;
            $page  = (int) ( $req->get_param( 'page' ) ?? 1 );
            $items = $pages[ $page ] ?? [];
            return new \WP_REST_Response( [ 'total' => 3, 'items' => $items ] );
        };

        $evaluator = $this->buildEvaluator( $dispatcher );
        $evaluator->evaluateFecha( $fechaId );

        // All 3 matches scored → state = 'evaluated'.
        $this->assertSame( 'evaluated', $this->getFechaState( $fechaId ) );
        $this->assertSame( 3, $this->countScores() );
        // Must have requested at least 2 pages.
        $this->assertGreaterThanOrEqual( 2, $callCount );
    }

    // -------------------------------------------------------------------------
    // RF-5: user with zero predictions → no rows written
    // -------------------------------------------------------------------------

    /** RF-5: user_id=99 has no predictions for this fecha → no prode_scores rows. */
    public function test_user_with_zero_predictions_gets_no_rows(): void {
        $fechaId = $this->seedLockedFecha( [ 101 ] );
        $this->seedUser( 1 );
        $this->seedUser( 99 );

        // Only user 1 has a prediction; user 99 has none.
        $this->seedPrediction( 1, 101, 2, 1 );

        $items     = [ $this->makeMatchItem( 101, 2, 1 ) ];
        $evaluator = $this->buildEvaluator( $this->stubDispatcher( $items ) );
        $evaluator->evaluateFecha( $fechaId );

        global $wpdb;
        $user99Count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}prode_scores WHERE user_id = %d",
                99
            )
        );
        $this->assertSame( 0, $user99Count );
    }

    // -------------------------------------------------------------------------
    // RF-6: user predicts match A but not match B → 2 rows (scored + no_prediction)
    // -------------------------------------------------------------------------

    /** RF-6: user predicts match 101 only; both final → scored row + no_prediction row for 102. */
    public function test_user_missing_one_match_gets_no_prediction_row(): void {
        $fechaId = $this->seedLockedFecha( [ 101, 102 ] );
        $this->seedUser( 1 );

        // Only predict match 101.
        $this->seedPrediction( 1, 101, 2, 1 );

        $items = [
            $this->makeMatchItem( 101, 2, 1 ),
            $this->makeMatchItem( 102, 1, 0 ),
        ];
        $evaluator = $this->buildEvaluator( $this->stubDispatcher( $items ) );
        $evaluator->evaluateFecha( $fechaId );

        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT match_id, evaluation_method FROM {$wpdb->prefix}prode_scores WHERE user_id = %d ORDER BY match_id",
                1
            ),
            ARRAY_A
        );

        $this->assertCount( 2, $rows );
        $methods = array_column( $rows, 'evaluation_method', 'match_id' );
        $this->assertContains( 'exact_score', array_values( $methods ) );
        $this->assertSame( 'no_prediction', $methods[102] );
    }

    // -------------------------------------------------------------------------
    // RF-2: null score blocks state flip (RH-2 variant)
    // -------------------------------------------------------------------------

    /** RF-2: match with null scores → no_match_score rows; hook fires; state stays locked. */
    public function test_null_score_blocks_state_flip(): void {
        $fechaId = $this->seedLockedFecha( [ 101 ] );
        $this->seedUser( 1 );
        $this->seedPrediction( 1, 101, 2, 1 );

        $items     = [ $this->makeMatchItem( 101, null, null ) ];
        $evaluator = $this->buildEvaluator( $this->stubDispatcher( $items ) );
        $evaluator->evaluateFecha( $fechaId );

        global $wpdb;
        $method = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT evaluation_method FROM {$wpdb->prefix}prode_scores WHERE match_id = %d AND user_id = %d",
                101,
                1
            )
        );
        $this->assertSame( 'no_match_score', $method );
        $this->assertSame( 'locked', $this->getFechaState( $fechaId ) );
        $this->assertSame( 1, did_action( 'prode_recompute_rankings_cron' ) );
    }

    // -------------------------------------------------------------------------
    // RH-3: invalid fechaId → early return; hook NOT fired
    // -------------------------------------------------------------------------

    /** RH-3: invalid fecha → early return; hook count=0. */
    public function test_hook_not_fired_on_error(): void {
        $evaluator = $this->buildEvaluator( $this->stubDispatcher( [] ) );
        $evaluator->evaluateFecha( 0 );

        $this->assertSame( 0, did_action( 'prode_recompute_rankings_cron' ) );
    }

    // -------------------------------------------------------------------------
    // Idempotent re-evaluation (SR-3 integration)
    // -------------------------------------------------------------------------

    /** SR-3 integration: run twice on same fecha → row count unchanged; points updated. */
    public function test_idempotent_re_evaluation(): void {
        $fechaId = $this->seedLockedFecha( [ 101 ] );
        $this->seedUser( 1 );
        $this->seedPrediction( 1, 101, 2, 1 );

        $items     = [ $this->makeMatchItem( 101, 2, 1 ) ];
        $evaluator = $this->buildEvaluator( $this->stubDispatcher( $items ) );

        $evaluator->evaluateFecha( $fechaId );
        $countAfterFirst = $this->countScores();

        $evaluator->evaluateFecha( $fechaId );
        $countAfterSecond = $this->countScores();

        $this->assertSame( $countAfterFirst, $countAfterSecond );
        $this->assertSame( 1, $countAfterSecond );
    }
}

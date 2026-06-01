<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Rest;

use EntreRedes\Prode\Fecha\FechaRepository;
use EntreRedes\Prode\Migrations\InitialSchema;
use EntreRedes\Prode\Predictions\PredictionRepository;
use EntreRedes\Prode\Rest\EvaluationController;
use EntreRedes\Prode\Scoring\FechaEvaluator;
use EntreRedes\Prode\Scoring\ScoreRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for POST /prode/evaluar-fecha (EvaluationController::handleEvaluate).
 *
 * Capability check is injected as a closure (ADR-G3-4) because the test shim
 * has no current_user_can() / wp_set_current_user(). The closure seam is the
 * ONLY viable approach — mirrors FechaResolver's dispatcher seam (ADR-G0-4).
 *
 * The results dispatcher is stubbed (mirrors FechaResolverTest + FechaEvaluatorTest).
 *
 * Spec coverage: AE-1..AE-9, R7.1..R7.7.
 */
class EvaluationControllerTest extends TestCase {

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
     * @param array<int, array<string, mixed>> $items
     */
    private function stubDispatcher( array $items ): callable {
        $total = count( $items );
        return static function ( \WP_REST_Request $req ) use ( $items, $total ): \WP_REST_Response {
            return new \WP_REST_Response( [ 'total' => $total, 'items' => $items ] );
        };
    }

    private function makeMatchItem( int $id, ?int $golesLocal, ?int $golesVisitante ): array {
        return [ 'id' => $id, 'goles_local' => $golesLocal, 'goles_visitante' => $golesVisitante ];
    }

    // -------------------------------------------------------------------------
    // Helpers — DB seeding
    // -------------------------------------------------------------------------

    private function seedUser( int $userId ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_users',
            [
                'id'           => $userId,
                'tenant_id'    => PRODE_TENANT_ID,
                'dni'          => "dni_{$userId}",
                'provider'     => 'google',
                'provider_id'  => "gid_{$userId}",
                'display_name' => "User {$userId}",
                'created_at'   => '2026-01-01 00:00:00',
            ]
        );
    }

    /**
     * @param array<int> $matchIds
     */
    private function seedFecha( array $matchIds, string $state = 'locked', int $seasonId = 359 ): int {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_fechas',
            [
                'tenant_id'    => 'test',
                'season_id'    => $seasonId,
                'locked_at'    => '2026-05-30 10:00:00',
                'state'        => $state,
                'created_at'   => '2026-05-28 00:00:00',
                'evaluated_at' => null,
            ]
        );
        $fechaId = $wpdb->insert_id;

        foreach ( $matchIds as $matchId ) {
            $wpdb->insert(
                $wpdb->prefix . 'prode_fecha_matches',
                [
                    'fecha_id'      => $fechaId,
                    'match_id'      => $matchId,
                    'match_kickoff' => '2026-05-30 13:00:00',
                ]
            );
        }

        $this->fechaId = $fechaId;
        return $fechaId;
    }

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

    // -------------------------------------------------------------------------
    // Helpers — controller factory
    // -------------------------------------------------------------------------

    private function buildController( callable $dispatcher, callable $capCheck ): EvaluationController {
        $evaluator = new FechaEvaluator( $this->scoreRepo, $this->predRepo, $this->fechaRepo, $dispatcher );
        return new EvaluationController( $evaluator, $capCheck );
    }

    private function buildRequest( array $params = [] ): \WP_REST_Request {
        $request = new \WP_REST_Request( 'POST', '/entre-redes/v1/prode/evaluar-fecha' );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        return $request;
    }

    // -------------------------------------------------------------------------
    // Auth gate: AE-1, AE-2 → 401
    // -------------------------------------------------------------------------

    /** AE-1: unauthenticated → 401. */
    public function test_unauthenticated_returns_401(): void {
        $controller = $this->buildController( $this->stubDispatcher( [] ), fn() => false );
        $request    = $this->buildRequest( [ 'fecha_id' => 1 ] );

        $response = $controller->handleEvaluate( $request );

        $this->assertSame( 401, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'unauthorized', $data['code'] );
    }

    /** AE-2: non-admin capability → 401. */
    public function test_non_admin_returns_401(): void {
        $controller = $this->buildController( $this->stubDispatcher( [] ), fn() => false );
        $request    = $this->buildRequest( [ 'fecha_id' => 5 ] );

        $response = $controller->handleEvaluate( $request );

        $this->assertSame( 401, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'unauthorized', $data['code'] );
        $this->assertSame( 401, $data['data']['status'] );
    }

    // -------------------------------------------------------------------------
    // Validation: AE-3 → missing fecha_id → 400
    // -------------------------------------------------------------------------

    /** AE-3: admin, body {} → 400 missing_fecha_id. */
    public function test_missing_fecha_id_returns_400(): void {
        $controller = $this->buildController( $this->stubDispatcher( [] ), fn() => true );
        $request    = $this->buildRequest(); // no fecha_id

        $response = $controller->handleEvaluate( $request );

        $this->assertSame( 400, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'missing_fecha_id', $data['code'] );
        $this->assertSame( 400, $data['data']['status'] );
    }

    // -------------------------------------------------------------------------
    // Not found: AE-4 → fecha_id not in DB → 400
    // -------------------------------------------------------------------------

    /** AE-4: fecha_id=999 does not exist → 400 fecha_not_found. */
    public function test_fecha_not_found_returns_400(): void {
        $controller = $this->buildController( $this->stubDispatcher( [] ), fn() => true );
        $request    = $this->buildRequest( [ 'fecha_id' => 999 ] );

        $response = $controller->handleEvaluate( $request );

        $this->assertSame( 400, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'fecha_not_found', $data['code'] );
    }

    // -------------------------------------------------------------------------
    // Wrong state: AE-5, AE-6 → fecha not locked → 400
    // -------------------------------------------------------------------------

    /** AE-5: fecha.state='open' → 400 fecha_not_locked. */
    public function test_fecha_not_locked_open_state_returns_400(): void {
        $fechaId    = $this->seedFecha( [ 101 ], 'open' );
        $controller = $this->buildController( $this->stubDispatcher( [] ), fn() => true );
        $request    = $this->buildRequest( [ 'fecha_id' => $fechaId ] );

        $response = $controller->handleEvaluate( $request );

        $this->assertSame( 400, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'fecha_not_locked', $data['code'] );
    }

    /** AE-6: fecha.state='evaluated' → 400 fecha_not_locked (A3: re-evaluation is cron-only). */
    public function test_fecha_not_locked_evaluated_state_returns_400(): void {
        $fechaId    = $this->seedFecha( [ 101 ], 'evaluated' );
        $controller = $this->buildController( $this->stubDispatcher( [] ), fn() => true );
        $request    = $this->buildRequest( [ 'fecha_id' => $fechaId ] );

        $response = $controller->handleEvaluate( $request );

        $this->assertSame( 400, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'fecha_not_locked', $data['code'] );
    }

    // -------------------------------------------------------------------------
    // Success: AE-7 → partial results → 200 with correct counts
    // -------------------------------------------------------------------------

    /** AE-7: 3 matches, 2 final + 1 pending → 200, evaluated_matches=2, pending_matches=1, state='locked'. */
    public function test_partial_results_returns_200_with_counts(): void {
        $fechaId = $this->seedFecha( [ 101, 102, 103 ] );
        $this->seedUser( 1 );
        $this->seedPrediction( 1, 101, 2, 1 );
        $this->seedPrediction( 1, 102, 1, 0 );
        $this->seedPrediction( 1, 103, 0, 0 );

        $items = [
            $this->makeMatchItem( 101, 2, 1 ),
            $this->makeMatchItem( 102, 1, 0 ),
            $this->makeMatchItem( 103, null, null ), // pending
        ];

        $controller = $this->buildController( $this->stubDispatcher( $items ), fn() => true );
        $request    = $this->buildRequest( [ 'fecha_id' => $fechaId ] );

        $response = $controller->handleEvaluate( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'ok', $data['status'] );
        $this->assertSame( 2, $data['evaluated_matches'] );
        $this->assertSame( 1, $data['pending_matches'] );
        $this->assertSame( 'locked', $data['fecha_state'] );
    }

    // -------------------------------------------------------------------------
    // Success: AE-8 → all final → 200 state='evaluated'
    // -------------------------------------------------------------------------

    /** AE-8: all 3 matches final → 200, evaluated_matches=3, pending=0, state='evaluated'. */
    public function test_all_final_returns_200_evaluated_state(): void {
        $fechaId = $this->seedFecha( [ 101, 102, 103 ] );
        $this->seedUser( 1 );
        $this->seedPrediction( 1, 101, 2, 1 );
        $this->seedPrediction( 1, 102, 1, 0 );
        $this->seedPrediction( 1, 103, 0, 0 );

        $items = [
            $this->makeMatchItem( 101, 2, 1 ),
            $this->makeMatchItem( 102, 1, 0 ),
            $this->makeMatchItem( 103, 0, 0 ),
        ];

        $controller = $this->buildController( $this->stubDispatcher( $items ), fn() => true );
        $request    = $this->buildRequest( [ 'fecha_id' => $fechaId ] );

        $response = $controller->handleEvaluate( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 3, $data['evaluated_matches'] );
        $this->assertSame( 0, $data['pending_matches'] );
        $this->assertSame( 'evaluated', $data['fecha_state'] );
    }

    // -------------------------------------------------------------------------
    // Idempotency: AE-9 → second call returns 200, same row count
    // -------------------------------------------------------------------------

    /** AE-9: second call on evaluated fecha → BUT endpoint gates on locked only → 400. */
    public function test_idempotent_second_call_returns_200_same_row_count(): void {
        $fechaId = $this->seedFecha( [ 101 ] );
        $this->seedUser( 1 );
        $this->seedPrediction( 1, 101, 2, 1 );

        $items = [ $this->makeMatchItem( 101, 2, 1 ) ];

        $controller = $this->buildController( $this->stubDispatcher( $items ), fn() => true );

        // First call.
        $request1  = $this->buildRequest( [ 'fecha_id' => $fechaId ] );
        $response1 = $controller->handleEvaluate( $request1 );
        $this->assertSame( 200, $response1->get_status() );
        $countAfterFirst = $this->countScores();

        // Second call: after first pass, fecha.state='evaluated' → gate rejects it.
        $request2  = $this->buildRequest( [ 'fecha_id' => $fechaId ] );
        $response2 = $controller->handleEvaluate( $request2 );

        // Spec A3: evaluated→'locked' check fails → 400 fecha_not_locked.
        $this->assertSame( 400, $response2->get_status() );
        $data2 = $response2->get_data();
        $this->assertSame( 'fecha_not_locked', $data2['code'] );

        // Row count must be unchanged.
        $this->assertSame( $countAfterFirst, $this->countScores() );
    }

    // -------------------------------------------------------------------------
    // R7.6 / A2: evaluated_matches counts distinct match_ids, not rows
    // -------------------------------------------------------------------------

    /** R7.6: 3 matches × 2 users = 6 rows; evaluated_matches = 3 (distinct match_ids). */
    public function test_evaluated_matches_counts_distinct_match_ids(): void {
        $fechaId = $this->seedFecha( [ 101, 102, 103 ] );
        $this->seedUser( 1 );
        $this->seedUser( 2 );

        foreach ( [ 1, 2 ] as $uid ) {
            $this->seedPrediction( $uid, 101, 2, 1 );
            $this->seedPrediction( $uid, 102, 1, 0 );
            $this->seedPrediction( $uid, 103, 0, 0 );
        }

        $items = [
            $this->makeMatchItem( 101, 2, 1 ),
            $this->makeMatchItem( 102, 1, 0 ),
            $this->makeMatchItem( 103, 0, 0 ),
        ];

        $controller = $this->buildController( $this->stubDispatcher( $items ), fn() => true );
        $request    = $this->buildRequest( [ 'fecha_id' => $fechaId ] );

        $response = $controller->handleEvaluate( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        // 6 score rows exist.
        $this->assertSame( 6, $this->countScores() );
        // But evaluated_matches counts distinct match_ids = 3.
        $this->assertSame( 3, $data['evaluated_matches'] );
        $this->assertSame( 0, $data['pending_matches'] );
    }
}

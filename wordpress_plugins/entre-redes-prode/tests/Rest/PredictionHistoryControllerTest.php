<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Rest;

use EntreRedes\Prode\Auth\AuthMiddleware;
use EntreRedes\Prode\Auth\JwtService;
use EntreRedes\Prode\Auth\SessionManager;
use EntreRedes\Prode\Migrations\InitialSchema;
use EntreRedes\Prode\Predictions\PredictionRepository;
use EntreRedes\Prode\Rest\PredictionHistoryController;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for GET /prode/predicciones (PredictionHistoryController).
 *
 * Business-logic tests call getHistory() directly with _prode_user set on the
 * request (simulating requireAuth already passed), mirroring the pattern used
 * by PredictionControllerTest / RankingControllerTest.
 */
class PredictionHistoryControllerTest extends TestCase {

    private PredictionHistoryController $controller;
    private PredictionRepository        $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );

        $this->repo = new PredictionRepository( $wpdb );
        $middleware = new AuthMiddleware( new JwtService(), new SessionManager() );
        $this->controller = new PredictionHistoryController( $this->repo, $middleware );
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

    private function seedFinishedPrediction(
        int $userId,
        int $fechaId,
        int $matchId,
        string $kickoff,
        ?int $points = null
    ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_fechas',
            [
                'id'         => $fechaId,
                'tenant_id'  => 'test_tenant',
                'season_id'  => 359,
                'locked_at'  => '2026-05-30 10:00:00',
                'state'      => 'evaluated',
                'created_at' => '2026-05-28 00:00:00',
            ]
        );
        $wpdb->insert(
            $wpdb->prefix . 'prode_predictions',
            [
                'user_id'            => $userId,
                'fecha_id'           => $fechaId,
                'match_id'           => $matchId,
                'result'             => 'X',
                'score_home'         => 1,
                'score_away'         => 1,
                'created_at'         => '2026-01-01 00:00:00',
                'updated_at'         => '2026-01-01 00:00:00',
                'locked_at_snapshot' => '2026-01-01 00:00:00',
            ]
        );
        $wpdb->insert(
            $wpdb->prefix . 'prode_fecha_matches',
            [
                'fecha_id'        => $fechaId,
                'match_id'        => $matchId,
                'match_kickoff'   => $kickoff,
                'home_team'       => 'Home FC',
                'away_team'       => 'Away FC',
                'zona'            => 'Apertura Zona A',
                'real_score_home' => 1,
                'real_score_away' => 0,
                'is_final'        => 1,
            ]
        );
        if ( null !== $points ) {
            $wpdb->insert(
                $wpdb->prefix . 'prode_scores',
                [
                    'user_id'           => $userId,
                    'fecha_id'          => $fechaId,
                    'match_id'          => $matchId,
                    'prediction_id'     => null,
                    'points'            => $points,
                    'evaluation_method' => 'result_only',
                    'evaluated_at'      => '2026-06-01 00:00:00',
                ]
            );
        }
    }

    private function makeAuthedRequest( int $userId, array $params = [] ): \WP_REST_Request {
        $req = new \WP_REST_Request( 'GET', '' );
        foreach ( $params as $key => $val ) {
            $req->set_param( $key, $val );
        }
        $req->set_param( '_prode_user', [ 'id' => $userId ] );
        return $req;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_returns_200_empty_envelope_when_no_history(): void {
        $response = $this->controller->getHistory( $this->makeAuthedRequest( 1 ) );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( [], $data['items'] );
        $this->assertSame( 0, $data['total'] );
        $this->assertSame( 1, $data['page'] );
        $this->assertSame( 15, $data['per_page'] );
    }

    public function test_returns_finished_predictions_for_caller(): void {
        $this->seedFinishedPrediction( 1, 10, 5, '2026-06-01 18:00:00', 1 );

        $response = $this->controller->getHistory( $this->makeAuthedRequest( 1 ) );
        $data     = $response->get_data();

        $this->assertSame( 1, $data['total'] );
        $this->assertCount( 1, $data['items'] );
        $item = $data['items'][0];
        $this->assertSame( 5, $item['match_id'] );
        $this->assertSame( 359, $item['season_id'] );
        $this->assertSame( 'Apertura Zona A', $item['zona'] );
        $this->assertSame( 1, $item['real_score_home'] );
        $this->assertSame( 0, $item['real_score_away'] );
        $this->assertTrue( $item['is_final'] );
        $this->assertSame( 1, $item['points'] );
        $this->assertSame( 'result_only', $item['evaluation_method'] );
    }

    public function test_default_page_size_is_15(): void {
        for ( $i = 1; $i <= 20; $i++ ) {
            $this->seedFinishedPrediction( 1, 100 + $i, $i, sprintf( '2026-06-%02d 18:00:00', $i ), 1 );
        }

        $response = $this->controller->getHistory( $this->makeAuthedRequest( 1 ) );
        $data     = $response->get_data();

        $this->assertSame( 20, $data['total'] );
        $this->assertCount( 15, $data['items'] );
    }

    public function test_second_page_returns_remaining(): void {
        for ( $i = 1; $i <= 20; $i++ ) {
            $this->seedFinishedPrediction( 1, 100 + $i, $i, sprintf( '2026-06-%02d 18:00:00', $i ), 1 );
        }

        $response = $this->controller->getHistory( $this->makeAuthedRequest( 1, [ 'page' => 2 ] ) );
        $data     = $response->get_data();

        $this->assertSame( 20, $data['total'] );
        $this->assertCount( 5, $data['items'] );
        $this->assertSame( 2, $data['page'] );
    }

    public function test_points_null_when_not_evaluated(): void {
        $this->seedFinishedPrediction( 1, 10, 5, '2026-06-01 18:00:00', null );

        $response = $this->controller->getHistory( $this->makeAuthedRequest( 1 ) );
        $item     = $response->get_data()['items'][0];

        $this->assertNull( $item['points'] );
        $this->assertNull( $item['evaluation_method'] );
    }

    public function test_non_numeric_page_returns_400(): void {
        $response = $this->controller->getHistory( $this->makeAuthedRequest( 1, [ 'page' => 'abc' ] ) );
        $this->assertSame( 400, $response->get_status() );
    }

    public function test_per_page_over_max_returns_400(): void {
        $response = $this->controller->getHistory( $this->makeAuthedRequest( 1, [ 'per_page' => 51 ] ) );
        $this->assertSame( 400, $response->get_status() );
    }

    public function test_page_less_than_1_returns_400(): void {
        $response = $this->controller->getHistory( $this->makeAuthedRequest( 1, [ 'page' => 0 ] ) );
        $this->assertSame( 400, $response->get_status() );
    }

    public function test_per_page_zero_returns_400(): void {
        $response = $this->controller->getHistory( $this->makeAuthedRequest( 1, [ 'per_page' => 0 ] ) );
        $this->assertSame( 400, $response->get_status() );
    }

    public function test_per_page_negative_returns_400(): void {
        $response = $this->controller->getHistory( $this->makeAuthedRequest( 1, [ 'per_page' => -5 ] ) );
        $this->assertSame( 400, $response->get_status() );
    }
}

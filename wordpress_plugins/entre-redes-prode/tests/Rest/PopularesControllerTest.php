<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Rest;

use EntreRedes\Prode\Migrations\InitialSchema;
use EntreRedes\Prode\Predictions\PredictionRepository;
use EntreRedes\Prode\Rest\PopularesController;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for GET /prode/populares (PopularesController).
 *
 * The endpoint is public, so tests call getPopulares() with a bare request —
 * there is no _prode_user to inject.
 */
class PopularesControllerTest extends TestCase {

    private PopularesController  $controller;
    private PredictionRepository $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );

        $this->repo       = new PredictionRepository( $wpdb );
        $this->controller = new PopularesController( $this->repo );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRequest( array $params = [] ): \WP_REST_Request {
        $req = new \WP_REST_Request( 'GET', '' );
        foreach ( $params as $key => $val ) {
            $req->set_param( $key, $val );
        }
        return $req;
    }

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

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function test_missing_match_id_returns_400(): void {
        $response = $this->controller->getPopulares( $this->makeRequest() );

        $this->assertInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 'invalid_match_id', $response->code );
        $this->assertSame( 400, $response->data['status'] );
    }

    public function test_non_positive_match_id_returns_400(): void {
        $response = $this->controller->getPopulares( $this->makeRequest( [ 'match_id' => 0 ] ) );

        $this->assertInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 'invalid_match_id', $response->code );
        $this->assertSame( 400, $response->data['status'] );
    }

    // -------------------------------------------------------------------------
    // Payload
    // -------------------------------------------------------------------------

    public function test_returns_percentages_for_an_evaluated_fecha(): void {
        $this->insertFecha( 10, 'evaluated' );
        $this->insertPrediction( 1, 10, 22862, '1' );
        $this->insertPrediction( 2, 10, 22862, '1' );
        $this->insertPrediction( 3, 10, 22862, 'X' );
        $this->insertPrediction( 4, 10, 22862, '2' );

        $response = $this->controller->getPopulares(
            $this->makeRequest( [ 'match_id' => 22862 ] )
        );
        $data = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 22862, $data['match_id'] );
        $this->assertSame( 4, $data['total'] );
        $this->assertSame( 50.0, $data['populares']['1'] );
        $this->assertSame( 25.0, $data['populares']['X'] );
        $this->assertSame( 25.0, $data['populares']['2'] );
    }

    public function test_locked_fecha_is_published(): void {
        // Only 'open' is gated — once the round locks the split is fair game.
        $this->insertFecha( 11, 'locked' );
        $this->insertPrediction( 1, 11, 500, '2' );

        $data = $this->controller->getPopulares(
            $this->makeRequest( [ 'match_id' => 500 ] )
        )->get_data();

        $this->assertSame( 1, $data['total'] );
        $this->assertSame( 100.0, $data['populares']['2'] );
    }

    public function test_match_without_predictions_returns_null_populares(): void {
        $data = $this->controller->getPopulares(
            $this->makeRequest( [ 'match_id' => 999 ] )
        )->get_data();

        $this->assertSame( 0, $data['total'] );
        $this->assertNull( $data['populares'] );
    }

    // -------------------------------------------------------------------------
    // Gate — the reason this endpoint cannot simply dump the aggregate
    // -------------------------------------------------------------------------

    public function test_open_fecha_withholds_the_split(): void {
        // Publishing the split while the round accepts predictions would let a
        // late voter copy the crowd.
        $this->insertFecha( 12, 'open' );
        $this->insertPrediction( 1, 12, 700, '1' );
        $this->insertPrediction( 2, 12, 700, '1' );

        $data = $this->controller->getPopulares(
            $this->makeRequest( [ 'match_id' => 700 ] )
        )->get_data();

        $this->assertNull( $data['populares'] );
    }

    public function test_open_fecha_also_withholds_the_total(): void {
        // A bare count still leaks how much traffic the round is getting.
        $this->insertFecha( 13, 'open' );
        $this->insertPrediction( 1, 13, 800, 'X' );
        $this->insertPrediction( 2, 13, 800, 'X' );
        $this->insertPrediction( 3, 13, 800, '1' );

        $data = $this->controller->getPopulares(
            $this->makeRequest( [ 'match_id' => 800 ] )
        )->get_data();

        $this->assertSame( 0, $data['total'] );
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Rest;

use EntreRedes\Prode\Auth\AuthMiddleware;
use EntreRedes\Prode\Auth\JwtService;
use EntreRedes\Prode\Auth\SessionManager;
use EntreRedes\Prode\Fecha\FechaRepository;
use EntreRedes\Prode\Migrations\InitialSchema;
use EntreRedes\Prode\Predictions\PredictionRepository;
use EntreRedes\Prode\Rest\PredictionController;
use PHPUnit\Framework\TestCase;

/**
 * Tests for POST /prode/prediccion (PredictionController::submitPrediction).
 *
 * Uses the SQLite shim. Each test seeds a prode_users row (id=1) and a
 * prode_fechas + prode_fecha_matches row to have a valid active fecha.
 *
 * Auth is exercised via real AuthMiddleware → requireAuth. The permission
 * callback runs before submitPrediction, so for auth tests we call
 * requireAuth directly and assert the WP_Error result.
 *
 * For business-logic tests (validation, lock, upsert) we seed _prode_user
 * directly on the request (simulating requireAuth already passed).
 */
class PredictionControllerTest extends TestCase {

    private PredictionRepository $predRepo;
    private FechaRepository      $fechaRepo;
    private AuthMiddleware       $middleware;
    private JwtService           $jwt;
    private SessionManager       $session;

    /** fecha_id seeded by seedActiveFecha() */
    private int $fechaId;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );

        $this->provisionKeys();
        $this->seedTestUser();

        $this->predRepo   = new PredictionRepository( $wpdb );
        $this->fechaRepo  = new FechaRepository( $wpdb );
        $this->jwt        = new JwtService();
        $this->session    = new SessionManager();
        $this->middleware = new AuthMiddleware( $this->jwt, $this->session );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        InitialSchema::up();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function provisionKeys(): void {
        $res = openssl_pkey_new( [ 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
        openssl_pkey_export( $res, $private_pem );
        $details    = openssl_pkey_get_details( $res );
        $public_pem = $details['key'];
        update_option( 'prode_rsa_private_key', $private_pem );
        update_option( 'prode_rsa_public_key', $public_pem );
        update_option( 'prode_rsa_key_id', 'test-kid' );
    }

    private function seedTestUser(): void {
        global $wpdb;
        $wpdb->query(
            "INSERT OR REPLACE INTO {$wpdb->prefix}prode_users
               (id, dni_hash, alias, season_version, session_version, created_at)
             VALUES (1, 'abc', 'tester', 1, 1, '2026-01-01 00:00:00')"
        );
    }

    /**
     * Seed an active fecha with locked_at far in the future and two matches.
     * Returns the fecha_id.
     */
    private function seedActiveFecha( string $lockedAt = '2099-12-31 23:59:00' ): int {
        $this->fechaId = $this->fechaRepo->upsertFecha(
            'test_tenant',
            359,
            $lockedAt,
            [
                [ 'match_id' => 10, 'kickoff' => '2099-12-31 13:45', 'home_team' => 'A', 'away_team' => 'B' ],
                [ 'match_id' => 11, 'kickoff' => '2099-12-31 15:10', 'home_team' => 'C', 'away_team' => 'D' ],
            ]
        );
        return $this->fechaId;
    }

    /**
     * Build a controller under test.
     */
    private function makeController(): PredictionController {
        global $wpdb;
        return new PredictionController( $this->predRepo, $this->fechaRepo, $this->middleware );
    }

    /**
     * Build a WP_REST_Request with body params pre-set, simulating successful
     * requireAuth (i.e., _prode_user attached). Used for business-logic tests.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed>|null $user  null → don't attach _prode_user
     */
    private function makeAuthedRequest( array $body = [], ?array $user = null ): \WP_REST_Request {
        $req = new \WP_REST_Request( 'POST', '' );
        foreach ( $body as $key => $val ) {
            $req->set_param( $key, $val );
        }
        $prodeUser = $user ?? [
            'id'              => 1,
            'session_version' => 1,
        ];
        $req->set_param( '_prode_user', $prodeUser );
        return $req;
    }

    /**
     * Build a request with a real Bearer token (for auth middleware tests).
     */
    private function makeRequestWithToken( string $token ): \WP_REST_Request {
        $req = new \WP_REST_Request( 'POST', '' );
        $req->set_header( 'authorization', "Bearer {$token}" );
        return $req;
    }

    private function makeRequestWithoutToken(): \WP_REST_Request {
        return new \WP_REST_Request( 'POST', '' );
    }

    // -------------------------------------------------------------------------
    // A2-1 RED — Auth enforcement
    // -------------------------------------------------------------------------

    public function test_no_token_returns_401_token_missing(): void {
        $controller = $this->makeController();
        $request    = $this->makeRequestWithoutToken();

        $result = $controller->requireAuth( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'token_missing', $result->code );
        $this->assertSame( 401, $result->data['status'] );
    }

    public function test_invalid_token_returns_401_token_invalid(): void {
        $controller = $this->makeController();
        $request    = $this->makeRequestWithToken( 'not-a-valid-jwt' );

        $result = $controller->requireAuth( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'token_invalid', $result->code );
        $this->assertSame( 401, $result->data['status'] );
    }

    // -------------------------------------------------------------------------
    // A2-2 RED — Validation: 400 for all malformed input
    // -------------------------------------------------------------------------

    public function test_missing_score_away_returns_400_missing_field(): void {
        $this->seedActiveFecha();
        $controller = $this->makeController();
        $request    = $this->makeAuthedRequest( [
            'fecha_id'   => $this->fechaId,
            'match_id'   => 10,
            'score_home' => 1,
            // score_away absent
        ] );

        $response = $controller->submitPrediction( $request );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'missing_field', $response->get_data()['code'] );
    }

    public function test_negative_score_home_returns_400_invalid_score(): void {
        $this->seedActiveFecha();
        $controller = $this->makeController();
        $request    = $this->makeAuthedRequest( [
            'fecha_id'   => $this->fechaId,
            'match_id'   => 10,
            'score_home' => -1,
            'score_away' => 0,
        ] );

        $response = $controller->submitPrediction( $request );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'invalid_score', $response->get_data()['code'] );
    }

    public function test_score_above_255_returns_400_invalid_score(): void {
        $this->seedActiveFecha();
        $controller = $this->makeController();
        $request    = $this->makeAuthedRequest( [
            'fecha_id'   => $this->fechaId,
            'match_id'   => 10,
            'score_home' => 256,
            'score_away' => 0,
        ] );

        $response = $controller->submitPrediction( $request );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'invalid_score', $response->get_data()['code'] );
    }

    public function test_non_integer_score_home_returns_400_invalid_score(): void {
        $this->seedActiveFecha();
        $controller = $this->makeController();
        $request    = $this->makeAuthedRequest( [
            'fecha_id'   => $this->fechaId,
            'match_id'   => 10,
            'score_home' => 'two',
            'score_away' => 0,
        ] );

        $response = $controller->submitPrediction( $request );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'invalid_score', $response->get_data()['code'] );
    }

    public function test_match_id_not_in_active_fecha_returns_400_match_not_found(): void {
        $this->seedActiveFecha();
        $controller = $this->makeController();
        $request    = $this->makeAuthedRequest( [
            'fecha_id'   => $this->fechaId,
            'match_id'   => 999, // not in fecha
            'score_home' => 1,
            'score_away' => 0,
        ] );

        $response = $controller->submitPrediction( $request );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'match_not_found', $response->get_data()['code'] );
    }

    // -------------------------------------------------------------------------
    // A2-3 RED — Lock enforcement and happy-path upsert
    // -------------------------------------------------------------------------

    public function test_submit_after_lock_returns_423_fecha_locked_no_write(): void {
        // locked_at in the past → current_time >= locked_at → locked
        $this->seedActiveFecha( '2000-01-01 00:00:00' );
        $controller = $this->makeController();
        $request    = $this->makeAuthedRequest( [
            'fecha_id'   => $this->fechaId,
            'match_id'   => 10,
            'score_home' => 1,
            'score_away' => 0,
        ] );

        $response = $controller->submitPrediction( $request );

        $this->assertSame( 423, $response->get_status() );
        $this->assertSame( 'fecha_locked', $response->get_data()['code'] );

        // No prediction row should have been written.
        global $wpdb;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}prode_predictions WHERE match_id = 10" );
        $this->assertSame( 0, $count );
    }

    public function test_valid_submit_before_lock_returns_200_and_writes_prediction(): void {
        $this->seedActiveFecha( '2099-12-31 23:59:00' );
        $controller = $this->makeController();
        $request    = $this->makeAuthedRequest( [
            'fecha_id'   => $this->fechaId,
            'match_id'   => 10,
            'score_home' => 2,
            'score_away' => 1,
        ] );

        $response = $controller->submitPrediction( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'ok', $response->get_data()['status'] );

        global $wpdb;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}prode_predictions WHERE match_id = 10 AND user_id = 1" );
        $this->assertSame( 1, $count );
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests;

use EntreRedes\Credencial\Approval\ApprovalRequestRepository;
use EntreRedes\Credencial\Migrations\InitialSchema;
use EntreRedes\Credencial\Observability\InMemoryEventLog;
use EntreRedes\Credencial\Plugin;
use EntreRedes\Credencial\Tests\Support\IssuesProdeTokens;
use PHPUnit\Framework\TestCase;

/**
 * THE WIRING TEST — mirrors entre-redes-cambios's own PluginTest (see that
 * class's docblock: "proves the CABLE, not the pieces"). Every collaborator
 * Plugin::boot() wires here (CredencialAuthorizer, PlayerReader,
 * EntreRedesApiTeamResolver, IssuanceRepository, CredencialService,
 * ApprovalRequestRepository, the Photo\* pipeline) already has its own deep
 * unit coverage elsewhere (CredencialControllerTest, PhotoUploadControllerTest,
 * IssuanceRepositoryTest, etc.); what none of those can see is boot() itself
 * forgetting to register a route, or the REAL, production-constructed
 * handler skipping authorization.
 */
class PluginRestWiringTest extends TestCase {

    use IssuesProdeTokens;

    private string $privateKeyPem;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_issuance" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_request" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_blob" );

        $this->resetPluginBootState();
    }

    protected function tearDown(): void {
        $GLOBALS['wp_test_posts']              = [];
        $GLOBALS['wp_test_postmeta']            = [];
        $GLOBALS['wp_test_post_thumbnail_urls'] = [];
        $GLOBALS['wp_test_post_thumbnail_ids']  = [];

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_issuance" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_request" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_blob" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}prode_users" );
        delete_option( 'prode_rsa_public_key' );

        $this->resetPluginBootState();
    }

    /**
     * Sets up a live prode session for user 42 signing tokens with player_id
     * 777 (IssuesProdeTokens's own defaults) and points
     * `prode_rsa_public_key` at the matching public key, so a real,
     * production-wired CredencialAuthorizer accepts the token this test
     * issues.
     */
    private function seedLiveProdeSession(): void {
        $resource = openssl_pkey_new( [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ] );
        openssl_pkey_export( $resource, $privateKeyPem );
        $this->privateKeyPem = $privateKeyPem;

        update_option( 'prode_rsa_public_key', openssl_pkey_get_details( $resource )['key'] );

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DROP TABLE IF EXISTS {$p}prode_users" );
        $wpdb->query(
            "CREATE TABLE {$p}prode_users (
                id INTEGER PRIMARY KEY,
                session_version INTEGER NOT NULL
            )"
        );
        $wpdb->insert( $p . 'prode_users', [ 'id' => 42, 'session_version' => 3 ] );
    }

    public function test_boot_registers_the_get_credencial_route(): void {
        Plugin::boot();
        do_action( 'rest_api_init' );

        $routes = $GLOBALS['_prode_test_registered_routes'] ?? [];

        $match = array_filter(
            $routes,
            static fn ( array $r ): bool => 'entre-redes/v1' === $r['namespace']
                && '/credencial/credencial' === $r['route']
                && \WP_REST_Server::READABLE === ( $r['args']['methods'] ?? null )
        );

        $this->assertNotEmpty( $match, 'GET /entre-redes/v1/credencial/credencial must be registered.' );
    }

    /**
     * Invokes the REAL, production-wired callback (real CredencialAuthorizer
     * built from real TokenVerifier/ProdeSessionGateway, not a test double)
     * with no Authorization header, and confirms it fails closed with a real
     * prode auth code — proof the wiring itself enforces authorization, not
     * just each piece in isolation.
     */
    public function test_boot_wired_handler_rejects_a_request_with_no_authorization_header(): void {
        Plugin::boot();
        do_action( 'rest_api_init' );

        $callback = $this->findRegisteredCallback( 'entre-redes/v1', '/credencial/credencial', \WP_REST_Server::READABLE );

        $response = $callback( new \WP_REST_Request() );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 401, $response->get_status() );
        $this->assertSame( 'token_missing', $response->get_data()['code'] );
    }

    public function test_boot_registers_the_post_foto_route(): void {
        Plugin::boot();
        do_action( 'rest_api_init' );

        $routes = $GLOBALS['_prode_test_registered_routes'] ?? [];

        $match = array_filter(
            $routes,
            static fn ( array $r ): bool => 'entre-redes/v1' === $r['namespace']
                && '/credencial/foto' === $r['route']
                && \WP_REST_Server::CREATABLE === ( $r['args']['methods'] ?? null )
        );

        $this->assertNotEmpty( $match, 'POST /entre-redes/v1/credencial/foto must be registered.' );
    }

    /**
     * Same proof as test_boot_wired_handler_rejects_a_request_with_no_authorization_header(),
     * for the upload route: the REAL, production-wired PhotoUploadController
     * fails closed with no Authorization header, before ever touching the
     * upload pipeline.
     */
    public function test_boot_wired_upload_handler_rejects_a_request_with_no_authorization_header(): void {
        Plugin::boot();
        do_action( 'rest_api_init' );

        $callback = $this->findRegisteredCallback( 'entre-redes/v1', '/credencial/foto', \WP_REST_Server::CREATABLE );

        $response = $callback( new \WP_REST_Request() );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 401, $response->get_status() );
        $this->assertSame( 'token_missing', $response->get_data()['code'] );
    }

    /**
     * The end-to-end proof for engram 1589 (`credencial/photo-request-sin-cablear`):
     * exercises the REAL, production-wired GET callback — real
     * CredencialAuthorizer, real CredencialService, real
     * Approval\ApprovalRequestRepository, all constructed exactly as
     * Plugin::boot() constructs them, not test doubles standing in for the
     * wiring itself. A unit test on CredencialService alone (see
     * CredencialServiceTest) cannot catch "the repository exists but nobody
     * passed it to the service" — this test is the one that can.
     */
    public function test_boot_wired_get_handler_reports_a_pending_photo_request(): void {
        $this->seedLiveProdeSession();

        $GLOBALS['wp_test_posts'][777] = [
            'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => 'Jugador 777', 'post_date' => '2000-01-01 00:00:00',
        ];
        $GLOBALS['wp_test_post_thumbnail_urls'][777] = 'https://example.com/photo.jpg';
        $GLOBALS['wp_test_post_thumbnail_ids'][777]  = 55;

        // CredencialController's clockFn defaults to the REAL time() when
        // wired by Plugin::boot() (no fake clock injected in production) —
        // so both the pending request's timestamp and the token's iat/exp
        // are anchored to the actual "now", not a fixed fixture date.
        $now = time();

        global $wpdb;
        $requestId = ( new ApprovalRequestRepository( $wpdb, new InMemoryEventLog() ) )
            ->createPendingPhotoRequest( 777, 42, 'bytes', $now );

        Plugin::boot();
        do_action( 'rest_api_init' );

        $callback = $this->findRegisteredCallback( 'entre-redes/v1', '/credencial/credencial', \WP_REST_Server::READABLE );

        $request = new \WP_REST_Request();
        $request->set_header(
            'authorization',
            'Bearer ' . $this->issueToken( [ 'iat' => $now - 60, 'exp' => $now + 900 ] )
        );

        $response = $callback( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'active', $data['state'] );
        $this->assertSame(
            [ 'id' => $requestId, 'status' => 'pending', 'created_at' => gmdate( 'Y-m-d H:i:s', $now ) ],
            $data['photo_request'],
            'the real, production-wired GET handler must report the pending photo request — this is the exact gap engram 1589 found.'
        );
    }

    private function findRegisteredCallback( string $namespace, string $route, string $method ): callable {
        foreach ( $GLOBALS['_prode_test_registered_routes'] ?? [] as $registered ) {
            if ( $registered['namespace'] === $namespace
                && $registered['route'] === $route
                && ( $registered['args']['methods'] ?? null ) === $method
            ) {
                return $registered['args']['callback'];
            }
        }

        $this->fail( "Route {$method} {$namespace}{$route} was not registered — cannot fetch its callback." );
    }

    /**
     * Same rationale as entre-redes-cambios's PluginTest::resetPluginBootState():
     * Plugin::boot() is idempotent by a private static flag; resetting it via
     * Reflection makes this suite's own boot() call deterministic regardless
     * of test execution order.
     */
    private function resetPluginBootState(): void {
        $property = new \ReflectionProperty( Plugin::class, 'booted' );
        $property->setValue( null, false );

        unset(
            $GLOBALS['_prode_test_registered_routes'],
            $GLOBALS['_prode_test_action_callbacks']['rest_api_init'],
            $GLOBALS['_prode_test_action_registrations']['rest_api_init']
        );
    }
}

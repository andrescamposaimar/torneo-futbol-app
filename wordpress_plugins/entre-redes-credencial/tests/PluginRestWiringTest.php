<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests;

use EntreRedes\Credencial\Migrations\InitialSchema;
use EntreRedes\Credencial\Plugin;
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

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_issuance" );

        $this->resetPluginBootState();
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_issuance" );

        $this->resetPluginBootState();
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

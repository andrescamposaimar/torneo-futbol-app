<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests;

use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * THE WIRING TEST — see this plugin's slice 4d task brief, point 4: this
 * suite proves the CABLE, not the pieces. Every collaborator Plugin::boot()
 * wires (CapitanAuthorizer, DictamenPipeline, SolicitudRepository,
 * PlazaRepository, ...) already has its own deep unit/integration coverage
 * elsewhere; what NONE of those suites can catch is boot() itself forgetting
 * to register a route, or a handler that (accidentally) skips authorization
 * — exactly the shape of bug this plugin's own EstadoDeriver incident was:
 * a correct, tested piece that nothing production ever actually called.
 *
 * Rest\SolicitudesControllerTest / Rest\PlazasControllerTest already prove,
 * with mocked repository doubles that fail the test if invoked, that EACH
 * controller's authorization check runs before any repository access. This
 * suite complements that with the one thing those unit tests cannot see:
 * that Plugin::boot() actually WIRES those controllers into real routes, and
 * that the REAL, production-constructed handler (not a hand-assembled test
 * double) still enforces that same authorization-first contract end to end.
 */
class PluginTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha', 'cambios_capitan' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }

        $this->resetPluginBootState();
    }

    protected function tearDown(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha', 'cambios_capitan' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }

        $this->resetPluginBootState();
    }

    public function test_boot_registers_the_three_captain_endpoints(): void {
        Plugin::boot();
        do_action( 'rest_api_init' );

        $routes = $GLOBALS['_prode_test_registered_routes'] ?? [];

        $this->assertRouteRegistered( $routes, 'entre-redes/v1', '/cambios/solicitudes', \WP_REST_Server::CREATABLE );
        $this->assertRouteRegistered( $routes, 'entre-redes/v1', '/cambios/solicitudes', \WP_REST_Server::READABLE );
        $this->assertRouteRegistered( $routes, 'entre-redes/v1', '/cambios/plazas', \WP_REST_Server::READABLE );
    }

    /**
     * Retrieves the REAL callback boot() wired for POST /cambios/solicitudes
     * — a callable bound to the ACTUAL production CapitanAuthorizer /
     * SolicitudRepository / DictamenPipeline instances, none of them test
     * doubles — and invokes it directly with a request that carries no
     * Authorization header at all.
     *
     * `cambios_solicitud` is asserted empty BEFORE and AFTER the call: since
     * boot() hardcodes its own construction (by design — manual injection,
     * no container, mirroring entre-redes-prode), there is no seam here to
     * hand it a spy repository the way the unit tests do; asserting the
     * REAL table never gained a row is the equivalent guarantee against the
     * REAL wiring — proof that authorization failing really did stop the
     * request before SolicitudRepository::crear() ever ran, not just that a
     * mock would have caught it.
     */
    public function test_boot_wired_handler_rejects_an_unauthenticated_request_without_touching_the_repository(): void {
        Plugin::boot();
        do_action( 'rest_api_init' );

        global $wpdb;
        $countBefore = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_solicitud" );
        $this->assertSame( 0, $countBefore );

        $callback = $this->findRegisteredCallback( 'entre-redes/v1', '/cambios/solicitudes', \WP_REST_Server::CREATABLE );

        $request = new \WP_REST_Request();
        // Deliberately no Authorization header — TokenVerifier receives an
        // empty string and rejects it as malformed, exactly like any other
        // bad token (see Capitania\Exception\InvalidTokenException).
        $request->set_param( 'season_id', 359 );
        $request->set_param( 'team_id', 100 );
        $request->set_param( 'plaza_id', 1 );
        $request->set_param( 'tipo', 'sustitucion' );
        $request->set_param( 'entrante_player_id', 888 );
        $request->set_param( 'fecha_id', 5 );

        $response = $callback( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 403, $response->get_status() );
        $this->assertSame( 'no_autorizado', $response->get_data()['code'] );

        $countAfter = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_solicitud" );
        $this->assertSame( 0, $countAfter, 'SolicitudRepository::crear() must never run when authorization fails.' );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** @param array<int, array{namespace: string, route: string, args: array<string, mixed>}> $routes */
    private function assertRouteRegistered( array $routes, string $namespace, string $route, string $method ): void {
        foreach ( $routes as $registered ) {
            if ( $registered['namespace'] === $namespace
                && $registered['route'] === $route
                && ( $registered['args']['methods'] ?? null ) === $method
            ) {
                $this->assertIsCallable( $registered['args']['callback'] );

                return;
            }
        }

        $this->fail( "Expected route {$method} {$namespace}{$route} to be registered, but it was not." );
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
     * Plugin::boot() is idempotent by design (guarded by a private static
     * flag) — correct in production (called once per request on
     * `plugins_loaded`), but it means a SECOND call within the same PHPUnit
     * process (any other test file that happens to run first and also boots
     * the plugin) would silently no-op here. Resetting the flag via
     * reflection makes this suite's own boot() call deterministic regardless
     * of test execution order — the same reason CapitanAuthorizerTest resets
     * its own fixture tables in setUp() rather than trusting run order.
     */
    private function resetPluginBootState(): void {
        // No setAccessible() call — PHP 8.1+ Reflection already grants access
        // to private members without it, and calling it is deprecated as of
        // PHP 8.5 (a no-op since 8.1) — this plugin's own composer.json
        // targets php ">=8.0", and this suite must run with zero
        // deprecations (see task brief's verification step).
        $property = new \ReflectionProperty( Plugin::class, 'booted' );
        $property->setValue( null, false );

        unset(
            $GLOBALS['_prode_test_registered_routes'],
            $GLOBALS['_prode_test_action_callbacks']['rest_api_init'],
            $GLOBALS['_prode_test_action_registrations']['rest_api_init']
        );
    }
}

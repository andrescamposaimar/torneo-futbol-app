<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Rest;

use EntreRedes\Cambios\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Guards Plugin::denyCambiosResponseCaching — see that method's own docblock
 * for the two production incidents (2026-09 prode, 2026-10 cambios) the
 * pattern defends against. Copied and adapted from
 * entre-redes-credencial/tests/Rest/CredencialResponseCachingTest.php.
 *
 * Every /cambios/ response is scoped to ONE captain's season/team, so a
 * shared cache keyed by URL alone can hand one captain's roster and pending
 * requests to another — and hand an authenticated payload to a request
 * carrying no credentials at all.
 */
class CambiosResponseCachingTest extends TestCase {

    private function request( string $route ): \WP_REST_Request {
        $request = new \WP_REST_Request();
        $request->set_route( $route );

        return $request;
    }

    public function test_cambios_route_response_is_marked_uncacheable(): void {
        $response = new \WP_REST_Response( [ 'solicitudes' => [] ], 200 );

        $result = Plugin::denyCambiosResponseCaching(
            $response,
            new \WP_REST_Server(),
            $this->request( '/entre-redes/v1/cambios/solicitudes' )
        );

        $headers = $result->get_headers();

        $this->assertSame(
            'no-store, no-cache, must-revalidate, max-age=0, private',
            $headers['Cache-Control'] ?? null,
            'A /cambios/ response must forbid shared-cache storage.'
        );
        $this->assertSame( 'no-cache', $headers['Pragma'] ?? null );
        $this->assertStringContainsString( 'Authorization', $headers['Vary'] ?? '' );
    }

    /**
     * Every route this plugin registers, listed explicitly rather than
     * derived: a new endpoint added without a line here is exactly the gap
     * that left /cambios/ uncovered while /prode/ and /credencial/ were
     * already protected.
     */
    public function test_every_cambios_route_is_covered(): void {
        $routes = [
            '/entre-redes/v1/cambios/solicitudes',
            '/entre-redes/v1/cambios/solicitudes/12/cancelar',
            '/entre-redes/v1/cambios/plazas',
            '/entre-redes/v1/cambios/plazas/candidatos',
            '/entre-redes/v1/cambios/fecha-abierta',
            '/entre-redes/v1/cambios/mis-equipos',
        ];

        foreach ( $routes as $route ) {
            $result = Plugin::denyCambiosResponseCaching(
                new \WP_REST_Response( [], 200 ),
                new \WP_REST_Server(),
                $this->request( $route )
            );

            $this->assertArrayHasKey(
                'Cache-Control',
                $result->get_headers(),
                "Route {$route} must be marked uncacheable."
            );
        }
    }

    public function test_non_cambios_route_response_is_left_cacheable(): void {
        $result = Plugin::denyCambiosResponseCaching(
            new \WP_REST_Response( [ 'ok' => true ], 200 ),
            new \WP_REST_Server(),
            $this->request( '/entre-redes/v1/temporadas' )
        );

        $this->assertSame(
            [],
            $result->get_headers(),
            'Public endpoints must stay cacheable — the filter is scoped to /cambios/.'
        );
    }

    /**
     * A route merely CONTAINING the base must not be caught: the guard is a
     * prefix test, not a substring test.
     */
    public function test_unrelated_route_mentioning_cambios_is_left_cacheable(): void {
        $result = Plugin::denyCambiosResponseCaching(
            new \WP_REST_Response( [], 200 ),
            new \WP_REST_Server(),
            $this->request( '/otro-plugin/v1/cambios/cosas' )
        );

        $this->assertSame( [], $result->get_headers() );
    }

    public function test_vary_appends_instead_of_replacing(): void {
        $response = new \WP_REST_Response( [], 200 );
        $response->header( 'Vary', 'Origin' );

        $result = Plugin::denyCambiosResponseCaching(
            $response,
            new \WP_REST_Server(),
            $this->request( '/entre-redes/v1/cambios/solicitudes' )
        );

        $this->assertSame( 'Origin, Authorization', $result->get_headers()['Vary'] );
    }

    public function test_unexpected_response_shape_passes_through_untouched(): void {
        $notAResponse = new \stdClass();

        $this->assertSame(
            $notAResponse,
            Plugin::denyCambiosResponseCaching(
                $notAResponse,
                new \WP_REST_Server(),
                $this->request( '/entre-redes/v1/cambios/solicitudes' )
            )
        );
    }

    public function test_filter_is_registered_on_rest_post_dispatch(): void {
        $source = (string) file_get_contents( __DIR__ . '/../../src/Plugin.php' );

        $this->assertMatchesRegularExpression(
            "/add_filter\(\s*'rest_post_dispatch',\s*\[\s*self::class,\s*'denyCambiosResponseCaching'\s*\]/",
            $source,
            'Plugin::boot() must hook denyCambiosResponseCaching onto rest_post_dispatch.'
        );
    }
}

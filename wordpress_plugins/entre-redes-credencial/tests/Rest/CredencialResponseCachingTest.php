<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Rest;

use EntreRedes\Credencial\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Guards Plugin::denyCredencialResponseCaching — design D2 ("rest_post_dispatch
 * sets no-store, private, Vary: Authorization on /credencial/*"). Copied and
 * adapted from entre-redes-prode/tests/Rest/ProdeResponseCachingTest.php: the
 * incident that pattern defends against (a reverse proxy caching a
 * Bearer-authenticated response keyed by URL alone) applies identically here
 * — every /credencial/ response is caller-specific (photo, DNI, rotating
 * code seed).
 */
class CredencialResponseCachingTest extends TestCase {

    private function request( string $route ): \WP_REST_Request {
        $request = new \WP_REST_Request();
        $request->set_route( $route );

        return $request;
    }

    public function test_credencial_route_response_is_marked_uncacheable(): void {
        $response = new \WP_REST_Response( [ 'state' => 'active' ], 200 );

        $result = Plugin::denyCredencialResponseCaching(
            $response,
            new \WP_REST_Server(),
            $this->request( '/entre-redes/v1/credencial/credencial' )
        );

        $headers = $result->get_headers();

        $this->assertSame(
            'no-store, no-cache, must-revalidate, max-age=0, private',
            $headers['Cache-Control'] ?? null,
            'A /credencial/ response must forbid shared-cache storage.'
        );
        $this->assertSame( 'no-cache', $headers['Pragma'] ?? null );
        $this->assertStringContainsString( 'Authorization', $headers['Vary'] ?? '' );
    }

    public function test_every_credencial_route_prefix_is_covered(): void {
        $routes = [
            '/entre-redes/v1/credencial/credencial',
            '/entre-redes/v1/credencial/credencial/foto',
        ];

        foreach ( $routes as $route ) {
            $result = Plugin::denyCredencialResponseCaching(
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

    public function test_non_credencial_route_response_is_left_cacheable(): void {
        $result = Plugin::denyCredencialResponseCaching(
            new \WP_REST_Response( [ 'ok' => true ], 200 ),
            new \WP_REST_Server(),
            $this->request( '/entre-redes/v1/healthcheck' )
        );

        $this->assertSame(
            [],
            $result->get_headers(),
            'Public endpoints must stay cacheable — the filter is scoped to /credencial/.'
        );
    }

    public function test_vary_appends_instead_of_replacing(): void {
        $response = new \WP_REST_Response( [], 200 );
        $response->header( 'Vary', 'Origin' );

        $result = Plugin::denyCredencialResponseCaching(
            $response,
            new \WP_REST_Server(),
            $this->request( '/entre-redes/v1/credencial/credencial' )
        );

        $this->assertSame( 'Origin, Authorization', $result->get_headers()['Vary'] );
    }

    public function test_unexpected_response_shape_passes_through_untouched(): void {
        $notAResponse = new \stdClass();

        $this->assertSame(
            $notAResponse,
            Plugin::denyCredencialResponseCaching(
                $notAResponse,
                new \WP_REST_Server(),
                $this->request( '/entre-redes/v1/credencial/credencial' )
            )
        );
    }

    public function test_filter_is_registered_on_rest_post_dispatch(): void {
        $source = (string) file_get_contents( __DIR__ . '/../../src/Plugin.php' );

        $this->assertMatchesRegularExpression(
            "/add_filter\(\s*'rest_post_dispatch',\s*\[\s*self::class,\s*'denyCredencialResponseCaching'\s*\]/",
            $source,
            'Plugin::boot() must hook denyCredencialResponseCaching onto rest_post_dispatch.'
        );
    }
}

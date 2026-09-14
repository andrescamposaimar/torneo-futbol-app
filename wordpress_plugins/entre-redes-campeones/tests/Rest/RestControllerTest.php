<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Rest;

use EntreRedes\Campeones\Migrations\InitialSchema;
use EntreRedes\Campeones\Rest\HistoryController;
use EntreRedes\Campeones\Rest\PlayerTitlesController;
use EntreRedes\Campeones\Rest\RestController;
use EntreRedes\Campeones\Tests\Linking\FakePlayerDirectory;
use EntreRedes\Campeones\Titles\SquadRepository;
use EntreRedes\Campeones\Titles\TitleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Wiring test for RestController — the nullable-slot aggregator pattern
 * (design §7, the entre-redes-prode RestController precedent).
 *
 * Item 4 (CRITICAL): nothing in this suite previously verified the
 * namespace, the route path, the HTTP method, or that permission_callback
 * is __return_true for either public route — the shim's
 * register_rest_route() discarded every argument, so there was nothing to
 * assert against. It now captures [$namespace, $route, $args] into
 * $GLOBALS['_campeones_test_registered_rest_routes'] (tests/wp-shim.php),
 * mirroring add_action()'s callback capture, so both routes can be
 * asserted directly instead of merely "register_routes() ran without
 * throwing".
 */
class RestControllerTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();
        $GLOBALS['_campeones_test_registered_rest_routes'] = [];
    }

    protected function tearDown(): void {
        $GLOBALS['_campeones_test_registered_rest_routes'] = [];
    }

    public function test_register_routes_runs_without_error_with_both_controllers_present(): void {
        global $wpdb;
        $titles    = new TitleRepository( $wpdb );
        $squads    = new SquadRepository( $wpdb );
        $photos    = new FakePlayerPhotoProvider();
        $directory = new FakePlayerDirectory( [] );

        $controller = new RestController(
            new HistoryController( $titles, $squads, $photos ),
            new PlayerTitlesController( $squads, $directory )
        );

        $controller->register_routes();
        $this->assertTrue( true );
    }

    public function test_register_routes_runs_without_error_with_no_controllers(): void {
        ( new RestController() )->register_routes();
        $this->assertTrue( true );
    }

    public function test_register_routes_registers_both_public_get_routes_with_no_auth(): void {
        global $wpdb;
        $titles    = new TitleRepository( $wpdb );
        $squads    = new SquadRepository( $wpdb );
        $photos    = new FakePlayerPhotoProvider();
        $directory = new FakePlayerDirectory( [] );

        $controller = new RestController(
            new HistoryController( $titles, $squads, $photos ),
            new PlayerTitlesController( $squads, $directory )
        );

        $controller->register_routes();

        $routes = $GLOBALS['_campeones_test_registered_rest_routes'];
        $this->assertCount( 2, $routes, 'Both controllers must each register exactly one route.' );

        $byRoute = array_column( $routes, null, 'route' );

        $this->assertArrayHasKey( '/campeones/historia', $byRoute, 'HistoryController must register /campeones/historia.' );
        $this->assertSame( 'entre-redes/v1', $byRoute['/campeones/historia']['namespace'] );
        $this->assertSame( \WP_REST_Server::READABLE, $byRoute['/campeones/historia']['args']['methods'] );
        $this->assertSame( '__return_true', $byRoute['/campeones/historia']['args']['permission_callback'] );

        $playerTitlesRoute = '/campeones/jugador/(?P<id>\d+)/titulos';
        $this->assertArrayHasKey( $playerTitlesRoute, $byRoute, 'PlayerTitlesController must register /campeones/jugador/{id}/titulos.' );
        $this->assertSame( 'entre-redes/v1', $byRoute[ $playerTitlesRoute ]['namespace'] );
        $this->assertSame( \WP_REST_Server::READABLE, $byRoute[ $playerTitlesRoute ]['args']['methods'] );
        $this->assertSame( '__return_true', $byRoute[ $playerTitlesRoute ]['args']['permission_callback'] );
    }
}

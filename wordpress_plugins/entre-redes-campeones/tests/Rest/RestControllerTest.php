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
 * (design §7, the entre-redes-prode RestController precedent). The shim's
 * register_rest_route() is a no-op, so this only proves register_routes()
 * completes without throwing, exactly like AdminMenuWiringTest.
 */
class RestControllerTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();
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
}

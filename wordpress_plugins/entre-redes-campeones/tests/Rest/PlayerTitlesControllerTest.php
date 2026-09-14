<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Rest;

use EntreRedes\Campeones\Migrations\InitialSchema;
use EntreRedes\Campeones\Rest\PlayerTitlesController;
use EntreRedes\Campeones\Titles\SquadEntry;
use EntreRedes\Campeones\Titles\SquadRepository;
use EntreRedes\Campeones\Titles\TitleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PlayerTitlesController — API-2 and API-4 (a player with zero
 * titles gets HTTP 200 with an empty result, never a 404 or error).
 */
class PlayerTitlesControllerTest extends TestCase {

    private TitleRepository $titles;
    private SquadRepository $squads;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );

        $this->titles = new TitleRepository( $wpdb );
        $this->squads = new SquadRepository( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );
        delete_transient( 'campeones_titulos_jugador_v1_5078' );
        delete_transient( 'campeones_titulos_jugador_v1_999999' );
    }

    private function request( int $jugadorId ): \WP_REST_Request {
        $request = new \WP_REST_Request();
        $request->set_param( 'id', (string) $jugadorId );
        return $request;
    }

    public function test_a_player_with_titles_gets_them_ordered_anio_descending(): void {
        $t2016 = $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $t2023 = $this->titles->createOrConflict( 2023, 'A', 'campeon', 'LIVERPOOL' );
        $this->squads->insert( new SquadEntry( $t2016->id, 0, 'BASSO, A.', true, 'auto', 5078 ) );
        $this->squads->insert( new SquadEntry( $t2023->id, 0, 'BASSO, A.', false, 'auto', 5078 ) );

        $controller = new PlayerTitlesController( $this->squads );
        $response   = $controller->handle( $this->request( 5078 ) );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 5078, $data['jugador_id'] );
        $this->assertSame( 2, $data['total'] );
        $this->assertSame( [ 2023, 2016 ], array_column( $data['titulos'], 'anio' ) );
        $this->assertTrue( $data['titulos'][1]['es_capitan'] );
        $this->assertFalse( $data['titulos'][0]['es_capitan'] );
    }

    public function test_a_player_with_zero_titles_returns_200_with_an_empty_result(): void {
        $controller = new PlayerTitlesController( $this->squads );
        $response   = $controller->handle( $this->request( 999999 ) );

        $this->assertSame( 200, $response->get_status(), 'API-4: zero titles must be HTTP 200, never a 404.' );
        $data = $response->get_data();
        $this->assertSame( 0, $data['total'] );
        $this->assertSame( [], $data['titulos'] );
    }

    public function test_titles_never_carry_a_photo_field(): void {
        $title = $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $this->squads->insert( new SquadEntry( $title->id, 0, 'BASSO, A.', false, 'auto', 5078 ) );

        $controller = new PlayerTitlesController( $this->squads );
        $response   = $controller->handle( $this->request( 5078 ) );

        $this->assertArrayNotHasKey( 'foto_url', $response->get_data()['titulos'][0] );
    }

    public function test_a_second_call_is_served_from_the_transient(): void {
        $title = $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $this->squads->insert( new SquadEntry( $title->id, 0, 'BASSO, A.', false, 'auto', 5078 ) );

        $controller = new PlayerTitlesController( $this->squads );
        $first      = $controller->handle( $this->request( 5078 ) );

        // A title added after the first call must not appear in a second
        // call served from cache.
        $t2 = $this->titles->createOrConflict( 2020, 'A', 'campeon', 'BOCA' );
        $this->squads->insert( new SquadEntry( $t2->id, 0, 'BASSO, A.', false, 'auto', 5078 ) );

        $second = $controller->handle( $this->request( 5078 ) );

        $this->assertSame( $first->get_data(), $second->get_data() );
        $this->assertSame( 1, $second->get_data()['total'] );
    }

    public function test_different_players_are_cached_under_different_keys(): void {
        $title = $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $this->squads->insert( new SquadEntry( $title->id, 0, 'BASSO, A.', false, 'auto', 5078 ) );
        $this->squads->insert( new SquadEntry( $title->id, 1, 'MAZZARA, M.', false, 'auto', 111 ) );

        $controller = new PlayerTitlesController( $this->squads );
        $a          = $controller->handle( $this->request( 5078 ) );
        $b          = $controller->handle( $this->request( 111 ) );

        $this->assertSame( 5078, $a->get_data()['jugador_id'] );
        $this->assertSame( 111, $b->get_data()['jugador_id'] );

        delete_transient( 'campeones_titulos_jugador_v1_111' );
    }
}

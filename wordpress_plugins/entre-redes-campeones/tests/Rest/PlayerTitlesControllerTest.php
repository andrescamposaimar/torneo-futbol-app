<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Rest;

use EntreRedes\Campeones\Linking\PlayerDirectoryInterface;
use EntreRedes\Campeones\Migrations\InitialSchema;
use EntreRedes\Campeones\Rest\PlayerTitlesController;
use EntreRedes\Campeones\Tests\Linking\FakePlayerDirectory;
use EntreRedes\Campeones\Tests\Support\ThrowingPlayerDirectory;
use EntreRedes\Campeones\Titles\SquadEntry;
use EntreRedes\Campeones\Titles\SquadRepository;
use EntreRedes\Campeones\Titles\TitleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PlayerTitlesController — API-2 and API-4 (a player with zero
 * titles gets HTTP 200 with an empty result, never a 404 or error), plus
 * item 1's bounded-cache-growth fix: an id with no matching registered
 * player must never get a transient written for it.
 */
class PlayerTitlesControllerTest extends TestCase {

    private TitleRepository $titles;
    private SquadRepository $squads;
    private PlayerDirectoryInterface $directory;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );

        $this->titles = new TitleRepository( $wpdb );
        $this->squads = new SquadRepository( $wpdb );

        $rows            = require __DIR__ . '/../Fixtures/players.php';
        $this->directory = FakePlayerDirectory::fromFixtureRows( $rows );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );
        delete_transient( 'campeones_titulos_jugador_v1_5078' );
        delete_transient( 'campeones_titulos_jugador_v1_999999' );
        $GLOBALS['_campeones_test_force_transient_write_failure'] = false;
        $GLOBALS['_campeones_test_error_log'] = [];
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

        $controller = new PlayerTitlesController( $this->squads, $this->directory );
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
        $controller = new PlayerTitlesController( $this->squads, $this->directory );
        $response   = $controller->handle( $this->request( 999999 ) );

        $this->assertSame( 200, $response->get_status(), 'API-4: zero titles must be HTTP 200, never a 404.' );
        $data = $response->get_data();
        $this->assertSame( 0, $data['total'] );
        $this->assertSame( [], $data['titulos'] );
    }

    public function test_titles_never_carry_a_photo_field(): void {
        $title = $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $this->squads->insert( new SquadEntry( $title->id, 0, 'BASSO, A.', false, 'auto', 5078 ) );

        $controller = new PlayerTitlesController( $this->squads, $this->directory );
        $response   = $controller->handle( $this->request( 5078 ) );

        $this->assertArrayNotHasKey( 'foto_url', $response->get_data()['titulos'][0] );
    }

    public function test_a_second_call_is_served_from_the_transient(): void {
        $title = $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $this->squads->insert( new SquadEntry( $title->id, 0, 'BASSO, A.', false, 'auto', 5078 ) );

        $controller = new PlayerTitlesController( $this->squads, $this->directory );
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
        // 4739 ("Mazzara, Mauro") is a real, registered id in the fixture
        // directory — using a genuine id here (rather than an arbitrary
        // made-up one) is what makes this test actually exercise the
        // per-player caching path post item-1 fix.
        $title = $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $this->squads->insert( new SquadEntry( $title->id, 0, 'BASSO, A.', false, 'auto', 5078 ) );
        $this->squads->insert( new SquadEntry( $title->id, 1, 'MAZZARA, M.', false, 'auto', 4739 ) );

        $controller = new PlayerTitlesController( $this->squads, $this->directory );
        $a          = $controller->handle( $this->request( 5078 ) );
        $b          = $controller->handle( $this->request( 4739 ) );

        $this->assertSame( 5078, $a->get_data()['jugador_id'] );
        $this->assertSame( 1, $a->get_data()['total'] );
        $this->assertSame( 4739, $b->get_data()['jugador_id'] );
        $this->assertSame( 1, $b->get_data()['total'] );

        delete_transient( 'campeones_titulos_jugador_v1_4739' );
    }

    // -------------------------------------------------------------------------
    // Item 1 (CRITICAL) — an unauthenticated caller walking every integer id
    // must never be able to grow wp_options without bound. An id with no
    // matching registered player must never get a transient written for it.
    // -------------------------------------------------------------------------

    public function test_an_id_with_no_matching_player_never_writes_a_transient(): void {
        $controller = new PlayerTitlesController( $this->squads, $this->directory );
        $response   = $controller->handle( $this->request( 999999 ) );

        $this->assertSame( 200, $response->get_status(), 'API-4: an unknown id must still be HTTP 200.' );
        $this->assertSame( 0, $response->get_data()['total'] );
        $this->assertFalse(
            get_transient( 'campeones_titulos_jugador_v1_999999' ),
            'An id with no matching registered player must never have a cache entry written — otherwise an anonymous caller walking every integer id grows wp_options without bound.'
        );
    }

    public function test_a_real_player_with_zero_titles_is_still_cached(): void {
        // The fix must not throw the baby out with the bathwater: a REAL
        // registered player who simply has no linked titles yet is a
        // legitimate, cacheable zero-title response — only ids that cannot
        // correspond to any player at all must be excluded from caching.
        $controller = new PlayerTitlesController( $this->squads, $this->directory );
        $response   = $controller->handle( $this->request( 5078 ) );

        $this->assertSame( 0, $response->get_data()['total'] );
        $this->assertNotFalse(
            get_transient( 'campeones_titulos_jugador_v1_5078' ),
            'A real registered player with zero titles is still a legitimate, cacheable response.'
        );
    }

    public function test_a_directory_query_failure_is_treated_as_no_match_and_never_cached(): void {
        // A directory outage must not turn this public, unauthenticated
        // endpoint into a fatal error (mirrors
        // TitleEditorPage::resolvePlayerNames()'s degrade-gracefully
        // precedent) — nor must it accidentally cache under uncertain
        // existence.
        $controller = new PlayerTitlesController( $this->squads, new ThrowingPlayerDirectory() );
        $response   = $controller->handle( $this->request( 5078 ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 0, $response->get_data()['total'] );
        $this->assertFalse(
            get_transient( 'campeones_titulos_jugador_v1_5078' ),
            'A directory query failure must not result in a cached response.'
        );
    }

    // -------------------------------------------------------------------------
    // Item 3 (CRITICAL) — set_transient() returning false went unchecked.
    // The response must still be correct, but the failure must be logged.
    // -------------------------------------------------------------------------

    public function test_a_failed_cache_write_still_returns_the_correct_payload(): void {
        $title = $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $this->squads->insert( new SquadEntry( $title->id, 0, 'BASSO, A.', false, 'auto', 5078 ) );

        $GLOBALS['_campeones_test_force_transient_write_failure'] = true;

        $controller = new PlayerTitlesController( $this->squads, $this->directory );
        $response   = $controller->handle( $this->request( 5078 ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 1, $response->get_data()['total'], 'A failed cache write must not affect the response payload.' );
    }

    public function test_a_failed_cache_write_is_logged(): void {
        $title = $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $this->squads->insert( new SquadEntry( $title->id, 0, 'BASSO, A.', false, 'auto', 5078 ) );

        $GLOBALS['_campeones_test_force_transient_write_failure'] = true;
        $GLOBALS['_campeones_test_error_log'] = [];

        $controller = new PlayerTitlesController( $this->squads, $this->directory );
        $controller->handle( $this->request( 5078 ) );

        $this->assertNotEmpty(
            $GLOBALS['_campeones_test_error_log'],
            'A failed cache write must be logged, distinguishing it from a plain cache miss.'
        );
    }
}

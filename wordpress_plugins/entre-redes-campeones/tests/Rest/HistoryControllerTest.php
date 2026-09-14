<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Rest;

use EntreRedes\Campeones\Migrations\InitialSchema;
use EntreRedes\Campeones\Rest\HistoryController;
use EntreRedes\Campeones\Titles\SquadEntry;
use EntreRedes\Campeones\Titles\SquadRepository;
use EntreRedes\Campeones\Titles\TitleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for HistoryController — API-1, API-3, API-4-adjacent ordering, and
 * the round-7 batched photo lookup (ADR-C2). No live HTTP dispatch: the
 * shim's register_rest_route() is a no-op, so handle() is called directly
 * with a scripted WP_REST_Request, exactly like the rest of this plugin's
 * admin tests exercise handlers directly.
 */
class HistoryControllerTest extends TestCase {

    private TitleRepository $titles;
    private SquadRepository $squads;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );

        $this->titles = new TitleRepository( $wpdb );
        $this->squads = new SquadRepository( $wpdb );

        delete_transient( 'campeones_historia_v2' );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );
        delete_transient( 'campeones_historia_v2' );
        $GLOBALS['_campeones_test_force_transient_write_failure'] = false;
        $GLOBALS['_campeones_test_error_log'] = [];
    }

    private function makeController( ?FakePlayerPhotoProvider $photos = null ): array {
        $photos ??= new FakePlayerPhotoProvider();
        return [ new HistoryController( $this->titles, $this->squads, $photos ), $photos ];
    }

    public function test_history_orders_titles_anio_descending(): void {
        $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $this->titles->createOrConflict( 2023, 'A', 'campeon', 'LIVERPOOL' );
        $this->titles->createOrConflict( 2019, 'A', 'campeon', 'BARCELONA' );

        [ $controller ] = $this->makeController();
        $response = $controller->handle( new \WP_REST_Request() );

        $this->assertSame( 200, $response->get_status() );
        $anios = array_column( $response->get_data()['titulos'], 'anio' );
        $this->assertSame( [ 2023, 2019, 2016 ], $anios );
    }

    public function test_an_all_unlinked_year_is_present_in_full(): void {
        // API-3: a year whose entire squad is sin_candidato appears in the
        // history in full, unmarked as an error.
        $title = $this->titles->createOrConflict( 2011, 'A', 'campeon', 'RIVER' );
        $this->squads->insert( new SquadEntry( $title->id, 0, 'BASSO, A.' ) );
        $this->squads->insert( new SquadEntry( $title->id, 1, 'MAZZARA, M.' ) );

        [ $controller ] = $this->makeController();
        $response = $controller->handle( new \WP_REST_Request() );

        $this->assertSame( 200, $response->get_status() );
        $plantel = $response->get_data()['titulos'][0]['plantel'];
        $this->assertCount( 2, $plantel );
        foreach ( $plantel as $row ) {
            $this->assertNull( $row['jugador_id'] );
            $this->assertSame( 'sin_candidato', $row['estado_vinculo'] );
        }
    }

    public function test_no_titles_returns_200_with_an_empty_list(): void {
        [ $controller ] = $this->makeController();
        $response = $controller->handle( new \WP_REST_Request() );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [], $response->get_data()['titulos'] );
    }

    // -------------------------------------------------------------------------
    // Photo batching (round-7, ADR-C2)
    // -------------------------------------------------------------------------

    public function test_photo_lookup_is_batched_into_exactly_one_call_across_the_whole_response(): void {
        $t1 = $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $t2 = $this->titles->createOrConflict( 2019, 'A', 'campeon', 'BARCELONA' );
        $this->squads->insert( new SquadEntry( $t1->id, 0, 'BASSO, A.', false, 'auto', 5078 ) );
        $this->squads->insert( new SquadEntry( $t1->id, 1, 'MAZZARA, M.', false, 'auto', 111 ) );
        $this->squads->insert( new SquadEntry( $t2->id, 0, 'BASSO, A.', false, 'auto', 5078 ) );

        [ $controller, $photos ] = $this->makeController();
        $controller->handle( new \WP_REST_Request() );

        $this->assertSame( 1, $photos->callCount(), 'Photo lookup must be issued once for the whole response, never once per year.' );
        $this->assertSame( [ 5078, 111 ], $photos->calls[0], 'The single call must carry every distinct linked id across all years.' );
    }

    public function test_a_mixed_year_maps_photo_null_and_absent_correctly(): void {
        $title = $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $this->squads->insert( new SquadEntry( $title->id, 0, 'BASSO, A.', false, 'auto', 5078 ) );      // has a photo
        $this->squads->insert( new SquadEntry( $title->id, 1, 'CALELLO, G.', false, 'auto', 111 ) );     // linked, no photo
        $this->squads->insert( new SquadEntry( $title->id, 2, 'MAZZARA, M.' ) );                          // unlinked

        [ $controller ] = $this->makeController( new FakePlayerPhotoProvider( [ 5078 => 'https://example.com/basso.jpg', 111 => null ] ) );
        $response = $controller->handle( new \WP_REST_Request() );

        $plantel = $response->get_data()['titulos'][0]['plantel'];
        $this->assertSame( 'https://example.com/basso.jpg', $plantel[0]['foto_url'] );
        $this->assertNull( $plantel[1]['foto_url'] );
        $this->assertNull( $plantel[2]['foto_url'] );
    }

    // -------------------------------------------------------------------------
    // Caching (design §7)
    // -------------------------------------------------------------------------

    public function test_a_second_call_is_served_from_the_transient_without_re_querying_photos(): void {
        $title = $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $this->squads->insert( new SquadEntry( $title->id, 0, 'BASSO, A.', false, 'auto', 5078 ) );

        [ $controller, $photos ] = $this->makeController( new FakePlayerPhotoProvider( [ 5078 => 'https://example.com/basso.jpg' ] ) );

        $first  = $controller->handle( new \WP_REST_Request() );
        $second = $controller->handle( new \WP_REST_Request() );

        $this->assertSame( $first->get_data(), $second->get_data() );
        $this->assertSame( 1, $photos->callCount(), 'A cache hit must never re-run the photo lookup.' );
    }

    public function test_a_cache_hit_reflects_data_present_when_the_cache_was_built_not_a_later_insert(): void {
        // Proves the transient is genuinely consulted first, not merely
        // that the two calls happen to agree.
        $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );

        [ $controller ] = $this->makeController();
        $controller->handle( new \WP_REST_Request() );

        $this->titles->createOrConflict( 2020, 'A', 'campeon', 'BOCA' );
        $second = $controller->handle( new \WP_REST_Request() );

        $this->assertCount( 1, $second->get_data()['titulos'], 'A cached response must not pick up a title created after it was cached.' );
    }

    // -------------------------------------------------------------------------
    // Item 3 (CRITICAL) — set_transient() returning false (payload too large,
    // a rejected write, an object-cache drop-in failure) went unchecked. The
    // response must still be correct, but the failure must be logged instead
    // of silently never populating the cache.
    // -------------------------------------------------------------------------

    public function test_a_failed_cache_write_still_returns_the_correct_payload(): void {
        $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );

        $GLOBALS['_campeones_test_force_transient_write_failure'] = true;

        [ $controller ] = $this->makeController();
        $response = $controller->handle( new \WP_REST_Request() );

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 1, $response->get_data()['titulos'], 'A failed cache write must not affect the response payload — it is still built from the DB.' );
    }

    public function test_a_failed_cache_write_is_logged(): void {
        $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );

        $GLOBALS['_campeones_test_force_transient_write_failure'] = true;
        $GLOBALS['_campeones_test_error_log'] = [];

        [ $controller ] = $this->makeController();
        $controller->handle( new \WP_REST_Request() );

        $this->assertNotEmpty(
            $GLOBALS['_campeones_test_error_log'],
            'A failed cache write must be logged — otherwise the cache silently never populates and every request re-runs the full rebuild indefinitely, with nothing in any log saying when it started.'
        );
    }
}

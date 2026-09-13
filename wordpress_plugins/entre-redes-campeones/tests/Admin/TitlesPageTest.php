<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Admin;

use EntreRedes\Campeones\Admin\TitlesPage;
use EntreRedes\Campeones\Linking\LinkResolver;
use EntreRedes\Campeones\Linking\LinkState;
use EntreRedes\Campeones\Linking\LinkWriteService;
use EntreRedes\Campeones\Linking\RevalidationService;
use EntreRedes\Campeones\Migrations\InitialSchema;
use EntreRedes\Campeones\Tests\Linking\FakePlayerDirectory;
use EntreRedes\Campeones\Tests\Support\RedirectTerminatedException;
use EntreRedes\Campeones\Tests\Support\TestableTitlesPage;
use EntreRedes\Campeones\Tests\Support\ThrowingPlayerDirectory;
use EntreRedes\Campeones\Titles\SquadEntry;
use EntreRedes\Campeones\Titles\SquadRepository;
use EntreRedes\Campeones\Titles\TitleDeletionService;
use EntreRedes\Campeones\Titles\TitleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TitlesPage (task 3.3).
 *
 * The shim's current_user_can() always returns false, so render() and
 * handlePost() always call wp_die(), which the shim turns into a
 * RuntimeException — this proves the capability guard without a WP install
 * (same strategy as entre-redes-prode's PredictionsPageTest). The actual
 * per-row mutations (delete / revalidate) are private, do not themselves
 * gate on capability, and end in wp_safe_redirect()+exit — so they are
 * exercised directly via Reflection, bypassing the exit-ing public entry
 * point, exactly as PredictionsPageTest reaches renderDetail().
 */
class TitlesPageTest extends TestCase {

    private TitleRepository $titles;
    private SquadRepository $squads;
    private int $tituloId;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );

        $this->titles = new TitleRepository( $wpdb );
        $this->squads = new SquadRepository( $wpdb );

        $title          = $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $this->tituloId = $title->id;
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );
        $GLOBALS['_campeones_test_current_user_can'] = false;
        unset( $_GET['campeones_notice'] );
    }

    private function makePage(): TitlesPage {
        global $wpdb;

        $rows      = require __DIR__ . '/../Fixtures/players.php';
        $directory = FakePlayerDirectory::fromFixtureRows( $rows );

        return new TitlesPage(
            $this->titles,
            new TitleDeletionService( $wpdb, $this->titles, $this->squads ),
            new RevalidationService(
                $this->titles,
                $this->squads,
                new LinkResolver( $directory ),
                new LinkWriteService( $this->squads )
            )
        );
    }

    // -------------------------------------------------------------------------
    // Constructor / capability guard
    // -------------------------------------------------------------------------

    public function test_page_can_be_instantiated(): void {
        $this->assertInstanceOf( TitlesPage::class, $this->makePage() );
    }

    public function test_render_throws_when_user_cannot_manage_options(): void {
        $this->expectException( \RuntimeException::class );
        $this->makePage()->render();
    }

    public function test_handle_post_throws_when_user_cannot_manage_options_and_an_action_is_present(): void {
        $_POST['campeones_titulo_action'] = 'eliminar';
        $_POST['titulo_id']               = (string) $this->tituloId;

        $this->expectException( \RuntimeException::class );
        $this->makePage()->handlePost();
    }

    public function test_handle_post_is_a_no_op_with_no_action_present(): void {
        unset( $_POST['campeones_titulo_action'] );

        $this->makePage()->handlePost();
        $this->assertTrue( true, 'handlePost() must not die on unrelated admin_init requests.' );
    }

    public function test_handle_post_rejects_an_invalid_nonce_even_when_capability_check_passes(): void {
        // Proves the nonce check is load-bearing, not decorative (item 1):
        // with manage_options granted, an eliminar POST carrying a nonce
        // that does not match campeones_eliminar_titulo_{id} must still be
        // rejected before any row is touched.
        $GLOBALS['_campeones_test_current_user_can'] = true;

        $_POST['campeones_titulo_action'] = 'eliminar';
        $_POST['titulo_id']               = (string) $this->tituloId;
        $_POST['campeones_titulo_nonce']  = 'forged-nonce';

        $this->expectException( \RuntimeException::class );
        try {
            $this->makePage()->handlePost();
        } finally {
            $this->assertNotNull( $this->titles->find( $this->tituloId ), 'A rejected nonce must never let the delete run.' );
        }
    }

    // -------------------------------------------------------------------------
    // Private mutation handlers — exercised via Reflection, bypassing the
    // capability-gated, exit()-ing public entry point.
    // -------------------------------------------------------------------------

    public function test_handle_delete_removes_the_title_and_its_squad(): void {
        $this->squads->insert( new SquadEntry( $this->tituloId, 0, 'BASSO, A.' ) );

        $page = $this->makePage();
        $ref  = new \ReflectionMethod( TitlesPage::class, 'handleDelete' );

        $this->assertTrue( $ref->invoke( $page, $this->tituloId ) );
        $this->assertNull( $this->titles->find( $this->tituloId ) );
    }

    public function test_handle_revalidate_re_resolves_resolvable_rows(): void {
        $id = $this->squads->insert(
            new SquadEntry( $this->tituloId, 0, 'GARCIA, M.', false, LinkState::SIN_CANDIDATO )
        );

        $page = $this->makePage();
        $ref  = new \ReflectionMethod( TitlesPage::class, 'handleRevalidate' );

        $this->assertSame( 1, $ref->invoke( $page, $this->tituloId ) );

        $row = $this->squads->find( $id );
        $this->assertSame( LinkState::AMBIGUO, $row->estadoVinculo );
    }

    public function test_handle_revalidate_never_touches_a_manual_row(): void {
        $id = $this->squads->insert(
            new SquadEntry( $this->tituloId, 0, 'MAZZARA, M.', false, LinkState::MANUAL, 999999 )
        );

        $page = $this->makePage();
        $ref  = new \ReflectionMethod( TitlesPage::class, 'handleRevalidate' );
        $ref->invoke( $page, $this->tituloId );

        $row = $this->squads->find( $id );
        $this->assertSame( LinkState::MANUAL, $row->estadoVinculo );
        $this->assertSame( 999999, $row->jugadorId );
    }

    // -------------------------------------------------------------------------
    // Item 5 — every notice handlePost() computes was thrown away: render()
    // never read $_GET['campeones_notice'], so a failed destructive action
    // looked exactly like a successful one.
    // -------------------------------------------------------------------------

    public function test_render_shows_a_success_notice_for_eliminado(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;
        $_GET['campeones_notice'] = 'eliminado';

        ob_start();
        $this->makePage()->render();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'notice-success', $html );
        $this->assertStringContainsString( 'eliminado', mb_strtolower( $html ) );
    }

    public function test_render_shows_an_error_notice_for_error_eliminar(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;
        $_GET['campeones_notice'] = 'error_eliminar';

        ob_start();
        $this->makePage()->render();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'notice-error', $html );
    }

    public function test_render_shows_the_revalidation_count_in_its_notice(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;
        $_GET['campeones_notice'] = 'revalidado_7';

        ob_start();
        $this->makePage()->render();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'notice-success', $html );
        $this->assertStringContainsString( '7', $html );
    }

    public function test_render_shows_no_notice_when_none_is_present_in_the_query(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;

        ob_start();
        $this->makePage()->render();
        $html = ob_get_clean();

        $this->assertStringNotContainsString( 'class="notice', $html );
    }

    // -------------------------------------------------------------------------
    // Item 7 — PlayerDirectoryQueryException is caught nowhere in
    // src/Admin/. handleRevalidate() runs LinkResolver over every
    // resolvable row in the year; a broken directory read must redirect
    // with a distinct notice instead of a fatal mid-loop.
    // -------------------------------------------------------------------------

    public function test_handle_post_revalidar_catches_a_directory_query_exception(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;

        global $wpdb;
        $this->squads->insert( new SquadEntry( $this->tituloId, 0, 'BASSO, A.' ) );

        $throwingResolver = new LinkResolver( new ThrowingPlayerDirectory() );
        $page             = new TestableTitlesPage(
            $this->titles,
            new TitleDeletionService( $wpdb, $this->titles, $this->squads ),
            new RevalidationService( $this->titles, $this->squads, $throwingResolver, new LinkWriteService( $this->squads ) )
        );

        $_POST['campeones_titulo_action'] = 'revalidar';
        $_POST['titulo_id']               = (string) $this->tituloId;
        $_POST['campeones_titulo_nonce']  = wp_create_nonce( 'campeones_revalidar_' . $this->tituloId );

        $this->expectException( RedirectTerminatedException::class );
        try {
            $page->handlePost();
        } finally {
            $this->assertStringContainsString(
                'campeones_notice=error_directorio',
                (string) $GLOBALS['_campeones_test_last_redirect'],
                'A directory query failure must redirect with a distinct notice, not fall through to a fatal.'
            );
        }
    }
}

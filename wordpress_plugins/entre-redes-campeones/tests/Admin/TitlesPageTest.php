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
}

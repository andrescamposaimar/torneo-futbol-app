<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Admin;

use EntreRedes\Campeones\Admin\TitleEditorPage;
use EntreRedes\Campeones\Linking\LinkResolver;
use EntreRedes\Campeones\Linking\LinkState;
use EntreRedes\Campeones\Linking\LinkWriteService;
use EntreRedes\Campeones\Migrations\InitialSchema;
use EntreRedes\Campeones\Tests\Linking\FakePlayerDirectory;
use EntreRedes\Campeones\Titles\SquadEntry;
use EntreRedes\Campeones\Titles\SquadRepository;
use EntreRedes\Campeones\Titles\TitleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TitleEditorPage (task 3.4).
 *
 * Same strategy as TitlesPageTest: the shim's current_user_can() always
 * returns false, so render()/handlePost() only prove the capability guard
 * (RuntimeException via wp_die()). The private mutation handlers hold no
 * capability check of their own and never redirect/exit, so real add /
 * edit / delete / link behaviour is exercised directly via Reflection.
 */
class TitleEditorPageTest extends TestCase {

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
    }

    private function makePage(): TitleEditorPage {
        $rows      = require __DIR__ . '/../Fixtures/players.php';
        $directory = FakePlayerDirectory::fromFixtureRows( $rows );

        return new TitleEditorPage(
            $this->titles,
            $this->squads,
            new LinkResolver( $directory ),
            new LinkWriteService( $this->squads )
        );
    }

    private function invoke( string $method, mixed ...$args ): mixed {
        $ref = new \ReflectionMethod( TitleEditorPage::class, $method );
        return $ref->invoke( $this->makePage(), ...$args );
    }

    // -------------------------------------------------------------------------
    // Constructor / capability guard
    // -------------------------------------------------------------------------

    public function test_page_can_be_instantiated(): void {
        $this->assertInstanceOf( TitleEditorPage::class, $this->makePage() );
    }

    public function test_render_throws_when_user_cannot_manage_options(): void {
        $this->expectException( \RuntimeException::class );
        $this->makePage()->render();
    }

    public function test_handle_post_throws_when_user_cannot_manage_options_and_an_action_is_present(): void {
        $_POST['campeones_editor_action'] = 'eliminar_fila';

        $this->expectException( \RuntimeException::class );
        $this->makePage()->handlePost();
    }

    public function test_handle_post_is_a_no_op_with_no_action_present(): void {
        unset( $_POST['campeones_editor_action'] );

        $this->makePage()->handlePost();
        $this->assertTrue( true );
    }

    // -------------------------------------------------------------------------
    // CSRF — item 2: handlePost() verified NO nonce at all for any of its
    // eight actions. Every one of them must now reject a forged nonce even
    // when manage_options is granted, driven through the real public
    // handlePost() entry point (not Reflection), so the check actually sits
    // where a real request would hit it.
    // -------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function actionsProvider(): array {
        return [
            'crear_titulo'      => [ 'crear_titulo' ],
            'actualizar_titulo' => [ 'actualizar_titulo' ],
            'agregar_fila'      => [ 'agregar_fila' ],
            'editar_fila'       => [ 'editar_fila' ],
            'eliminar_fila'     => [ 'eliminar_fila' ],
            'vincular'          => [ 'vincular' ],
            'cambiar'           => [ 'cambiar' ],
            'desvincular'       => [ 'desvincular' ],
        ];
    }

    /** @dataProvider actionsProvider */
    public function test_handle_post_rejects_an_invalid_nonce_for_every_action( string $action ): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;

        $rowId = $this->squads->insert( new SquadEntry( $this->tituloId, 0, 'BASSO, A.' ) );

        $_POST['campeones_editor_action'] = $action;
        $_POST['titulo_id']               = (string) $this->tituloId;
        $_POST['plantel_id']              = (string) $rowId;
        $_POST['jugador_id']              = '5078';
        $_POST['jugador_nombre']          = 'BASSO, A.';
        $_POST['es_capitan']              = '';
        $_POST['orden']                   = '0';
        $_POST['equipo_nombre']           = 'BOCA';
        $_POST['anio']                    = '2020';
        $_POST['zona']                    = 'A';
        $_POST['posicion']                = 'campeon';
        $_POST['campeones_editor_nonce']  = 'forged-nonce';
        $_POST['campeones_link_nonce']    = 'forged-nonce';

        $this->expectException( \RuntimeException::class );
        try {
            $this->makePage()->handlePost();
        } finally {
            $row = $this->squads->find( $rowId );
            $this->assertSame(
                'sin_candidato',
                $row->estadoVinculo,
                "A forged nonce must not let '{$action}' reach its handler."
            );
            $this->assertNull( $row->jugadorId );
        }
    }

    // -------------------------------------------------------------------------
    // Header — create / update
    // -------------------------------------------------------------------------

    public function test_handle_create_title_creates_a_new_record(): void {
        $newId = $this->invoke( 'handleCreateTitle', 2011, 'A', 'campeon', 'INDEPENDIENTE' );

        $this->assertIsInt( $newId );
        $found = $this->titles->find( $newId );
        $this->assertSame( 'INDEPENDIENTE', $found->equipoNombre );
    }

    public function test_handle_create_title_returns_null_on_conflict(): void {
        // 2016/A/campeon already exists from setUp().
        $result = $this->invoke( 'handleCreateTitle', 2016, 'A', 'campeon', 'BOCA' );

        $this->assertNull( $result );
    }

    public function test_handle_update_header_changes_the_team_name_only(): void {
        $ok = $this->invoke( 'handleUpdateHeader', $this->tituloId, 'BOCA' );

        $this->assertTrue( $ok );
        $reread = $this->titles->find( $this->tituloId );
        $this->assertSame( 'BOCA', $reread->equipoNombre );
        $this->assertSame( 2016, $reread->anio, 'Identity fields must never change through a header edit.' );
    }

    // -------------------------------------------------------------------------
    // Squad row CRUD, with LINK-1 resolution on add/edit
    // -------------------------------------------------------------------------

    public function test_handle_add_row_inserts_and_resolves_it(): void {
        // BASSO, A. resolves auto against the fixture directory (id 5078).
        $newId = $this->invoke( 'handleAddRow', $this->tituloId, 'BASSO, A.', false );

        $this->assertIsInt( $newId );
        $row = $this->squads->find( $newId );
        $this->assertSame( 'BASSO, A.', $row->jugadorNombre );
        $this->assertSame( LinkState::AUTO, $row->estadoVinculo );
        $this->assertSame( 5078, $row->jugadorId );
    }

    public function test_handle_add_row_appends_at_the_next_orden(): void {
        $this->invoke( 'handleAddRow', $this->tituloId, 'BASSO, A.', false );
        $secondId = $this->invoke( 'handleAddRow', $this->tituloId, 'CALELLO, G.', true );

        $row = $this->squads->find( $secondId );
        $this->assertSame( 1, $row->orden );
        $this->assertTrue( $row->esCapitan );
    }

    public function test_handle_edit_row_updates_name_and_re_resolves_when_not_manual(): void {
        $id = $this->squads->insert( new SquadEntry( $this->tituloId, 0, 'ZUBIZARRETA, F.' ) );
        // Insert leaves the row at its SquadRepository::insert() default
        // (sin_candidato) — the editor must re-resolve on a name change.

        $ok = $this->invoke( 'handleEditRow', $id, 'BASSO, A.', false, 0 );

        $this->assertTrue( $ok );
        $row = $this->squads->find( $id );
        $this->assertSame( 'BASSO, A.', $row->jugadorNombre );
        $this->assertSame( LinkState::AUTO, $row->estadoVinculo );
        $this->assertSame( 5078, $row->jugadorId );
    }

    public function test_handle_edit_row_never_re_resolves_a_manual_row(): void {
        $id = $this->squads->insert(
            new SquadEntry( $this->tituloId, 0, 'MAZZARA, M.', false, LinkState::MANUAL, 999999 )
        );

        // Even changing the captain flag must not disturb a manual link.
        $ok = $this->invoke( 'handleEditRow', $id, 'MAZZARA, M.', true, 0 );

        $this->assertTrue( $ok );
        $row = $this->squads->find( $id );
        $this->assertSame( LinkState::MANUAL, $row->estadoVinculo );
        $this->assertSame( 999999, $row->jugadorId );
        $this->assertTrue( $row->esCapitan );
    }

    public function test_handle_delete_row_removes_it(): void {
        $id = $this->squads->insert( new SquadEntry( $this->tituloId, 0, 'BASSO, A.' ) );

        $this->assertTrue( $this->invoke( 'handleDeleteRow', $id ) );
        $this->assertNull( $this->squads->find( $id ) );
    }

    // -------------------------------------------------------------------------
    // Link control — Vincular / Cambiar / Desvincular, all -> manual (LINK-8)
    // -------------------------------------------------------------------------

    public function test_handle_set_link_vincula_sets_the_pointer_and_becomes_manual(): void {
        $id = $this->squads->insert( new SquadEntry( $this->tituloId, 0, 'ZUBIZARRETA, F.' ) );

        $this->assertTrue( $this->invoke( 'handleSetLink', $id, 2225 ) );

        $row = $this->squads->find( $id );
        $this->assertSame( 2225, $row->jugadorId );
        $this->assertSame( LinkState::MANUAL, $row->estadoVinculo );
    }

    public function test_handle_set_link_cambia_replaces_an_existing_pointer(): void {
        $id = $this->squads->insert( new SquadEntry( $this->tituloId, 0, 'GARCIA, M.', false, LinkState::AMBIGUO ) );

        $this->invoke( 'handleSetLink', $id, 2225 );
        $this->invoke( 'handleSetLink', $id, 2461 );

        $row = $this->squads->find( $id );
        $this->assertSame( 2461, $row->jugadorId );
        $this->assertSame( LinkState::MANUAL, $row->estadoVinculo );
    }

    public function test_handle_set_link_desvincula_with_a_null_pointer_stays_manual(): void {
        // LINK-9 acceptance scenario "manual unlink survives re-validation":
        // Desvincular leaves NO pointer but the state is still `manual`,
        // never back to sin_candidato/auto/ambiguo.
        $id = $this->squads->insert(
            new SquadEntry( $this->tituloId, 0, 'BASSO, A.', false, LinkState::AUTO, 5078 )
        );

        $this->assertTrue( $this->invoke( 'handleSetLink', $id, null ) );

        $row = $this->squads->find( $id );
        $this->assertNull( $row->jugadorId );
        $this->assertSame( LinkState::MANUAL, $row->estadoVinculo );
    }
}

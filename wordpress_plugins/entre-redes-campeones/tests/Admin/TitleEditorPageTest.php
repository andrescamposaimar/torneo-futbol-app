<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Admin;

use EntreRedes\Campeones\Admin\TitleEditorPage;
use EntreRedes\Campeones\Linking\LinkResolver;
use EntreRedes\Campeones\Linking\LinkState;
use EntreRedes\Campeones\Linking\LinkWriteService;
use EntreRedes\Campeones\Migrations\InitialSchema;
use EntreRedes\Campeones\Tests\Linking\FakePlayerDirectory;
use EntreRedes\Campeones\Tests\Support\FailingInsertWpdb;
use EntreRedes\Campeones\Tests\Support\FailingResolutionApplyWpdb;
use EntreRedes\Campeones\Tests\Support\RedirectTerminatedException;
use EntreRedes\Campeones\Tests\Support\TestableTitleEditorPage;
use EntreRedes\Campeones\Tests\Support\ThrowingPlayerDirectory;
use EntreRedes\Campeones\Titles\SquadEntry;
use EntreRedes\Campeones\Titles\SquadRepository;
use EntreRedes\Campeones\Titles\TitleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TitleEditorPage (task 3.4).
 *
 * Same strategy as TitlesPageTest: the shim's current_user_can() always
 * returns false, so render()/handlePost() only prove the capability guard
 * (RuntimeException via wp_die()) by default. The private mutation
 * handlers hold no capability check of their own and never redirect/exit,
 * so most add / edit / delete / link behaviour is exercised directly via
 * Reflection. The "driven through handlePost()" tests below use
 * TestableTitleEditorPage instead — a real request end to end (capability
 * granted, correct nonce, dispatch, PRG redirect) with only the final
 * `exit;` replaced by a catchable exception, so a missing or
 * disconnected form/entry point fails the suite instead of going unnoticed.
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
        unset( $_GET['titulo_id'], $_GET['campeones_notice'] );
    }

    private function makePage(): TitleEditorPage {
        $rows      = require __DIR__ . '/../Fixtures/players.php';
        $directory = FakePlayerDirectory::fromFixtureRows( $rows );

        return new TitleEditorPage(
            $this->titles,
            $this->squads,
            new LinkResolver( $directory ),
            new LinkWriteService( $this->squads, $directory )
        );
    }

    private function invoke( string $method, mixed ...$args ): mixed {
        $ref = new \ReflectionMethod( TitleEditorPage::class, $method );
        return $ref->invoke( $this->makePage(), ...$args );
    }

    private function makeTestablePage(): TestableTitleEditorPage {
        $rows      = require __DIR__ . '/../Fixtures/players.php';
        $directory = FakePlayerDirectory::fromFixtureRows( $rows );

        return new TestableTitleEditorPage(
            $this->titles,
            $this->squads,
            new LinkResolver( $directory ),
            new LinkWriteService( $this->squads, $directory )
        );
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

    public function test_handle_edit_row_re_resolves_even_when_only_the_captain_flag_changes(): void {
        // Pins the documented, deliberate choice: a non-manual row is
        // re-resolved on every successful edit, not only when the name
        // itself changed. Insert as if stale (sin_candidato) with a name
        // that DOES resolve auto, then edit only the captain flag with the
        // SAME name — the row must still come out AUTO/5078, proving
        // handleEditRow() re-ran LinkResolver despite no name change.
        $id = $this->squads->insert(
            new SquadEntry( $this->tituloId, 0, 'BASSO, A.', false, LinkState::SIN_CANDIDATO )
        );

        $ok = $this->invoke( 'handleEditRow', $id, 'BASSO, A.', true, 0 );

        $this->assertTrue( $ok );
        $row = $this->squads->find( $id );
        $this->assertSame( LinkState::AUTO, $row->estadoVinculo );
        $this->assertSame( 5078, $row->jugadorId );
        $this->assertTrue( $row->esCapitan );
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
    // Item 6 — handleAddRow()/handleEditRow() reported success even when
    // applyResolution()'s write failed. Force the resolution write to fail
    // while the row's own insert/update still succeeds, and prove the
    // reported outcome now reflects that.
    // -------------------------------------------------------------------------

    public function test_handle_add_row_reports_failure_when_the_resolution_write_fails(): void {
        global $wpdb;
        $original = $wpdb;
        $failing  = new FailingResolutionApplyWpdb();

        try {
            $wpdb = $failing;
            InitialSchema::up();

            $titles = new TitleRepository( $failing );
            $squads = new SquadRepository( $failing );
            $title  = $titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );

            $rows      = require __DIR__ . '/../Fixtures/players.php';
            $directory = FakePlayerDirectory::fromFixtureRows( $rows );

            $page = new TitleEditorPage(
                $titles,
                $squads,
                new LinkResolver( $directory ),
                new LinkWriteService( $squads, $directory )
            );

            $ref    = new \ReflectionMethod( TitleEditorPage::class, 'handleAddRow' );
            $result = $ref->invoke( $page, $title->id, 'BASSO, A.', false );

            $this->assertNull( $result, 'A failed resolution write must not be reported as a successful add.' );
        } finally {
            $wpdb = $original;
        }
    }

    public function test_handle_edit_row_reports_failure_when_the_resolution_write_fails(): void {
        global $wpdb;
        $original = $wpdb;
        $failing  = new FailingResolutionApplyWpdb();

        try {
            $wpdb = $failing;
            InitialSchema::up();

            $titles = new TitleRepository( $failing );
            $squads = new SquadRepository( $failing );
            $title  = $titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
            $id     = $squads->insert( new SquadEntry( $title->id, 0, 'ZUBIZARRETA, F.' ) );

            $rows      = require __DIR__ . '/../Fixtures/players.php';
            $directory = FakePlayerDirectory::fromFixtureRows( $rows );

            $page = new TitleEditorPage(
                $titles,
                $squads,
                new LinkResolver( $directory ),
                new LinkWriteService( $squads, $directory )
            );

            $ref    = new \ReflectionMethod( TitleEditorPage::class, 'handleEditRow' );
            $result = $ref->invoke( $page, $id, 'BASSO, A.', false, 0 );

            $this->assertFalse( $result, 'A failed resolution write must not be reported as a successful edit.' );
        } finally {
            $wpdb = $original;
        }
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

    // -------------------------------------------------------------------------
    // Item 3 — the slice's purpose is unreachable without a form that
    // triggers agregar_fila / editar_fila. These drive the REAL handlePost()
    // entry point (allow-list, capability, nonce, dispatch, redirect), not
    // Reflection — a missing or disconnected form fails these, where the
    // Reflection-based tests above could not have caught it.
    // -------------------------------------------------------------------------

    public function test_render_emits_a_form_that_submits_agregar_fila(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;
        $_GET['titulo_id'] = (string) $this->tituloId;

        ob_start();
        $this->makeTestablePage()->render();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'name="campeones_editor_action" value="agregar_fila"', $html );
        $this->assertStringContainsString( 'name="jugador_nombre"', $html );
        $this->assertStringContainsString( 'name="es_capitan"', $html );
    }

    public function test_handle_post_agregar_fila_inserts_and_resolves_a_row(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;

        $_POST['campeones_editor_action'] = 'agregar_fila';
        $_POST['titulo_id']               = (string) $this->tituloId;
        $_POST['jugador_nombre']          = 'BASSO, A.';
        $_POST['es_capitan']              = '1';
        $_POST['campeones_editor_nonce']  = wp_create_nonce( 'campeones_agregar_fila_' . $this->tituloId );

        $this->expectException( RedirectTerminatedException::class );
        try {
            $this->makeTestablePage()->handlePost();
        } finally {
            $rows = $this->squads->findByTitle( $this->tituloId );
            $this->assertCount( 1, $rows, 'agregar_fila must actually reach handleAddRow() and insert a row.' );
            $this->assertSame( 'BASSO, A.', $rows[0]->jugadorNombre );
            $this->assertTrue( $rows[0]->esCapitan );
            $this->assertSame( LinkState::AUTO, $rows[0]->estadoVinculo );
        }
    }

    // -------------------------------------------------------------------------
    // Item 8 — "Vincular" with an empty id silently became "Desvincular":
    // absint($_POST['jugador_id'] ?? 0) ?: null collapses a missing/zero id
    // into null, writing the same `manual`-with-no-pointer row as a real
    // unlink. vincular/cambiar must require a non-zero id instead.
    // -------------------------------------------------------------------------

    public function test_handle_post_vincular_rejects_a_missing_jugador_id(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;

        $id = $this->squads->insert( new SquadEntry( $this->tituloId, 0, 'ZUBIZARRETA, F.' ) );

        $_POST['campeones_editor_action'] = 'vincular';
        $_POST['titulo_id']               = (string) $this->tituloId;
        $_POST['plantel_id']              = (string) $id;
        $_POST['jugador_id']              = '';
        $_POST['campeones_link_nonce']    = wp_create_nonce( 'campeones_link_' . $id );

        $this->expectException( RedirectTerminatedException::class );
        try {
            $this->makeTestablePage()->handlePost();
        } finally {
            $this->assertStringContainsString(
                'campeones_notice=error_id_requerido',
                (string) $GLOBALS['_campeones_test_last_redirect'],
                'Vincular with no id must report a distinct error, not silently become Desvincular.'
            );

            $row = $this->squads->find( $id );
            $this->assertSame( 'sin_candidato', $row->estadoVinculo, 'A rejected Vincular must never write manual with no pointer.' );
            $this->assertNull( $row->jugadorId );
        }
    }

    public function test_handle_post_desvincular_redirects_to_the_real_titulo_id_not_zero(): void {
        // Item 4: the row's forms only ever carried titulo_id on the form's
        // action="...&titulo_id=..." URL, never as a hidden POST field, so
        // handlePost() always read titulo_id=0 and the redirect landed on
        // the "Nuevo título" create form instead of back on this title.
        $GLOBALS['_campeones_test_current_user_can'] = true;

        $id = $this->squads->insert(
            new SquadEntry( $this->tituloId, 0, 'BASSO, A.', false, LinkState::AUTO, 5078 )
        );

        $_POST['campeones_editor_action'] = 'desvincular';
        $_POST['titulo_id']               = (string) $this->tituloId;
        $_POST['plantel_id']              = (string) $id;
        $_POST['campeones_link_nonce']    = wp_create_nonce( 'campeones_link_' . $id );

        $this->expectException( RedirectTerminatedException::class );
        try {
            $this->makeTestablePage()->handlePost();
        } finally {
            $this->assertStringContainsString(
                'titulo_id=' . $this->tituloId,
                (string) $GLOBALS['_campeones_test_last_redirect'],
                'A row action must redirect back to the title it acted on, not titulo_id=0.'
            );
            $row = $this->squads->find( $id );
            $this->assertNull( $row->jugadorId, 'desvincular must actually reach handleSetLink() and clear the pointer.' );
            $this->assertSame( LinkState::MANUAL, $row->estadoVinculo );
        }
    }

    public function test_handle_post_vincular_links_a_row(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;

        $id = $this->squads->insert( new SquadEntry( $this->tituloId, 0, 'ZUBIZARRETA, F.' ) );

        $_POST['campeones_editor_action'] = 'vincular';
        $_POST['titulo_id']               = (string) $this->tituloId;
        $_POST['plantel_id']              = (string) $id;
        $_POST['jugador_id']              = '5078';
        $_POST['campeones_link_nonce']    = wp_create_nonce( 'campeones_link_' . $id );

        $this->expectException( RedirectTerminatedException::class );
        try {
            $this->makeTestablePage()->handlePost();
        } finally {
            $this->assertStringContainsString( 'campeones_notice=vinculado', (string) $GLOBALS['_campeones_test_last_redirect'] );
            $row = $this->squads->find( $id );
            $this->assertSame( 5078, $row->jugadorId, 'vincular must actually reach handleSetLink() and set the pointer.' );
            $this->assertSame( LinkState::MANUAL, $row->estadoVinculo );
        }
    }

    public function test_handle_post_eliminar_fila_removes_a_row(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;

        $id = $this->squads->insert( new SquadEntry( $this->tituloId, 0, 'BASSO, A.' ) );

        $_POST['campeones_editor_action'] = 'eliminar_fila';
        $_POST['titulo_id']               = (string) $this->tituloId;
        $_POST['plantel_id']              = (string) $id;
        $_POST['campeones_link_nonce']    = wp_create_nonce( 'campeones_link_' . $id );

        $this->expectException( RedirectTerminatedException::class );
        try {
            $this->makeTestablePage()->handlePost();
        } finally {
            $this->assertStringContainsString( 'campeones_notice=fila_eliminada', (string) $GLOBALS['_campeones_test_last_redirect'] );
            $this->assertNull( $this->squads->find( $id ), 'eliminar_fila must actually reach handleDeleteRow() and remove the row.' );
        }
    }

    public function test_handle_post_crear_titulo_creates_a_title(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;

        unset( $_POST['titulo_id'] );
        $_POST['campeones_editor_action'] = 'crear_titulo';
        $_POST['anio']                    = '2011';
        $_POST['zona']                    = 'A';
        $_POST['posicion']                = 'campeon';
        $_POST['equipo_nombre']           = 'INDEPENDIENTE';
        $_POST['campeones_editor_nonce']  = wp_create_nonce( 'campeones_crear_titulo' );

        $this->expectException( RedirectTerminatedException::class );
        try {
            $this->makeTestablePage()->handlePost();
        } finally {
            $this->assertStringContainsString( 'campeones_notice=creado', (string) $GLOBALS['_campeones_test_last_redirect'] );
            $created = $this->titles->findByKey( 2011, 'A', 'campeon' );
            $this->assertNotNull( $created, 'crear_titulo must actually reach handleCreateTitle() and insert the title.' );
            $this->assertSame( 'INDEPENDIENTE', $created->equipoNombre );
            $_POST['titulo_id'] = (string) $this->tituloId;
        }
    }

    public function test_handle_post_editar_fila_updates_a_row(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;

        $id = $this->squads->insert( new SquadEntry( $this->tituloId, 0, 'ZUBIZARRETA, F.' ) );

        $_POST['campeones_editor_action'] = 'editar_fila';
        $_POST['titulo_id']               = (string) $this->tituloId;
        $_POST['plantel_id']              = (string) $id;
        $_POST['jugador_nombre']          = 'BASSO, A.';
        $_POST['es_capitan']              = '1';
        $_POST['orden']                   = '0';
        $_POST['campeones_link_nonce']    = wp_create_nonce( 'campeones_link_' . $id );

        $this->expectException( RedirectTerminatedException::class );
        try {
            $this->makeTestablePage()->handlePost();
        } finally {
            $row = $this->squads->find( $id );
            $this->assertSame( 'BASSO, A.', $row->jugadorNombre, 'editar_fila must actually reach handleEditRow() and update the row.' );
            $this->assertTrue( $row->esCapitan );
            $this->assertSame( LinkState::AUTO, $row->estadoVinculo );
        }
    }

    // -------------------------------------------------------------------------
    // Item 5 — every notice handlePost() computes was thrown away: render()
    // never read $_GET['campeones_notice']. Cover both the create form
    // branch (e.g. 'conflicto') and the title-found branch (e.g. 'vinculado').
    // -------------------------------------------------------------------------

    public function test_render_shows_an_error_notice_on_the_create_form_for_a_conflict(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;
        // No titulo_id in $_GET -> renderCreateForm() branch.
        $_GET['campeones_notice'] = 'conflicto';

        ob_start();
        $this->makeTestablePage()->render();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'notice-error', $html );
    }

    public function test_render_shows_a_success_notice_for_vinculado_on_the_title_found_branch(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;
        $_GET['titulo_id']         = (string) $this->tituloId;
        $_GET['campeones_notice']  = 'vinculado';

        ob_start();
        $this->makeTestablePage()->render();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'notice-success', $html );
    }

    public function test_render_shows_an_error_notice_for_error_vincular(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;
        $_GET['titulo_id']        = (string) $this->tituloId;
        $_GET['campeones_notice'] = 'error_vincular';

        ob_start();
        $this->makeTestablePage()->render();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'notice-error', $html );
    }

    // -------------------------------------------------------------------------
    // Item 7 — PlayerDirectoryQueryException / WriteFailedException were
    // caught nowhere in src/Admin/. Worse, in handleAddRow the row is
    // already inserted before the resolver runs, so an uncaught throw there
    // left a real row behind and sent the operator to WordPress's fatal
    // error screen instead of a notice.
    // -------------------------------------------------------------------------

    public function test_handle_post_agregar_fila_catches_a_directory_query_exception(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;

        $throwingDirectory = new ThrowingPlayerDirectory();
        $throwingResolver  = new LinkResolver( $throwingDirectory );
        $page              = new TestableTitleEditorPage( $this->titles, $this->squads, $throwingResolver, new LinkWriteService( $this->squads, $throwingDirectory ) );

        $_POST['campeones_editor_action'] = 'agregar_fila';
        $_POST['titulo_id']               = (string) $this->tituloId;
        $_POST['jugador_nombre']          = 'BASSO, A.';
        $_POST['campeones_editor_nonce']  = wp_create_nonce( 'campeones_agregar_fila_' . $this->tituloId );

        $this->expectException( RedirectTerminatedException::class );
        try {
            $page->handlePost();
        } finally {
            $this->assertStringContainsString(
                'campeones_notice=error_directorio',
                (string) $GLOBALS['_campeones_test_last_redirect'],
                'A directory query failure must redirect with a distinct notice, not fall through to a fatal.'
            );
            // The row itself was already inserted before the resolver threw
            // — it must still exist (in its sin_candidato default), so a
            // later "Revalidar" can pick it up, exactly as item 7 requires.
            $rows = $this->squads->findByTitle( $this->tituloId );
            $this->assertCount( 1, $rows );
            $this->assertSame( LinkState::SIN_CANDIDATO, $rows[0]->estadoVinculo );
        }
    }

    // -------------------------------------------------------------------------
    // Item 1 — no handler verified a squad row belongs to the title being
    // edited. The per-row nonce is scoped to the ROW (campeones_link_{id}),
    // not the title, so a request pairing a VALID row nonce with a
    // different titulo_id must still be rejected before any handler runs.
    // -------------------------------------------------------------------------

    public function test_handle_post_editar_fila_rejects_a_row_belonging_to_a_different_title_even_with_a_valid_row_nonce(): void {
        // Pins the exact shape flagged in review: a nonce that is genuinely
        // valid for the row it was minted for, submitted alongside a
        // DIFFERENT titulo_id. The nonce derivation is correct — only a
        // row-vs-title ownership check stops this.
        $GLOBALS['_campeones_test_current_user_can'] = true;

        $titleX = $this->titles->createOrConflict( 2017, 'A', 'campeon', 'RIVER' );
        $titleY = $this->titles->createOrConflict( 2018, 'A', 'campeon', 'INDEPENDIENTE' );

        $rowInX = $this->squads->insert(
            new SquadEntry( $titleX->id, 0, 'BASSO, A.', false, LinkState::AUTO, 5078 )
        );

        $_POST['campeones_editor_action'] = 'editar_fila';
        $_POST['titulo_id']               = (string) $titleY->id; // Y, not X.
        $_POST['plantel_id']              = (string) $rowInX;      // The row belongs to X.
        $_POST['jugador_nombre']          = 'MAZZARA, M.';
        $_POST['es_capitan']              = '1';
        $_POST['orden']                   = '0';
        $_POST['campeones_link_nonce']    = wp_create_nonce( 'campeones_link_' . $rowInX );

        $this->expectException( RedirectTerminatedException::class );
        try {
            $this->makeTestablePage()->handlePost();
        } finally {
            $this->assertStringContainsString(
                'campeones_notice=error_fila_ajena',
                (string) $GLOBALS['_campeones_test_last_redirect'],
                'A row nonce that is valid for its own row must not authorize acting on it under a different titulo_id.'
            );
            $row = $this->squads->find( $rowInX );
            $this->assertSame( 'BASSO, A.', $row->jugadorNombre, 'A cross-title edit must never modify a row belonging to a different title.' );
            $this->assertSame( LinkState::AUTO, $row->estadoVinculo );
            $this->assertSame( 5078, $row->jugadorId );
        }
    }

    public function test_handle_post_eliminar_fila_rejects_a_row_belonging_to_a_different_title(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;

        $otherTitle      = $this->titles->createOrConflict( 2017, 'A', 'campeon', 'RIVER' );
        $rowInOtherTitle = $this->squads->insert( new SquadEntry( $otherTitle->id, 0, 'BASSO, A.' ) );

        $_POST['campeones_editor_action'] = 'eliminar_fila';
        $_POST['titulo_id']               = (string) $this->tituloId; // A stale/bookmarked title, not the row's own.
        $_POST['plantel_id']              = (string) $rowInOtherTitle;
        $_POST['campeones_link_nonce']    = wp_create_nonce( 'campeones_link_' . $rowInOtherTitle );

        $this->expectException( RedirectTerminatedException::class );
        try {
            $this->makeTestablePage()->handlePost();
        } finally {
            $this->assertStringContainsString( 'campeones_notice=error_fila_ajena', (string) $GLOBALS['_campeones_test_last_redirect'] );
            $this->assertNotNull( $this->squads->find( $rowInOtherTitle ), 'A cross-title delete must never remove the other title\'s row.' );
        }
    }

    public function test_handle_post_vincular_rejects_a_row_belonging_to_a_different_title(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;

        $otherTitle      = $this->titles->createOrConflict( 2017, 'A', 'campeon', 'RIVER' );
        $rowInOtherTitle = $this->squads->insert( new SquadEntry( $otherTitle->id, 0, 'ZUBIZARRETA, F.' ) );

        $_POST['campeones_editor_action'] = 'vincular';
        $_POST['titulo_id']               = (string) $this->tituloId;
        $_POST['plantel_id']              = (string) $rowInOtherTitle;
        $_POST['jugador_id']              = '5078';
        $_POST['campeones_link_nonce']    = wp_create_nonce( 'campeones_link_' . $rowInOtherTitle );

        $this->expectException( RedirectTerminatedException::class );
        try {
            $this->makeTestablePage()->handlePost();
        } finally {
            $this->assertStringContainsString( 'campeones_notice=error_fila_ajena', (string) $GLOBALS['_campeones_test_last_redirect'] );
            $row = $this->squads->find( $rowInOtherTitle );
            $this->assertNull( $row->jugadorId, 'A cross-title vincular must never set the pointer.' );
            $this->assertSame( 'sin_candidato', $row->estadoVinculo );
        }
    }

    public function test_handle_post_crear_titulo_catches_a_write_failed_exception(): void {
        $GLOBALS['_campeones_test_current_user_can'] = true;

        global $wpdb;
        $original = $wpdb;
        $failing  = new FailingInsertWpdb();

        try {
            $wpdb = $failing;
            InitialSchema::up();

            $titles = new TitleRepository( $failing );
            $squads = new SquadRepository( $failing );

            $rows      = require __DIR__ . '/../Fixtures/players.php';
            $directory = FakePlayerDirectory::fromFixtureRows( $rows );

            $page = new TestableTitleEditorPage( $titles, $squads, new LinkResolver( $directory ), new LinkWriteService( $squads, $directory ) );

            $_POST['campeones_editor_action'] = 'crear_titulo';
            unset( $_POST['titulo_id'] );
            $_POST['anio']                    = '2099';
            $_POST['zona']                    = 'A';
            $_POST['posicion']                = 'campeon';
            $_POST['equipo_nombre']           = 'BOCA';
            $_POST['campeones_editor_nonce']  = wp_create_nonce( 'campeones_crear_titulo' );

            $this->expectException( RedirectTerminatedException::class );
            try {
                $page->handlePost();
            } finally {
                $this->assertStringContainsString(
                    'campeones_notice=error_directorio',
                    (string) $GLOBALS['_campeones_test_last_redirect'],
                    'A failed insert must redirect with a distinct notice, not fall through to a fatal.'
                );
                $this->assertSame(
                    '0',
                    (string) $failing->get_var( "SELECT COUNT(*) FROM {$failing->prefix}campeones_titulo WHERE anio = 2099" ),
                    'A failed insert must not leave a partial row behind.'
                );
            }
        } finally {
            $wpdb = $original;
            $_POST['titulo_id'] = (string) $this->tituloId;
        }
    }
}

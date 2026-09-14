<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Admin;

use EntreRedes\Campeones\Admin\SquadListTable;
use EntreRedes\Campeones\Linking\LinkState;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SquadListTable (task 3.4). Rendering itself is a
 * WP_List_Table concern the shim stubs as a no-op; verified behaviour here
 * is column definitions, data plumbing, and per-row action markup.
 */
class SquadListTableTest extends TestCase {

    private SquadListTable $table;

    protected function setUp(): void {
        $this->table = new SquadListTable( [ 'singular' => 'jugador', 'plural' => 'jugadores', 'ajax' => false ] );
    }

    public function test_get_columns_contains_the_expected_keys(): void {
        $columns = $this->table->get_columns();

        $this->assertArrayHasKey( 'orden', $columns );
        $this->assertArrayHasKey( 'jugador_nombre', $columns );
        $this->assertArrayHasKey( 'estado_vinculo', $columns );
        $this->assertArrayHasKey( 'vinculado_a', $columns );
        $this->assertArrayHasKey( 'jugador_id', $columns );
        $this->assertArrayHasKey( 'acciones', $columns );
    }

    public function test_set_data_populates_items(): void {
        $rows = [ [ 'id' => 1, 'orden' => 0, 'jugador_nombre' => 'BASSO, A.', 'es_capitan' => false, 'estado_vinculo' => 'auto', 'jugador_id' => 5078 ] ];

        $this->table->setData( $rows, 99 );
        $this->table->prepare_items();

        $this->assertCount( 1, $this->table->items );
    }

    public function test_column_jugador_nombre_marks_the_captain(): void {
        $item = [ 'jugador_nombre' => 'MAZZARA, M.', 'es_capitan' => true ];

        $result = $this->invokeColumnMethod( 'column_jugador_nombre', $item );

        $this->assertStringContainsString( 'MAZZARA, M.', $result );
        $this->assertStringContainsString( '(C)', $result );
    }

    public function test_column_jugador_nombre_has_no_marker_for_a_non_captain(): void {
        $item = [ 'jugador_nombre' => 'BASSO, A.', 'es_capitan' => false ];

        $result = $this->invokeColumnMethod( 'column_jugador_nombre', $item );

        $this->assertSame( 'BASSO, A.', $result );
    }

    public function test_column_estado_vinculo_maps_every_link_state_to_a_label(): void {
        foreach (
            [
                LinkState::AUTO          => 'Automático',
                LinkState::AMBIGUO       => 'Ambiguo',
                LinkState::SIN_CANDIDATO => 'Sin candidato',
                LinkState::MANUAL        => 'Manual',
            ] as $estado => $label
        ) {
            $result = $this->invokeColumnMethod( 'column_estado_vinculo', [ 'estado_vinculo' => $estado ] );
            $this->assertSame( $label, $result );
        }
    }

    public function test_column_acciones_offers_vincular_for_an_unlinked_row(): void {
        $item = [ 'id' => 7, 'jugador_id' => null ];

        $html = $this->invokeColumnMethod( 'column_acciones', $item );

        $this->assertStringContainsString( 'Vincular', $html );
        $this->assertStringContainsString( 'value="vincular"', $html );
        $this->assertStringNotContainsString( 'Desvincular', $html );
        $this->assertStringContainsString( 'Eliminar', $html );
    }

    public function test_column_acciones_offers_cambiar_and_desvincular_for_a_linked_row(): void {
        $item = [ 'id' => 7, 'jugador_id' => 5078 ];

        $html = $this->invokeColumnMethod( 'column_acciones', $item );

        $this->assertStringContainsString( 'Cambiar', $html );
        $this->assertStringContainsString( 'value="cambiar"', $html );
        $this->assertStringContainsString( 'Desvincular', $html );
    }

    public function test_column_acciones_carries_titulo_id_as_a_hidden_field_on_every_form(): void {
        // Item 4: the three action forms only ever carried titulo_id on the
        // form's action="...&titulo_id=..." URL, never as a POST field.
        // handlePost() reads $_POST['titulo_id'], which was therefore always
        // 0, sending the operator to the "Nuevo título" create form after
        // any link/unlink/delete. editar_fila (added by item 3) is already
        // correct — cambiar, desvincular, and eliminar_fila must match it.
        $this->table->setData( [], 42 );
        $item = [ 'id' => 7, 'jugador_id' => 5078, 'jugador_nombre' => 'BASSO, A.', 'orden' => 0 ];

        $html = $this->invokeColumnMethod( 'column_acciones', $item );

        $this->assertSame(
            4,
            substr_count( $html, 'name="titulo_id" value="42"' ),
            'editar_fila, cambiar, desvincular, and eliminar_fila must each POST the hidden titulo_id field.'
        );
    }

    // -------------------------------------------------------------------------
    // "Vinculado a" / "ID" columns — the operator must be able to see, for
    // every row, whether it is linked, to whom, and with which id (task's
    // "Definition of done"). A row with no link shows a dash in both; a set
    // jugador_id with no matching entry in the lookup (a dangling pointer —
    // the player was deleted or unpublished) must say so loudly, never
    // silently render as an empty cell; a directory-wide failure must be
    // reported distinctly from a single dangling pointer.
    // -------------------------------------------------------------------------

    public function test_column_vinculado_a_shows_a_dash_for_an_unlinked_row(): void {
        $result = $this->invokeColumnMethod( 'column_vinculado_a', [ 'jugador_id' => null ] );

        $this->assertSame( '—', $result );
    }

    public function test_column_vinculado_a_shows_the_registered_name_when_looked_up(): void {
        $this->table->setPlayerLookup( [ 5078 => 'Basso, Alejandro' ] );

        $result = $this->invokeColumnMethod( 'column_vinculado_a', [ 'jugador_id' => 5078 ] );

        $this->assertSame( 'Basso, Alejandro', $result );
    }

    public function test_column_vinculado_a_reports_a_dangling_pointer_loudly(): void {
        // jugador_id is set but the batched lookup has nothing for it — the
        // player was deleted or unpublished. A blank cell here would hide
        // exactly the kind of failure this project has already had to fix
        // (runbook-prode-sin-equipo).
        $this->table->setPlayerLookup( [] );

        $result = $this->invokeColumnMethod( 'column_vinculado_a', [ 'jugador_id' => 9999 ] );

        $this->assertStringContainsString( '9999', $result );
        $this->assertStringContainsString( 'no encontrado', $result );
    }

    public function test_column_vinculado_a_reports_a_directory_outage_distinctly_from_a_dangling_pointer(): void {
        $this->table->setPlayerLookup( [], true );

        $result = $this->invokeColumnMethod( 'column_vinculado_a', [ 'jugador_id' => 9999 ] );

        $this->assertStringContainsString( 'directorio', $result );
        $this->assertStringNotContainsString( 'no encontrado', $result );
    }

    public function test_column_jugador_id_shows_a_dash_for_an_unlinked_row(): void {
        $result = $this->invokeColumnMethod( 'column_jugador_id', [ 'jugador_id' => null ] );

        $this->assertSame( '—', $result );
    }

    public function test_column_jugador_id_shows_the_raw_id_for_a_linked_row(): void {
        // Shown plainly, on purpose (not just implied by the name), so the
        // operator can copy it into another row's "ID jugador" field to
        // relink by hand.
        $result = $this->invokeColumnMethod( 'column_jugador_id', [ 'jugador_id' => 5078 ] );

        $this->assertSame( '5078', $result );
    }

    public function test_column_jugador_id_shows_the_raw_id_even_for_a_dangling_pointer(): void {
        // The id must stay visible even when the name lookup fails — that is
        // the whole point of showing it plainly.
        $this->table->setPlayerLookup( [] );

        $result = $this->invokeColumnMethod( 'column_jugador_id', [ 'jugador_id' => 9999 ] );

        $this->assertSame( '9999', $result );
    }

    // -------------------------------------------------------------------------
    // Cramped actions column, found in real use on staging: the captain
    // checkbox label ran into "Guardar" with no space ("CapitánGuardar"),
    // the "ID jugador" placeholder was cut to "ID juga" by too narrow a
    // field, and "Cambiar Desvincular Eliminar" had no visual separator.
    // -------------------------------------------------------------------------

    public function test_column_acciones_does_not_glue_the_captain_label_to_the_guardar_button(): void {
        $item = [ 'id' => 7, 'jugador_id' => null, 'jugador_nombre' => 'BASSO, A.', 'es_capitan' => true, 'orden' => 0 ];

        $html = $this->invokeColumnMethod( 'column_acciones', $item );

        // The Capitán <label> is followed by a hidden (invisible) "orden"
        // input, then the Guardar button — a browser renders "Capitán"
        // immediately butted against "Guardar" unless a real space
        // separates the last visible element from <button>.
        $this->assertMatchesRegularExpression(
            '/Capitán<\/label>.*?\s<button/s',
            $html,
            'A missing space here renders as "CapitánGuardar" in the browser.'
        );
        $this->assertStringNotContainsString(
            '"><button',
            $html,
            'No hidden field may be glued directly to the following <button> with no separating space.'
        );
    }

    public function test_column_acciones_id_jugador_field_is_wide_enough_for_its_placeholder(): void {
        $item = [ 'id' => 7, 'jugador_id' => null ];

        $html = $this->invokeColumnMethod( 'column_acciones', $item );

        $this->assertStringContainsString( 'placeholder="ID jugador"', $html );
        $this->assertStringNotContainsString( 'width:6em', $html );
    }

    public function test_column_acciones_separates_action_forms_with_a_visible_separator(): void {
        // Linked row: editar, cambiar, desvincular, eliminar — 4 forms, 3
        // separators.
        $item = [ 'id' => 7, 'jugador_id' => 5078, 'jugador_nombre' => 'BASSO, A.', 'orden' => 0 ];

        $html = $this->invokeColumnMethod( 'column_acciones', $item );

        $this->assertSame(
            3,
            substr_count( $html, ' | ' ),
            'Cambiar / Desvincular / Eliminar must not run together with no separation.'
        );
    }

    public function test_no_items_message(): void {
        ob_start();
        $this->table->no_items();
        $output = ob_get_clean();

        $this->assertNotSame( '', $output );
    }

    /**
     * @param array<string, mixed> $item
     */
    private function invokeColumnMethod( string $method, array $item ): string {
        $ref = new \ReflectionMethod( $this->table, $method );
        return (string) $ref->invoke( $this->table, $item );
    }
}

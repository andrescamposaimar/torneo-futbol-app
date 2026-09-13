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

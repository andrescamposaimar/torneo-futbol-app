<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Admin;

use EntreRedes\Campeones\Admin\TitlesListTable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TitlesListTable (task 3.3). Rendering itself (display())
 * is a WP_List_Table concern the shim stubs out as a no-op — verified
 * behaviour here is column definitions, data plumbing, and the per-row
 * action markup, mirroring entre-redes-prode's PredictionsListTableTest.
 */
class TitlesListTableTest extends TestCase {

    private TitlesListTable $table;

    protected function setUp(): void {
        $this->table = new TitlesListTable( [ 'singular' => 'titulo', 'plural' => 'titulos', 'ajax' => false ] );
    }

    public function test_get_columns_contains_the_expected_keys(): void {
        $columns = $this->table->get_columns();

        $this->assertArrayHasKey( 'anio', $columns );
        $this->assertArrayHasKey( 'zona', $columns );
        $this->assertArrayHasKey( 'posicion', $columns );
        $this->assertArrayHasKey( 'equipo_nombre', $columns );
        $this->assertArrayHasKey( 'acciones', $columns );
    }

    public function test_set_data_populates_items(): void {
        $rows = [
            [ 'id' => 1, 'anio' => 2016, 'zona' => 'A', 'posicion' => 'campeon', 'equipo_nombre' => 'CHELSEA' ],
        ];

        $this->table->setData( $rows, 1 );
        $this->table->prepare_items();

        $this->assertCount( 1, $this->table->items );
    }

    public function test_set_data_with_empty_rows(): void {
        $this->table->setData( [], 0 );
        $this->table->prepare_items();

        $this->assertSame( [], $this->table->items );
    }

    public function test_column_default_returns_escaped_value(): void {
        $item = [ 'anio' => 2016 ];
        $this->assertSame( '2016', $this->invokeColumnMethod( 'column_default', $item, 'anio' ) );
    }

    public function test_column_acciones_contains_editar_revalidar_and_eliminar(): void {
        $item = [ 'id' => 42, 'anio' => 2016, 'zona' => 'A', 'posicion' => 'campeon', 'equipo_nombre' => 'CHELSEA' ];

        $html = $this->invokeColumnMethod( 'column_acciones', $item );

        $this->assertStringContainsString( 'campeones-titulo-edit', $html );
        $this->assertStringContainsString( 'Revalidar', $html );
        $this->assertStringContainsString( 'Eliminar', $html );
        // The row's identity is carried through so each per-row nonce/form
        // targets exactly this title, never a sibling row.
        $this->assertStringContainsString( '42', $html );
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
    private function invokeColumnMethod( string $method, array $item, mixed ...$extra ): string {
        $ref = new \ReflectionMethod( $this->table, $method );
        return (string) $ref->invoke( $this->table, $item, ...$extra );
    }
}

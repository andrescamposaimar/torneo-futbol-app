<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas\Eleccion;

use EntreRedes\Cambios\Plazas\Eleccion\EleccionOverrides;
use PHPUnit\Framework\TestCase;

class EleccionOverridesTest extends TestCase {

    public function test_parses_a_normalized_name_to_player_id_map(): void {
        $csv = "nombre_excel,titular_player_id\n"
            . "\"Perez, Juan\",111\n"
            . "\"Gomez , Maria\",222\n";

        $overrides = EleccionOverrides::parse( $csv );

        $this->assertSame( 111, $overrides['PEREZ, JUAN'] );
        $this->assertSame( 222, $overrides['GOMEZ , MARIA'] );
    }

    public function test_comment_rows_are_skipped(): void {
        $csv = "nombre_excel,titular_player_id\n"
            . "# esto es un comentario,999\n"
            . "\"Perez, Juan\",111\n";

        $overrides = EleccionOverrides::parse( $csv );

        $this->assertCount( 1, $overrides );
    }

    public function test_conflicting_duplicate_throws(): void {
        $csv = "nombre_excel,titular_player_id\n"
            . "\"Perez, Juan\",111\n"
            . "\"Perez, Juan\",222\n";

        $this->expectException( \InvalidArgumentException::class );
        EleccionOverrides::parse( $csv );
    }

    public function test_same_duplicate_value_does_not_throw(): void {
        $csv = "nombre_excel,titular_player_id\n"
            . "\"Perez, Juan\",111\n"
            . "\"Perez, Juan\",111\n";

        $overrides = EleccionOverrides::parse( $csv );

        $this->assertSame( 111, $overrides['PEREZ, JUAN'] );
    }

    public function test_non_numeric_id_throws(): void {
        $csv = "nombre_excel,titular_player_id\n"
            . "\"Perez, Juan\",abc\n";

        $this->expectException( \InvalidArgumentException::class );
        EleccionOverrides::parse( $csv );
    }

    public function test_missing_header_column_throws(): void {
        $this->expectException( \InvalidArgumentException::class );
        EleccionOverrides::parse( "nombre_excel\nPerez\n" );
    }

    public function test_empty_content_returns_empty_map(): void {
        $this->assertSame( [], EleccionOverrides::parse( '' ) );
    }
}

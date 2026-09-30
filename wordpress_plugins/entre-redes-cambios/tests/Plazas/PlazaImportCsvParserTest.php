<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas;

use EntreRedes\Cambios\Plazas\PlazaImportCsvParser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PlazaImportCsvParser — pure string parsing, no \wpdb and no
 * WordPress shim needed at all (see that class's own docblock).
 */
class PlazaImportCsvParserTest extends TestCase {

    public function test_parses_a_well_formed_file(): void {
        $csv = "equipo,titular,tipo,puntaje_techo\n"
            . "Boca Juniors,Juan Perez,campo,3\n"
            . "12,777,suplente,\"2,5\"\n";

        $rows = PlazaImportCsvParser::parse( $csv );

        $this->assertCount( 2, $rows );

        $this->assertSame( 2, $rows[0]['line'] );
        $this->assertSame( 'Boca Juniors', $rows[0]['equipo'] );
        $this->assertSame( 'Juan Perez', $rows[0]['titular'] );
        $this->assertSame( 'campo', $rows[0]['tipo'] );
        $this->assertSame( '3', $rows[0]['puntaje_techo'] );

        $this->assertSame( 3, $rows[1]['line'] );
        $this->assertSame( '12', $rows[1]['equipo'] );
        $this->assertSame( '777', $rows[1]['titular'] );
        $this->assertSame( 'suplente', $rows[1]['tipo'] );
        $this->assertSame( '2,5', $rows[1]['puntaje_techo'] );
    }

    public function test_header_matching_is_case_insensitive_and_order_free(): void {
        $csv = "Puntaje_Techo,TIPO,Titular,EQUIPO\n"
            . "3,campo,777,12\n";

        $rows = PlazaImportCsvParser::parse( $csv );

        $this->assertSame( '12', $rows[0]['equipo'] );
        $this->assertSame( '777', $rows[0]['titular'] );
        $this->assertSame( 'campo', $rows[0]['tipo'] );
        $this->assertSame( '3', $rows[0]['puntaje_techo'] );
    }

    public function test_extra_columns_are_accepted_and_ignored(): void {
        $csv = "equipo,titular,tipo,puntaje_techo,notas\n"
            . "12,777,campo,3,revisar mas tarde\n";

        $rows = PlazaImportCsvParser::parse( $csv );

        $this->assertCount( 1, $rows );
        $this->assertSame( '12', $rows[0]['equipo'] );
    }

    public function test_skips_comment_rows_and_blank_rows(): void {
        $csv = "equipo,titular,tipo,puntaje_techo\n"
            . "# esto es un ejemplo, se ignora\n"
            . "12,777,campo,3\n"
            . "\n"
            . "13,888,suplente,2\n";

        $rows = PlazaImportCsvParser::parse( $csv );

        $this->assertCount( 2, $rows );
        // Line numbers still reflect the ORIGINAL file, comments included.
        $this->assertSame( 3, $rows[0]['line'] );
        $this->assertSame( 5, $rows[1]['line'] );
    }

    public function test_strips_a_leading_utf8_bom(): void {
        $csv = "\xEF\xBB\xBFequipo,titular,tipo,puntaje_techo\n12,777,campo,3\n";

        $rows = PlazaImportCsvParser::parse( $csv );

        $this->assertCount( 1, $rows );
        $this->assertSame( '12', $rows[0]['equipo'] );
    }

    public function test_throws_on_an_empty_file(): void {
        $this->expectException( \InvalidArgumentException::class );

        PlazaImportCsvParser::parse( '' );
    }

    public function test_throws_when_a_required_column_is_missing(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/titular/' );

        PlazaImportCsvParser::parse( "equipo,tipo,puntaje_techo\n12,campo,3\n" );
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas\Alta;

use EntreRedes\Cambios\Plazas\Alta\TitularesListParser;
use PHPUnit\Framework\TestCase;

/**
 * Pure format tests for TitularesListParser — no `\wpdb`, no WordPress. See
 * that class's own docblock for the exact division of labor between this
 * parser (FORMAT only) and TitularesListImporter (every DB-backed check).
 */
class TitularesListParserTest extends TestCase {

    private const HEADER = "team_id,equipo,titular_player_id,puntaje_techo,es_capitan\n";

    public function test_a_well_formed_row_parses_cleanly(): void {
        $csv = self::HEADER . "9001,EQUIPO A,5001,2.5,1\n";

        $result = TitularesListParser::parse( $csv );

        $this->assertSame( [], $result['errors'] );
        $this->assertCount( 1, $result['rows'] );
        $this->assertSame( [
            'line'              => 2,
            'team_id'           => 9001,
            'equipo'            => 'EQUIPO A',
            'titular_player_id' => 5001,
            'puntaje_raw'       => '2.5',
            'es_capitan'        => true,
        ], $result['rows'][0] );
    }

    public function test_the_equipo_column_is_optional(): void {
        $csv = "team_id,titular_player_id,puntaje_techo,es_capitan\n9001,5001,2.5,1\n";

        $result = TitularesListParser::parse( $csv );

        $this->assertSame( [], $result['errors'] );
        $this->assertSame( '', $result['rows'][0]['equipo'] );
    }

    public function test_a_comment_row_and_a_blank_line_are_skipped(): void {
        $csv = self::HEADER . "# esto es un comentario\n9001,EQUIPO A,5001,2.5,1\n\n";

        $result = TitularesListParser::parse( $csv );

        $this->assertSame( [], $result['errors'] );
        $this->assertCount( 1, $result['rows'] );
    }

    public function test_missing_required_column_throws(): void {
        $csv = "team_id,titular_player_id,puntaje_techo\n9001,5001,2.5\n";

        $this->expectException( \InvalidArgumentException::class );
        TitularesListParser::parse( $csv );
    }

    public function test_an_empty_file_throws(): void {
        $this->expectException( \InvalidArgumentException::class );
        TitularesListParser::parse( '' );
    }

    public function test_a_non_numeric_team_id_is_a_row_error_and_excludes_the_row(): void {
        $csv = self::HEADER . "abc,EQUIPO A,5001,2.5,1\n";

        $result = TitularesListParser::parse( $csv );

        $this->assertSame( [], $result['rows'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'team_id', $result['errors'][0] );
    }

    public function test_a_non_numeric_titular_player_id_is_a_row_error(): void {
        $csv = self::HEADER . "9001,EQUIPO A,abc,2.5,1\n";

        $result = TitularesListParser::parse( $csv );

        $this->assertSame( [], $result['rows'] );
        $this->assertStringContainsString( 'titular_player_id', $result['errors'][0] );
    }

    public function test_an_empty_puntaje_is_a_row_error(): void {
        $csv = self::HEADER . "9001,EQUIPO A,5001,,1\n";

        $result = TitularesListParser::parse( $csv );

        $this->assertSame( [], $result['rows'] );
        $this->assertStringContainsString( 'puntaje_techo', $result['errors'][0] );
    }

    /**
     * @dataProvider invalidEsCapitanProvider
     */
    public function test_es_capitan_must_be_exactly_0_or_1( string $value ): void {
        $csv = self::HEADER . "9001,EQUIPO A,5001,2.5,{$value}\n";

        $result = TitularesListParser::parse( $csv );

        $this->assertSame( [], $result['rows'] );
        $this->assertStringContainsString( 'es_capitan', $result['errors'][0] );
    }

    /** @return array<int, array{0:string}> */
    public static function invalidEsCapitanProvider(): array {
        return [ [ 'true' ], [ 'si' ], [ '2' ], [ '' ] ];
    }

    public function test_every_row_problem_is_collected_not_just_the_first(): void {
        $csv = self::HEADER
            . "abc,EQUIPO A,5001,2.5,1\n"
            . "9002,EQUIPO B,def,2.5,1\n"
            . "9003,EQUIPO C,5003,2.5,2\n";

        $result = TitularesListParser::parse( $csv );

        $this->assertSame( [], $result['rows'] );
        $this->assertCount( 3, $result['errors'] );
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas\Eleccion;

use EntreRedes\Cambios\Plazas\Eleccion\EleccionSheetParser;
use PHPUnit\Framework\TestCase;

/**
 * Fixture rows reproduce the REAL shapes the task brief names — interleaved
 * repeated header rows, a team with fewer than 11 rows — using invented
 * names, never the real spreadsheet.
 */
class EleccionSheetParserTest extends TestCase {

    // -------------------------------------------------------------------------
    // "x Equipo"
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<int, string>>
     */
    private function equipoRow( string $vuelta, string $equipo, string $nombre ): array {
        return [ $vuelta, $equipo, '999', $nombre, '', '', '' ];
    }

    private function equipoHeader(): array {
        return [ 'Vuelta', 'Equipo', 'id', 'Nombre', 'Celular', 'mail', 'Fijo' ];
    }

    public function test_parses_one_complete_team_of_11(): void {
        $rows   = [ $this->equipoHeader() ];
        $rows[] = $this->equipoRow( 'CAP', 'Boca', 'Perez, Juan' );
        for ( $i = 1; $i <= 10; $i++ ) {
            $rows[] = $this->equipoRow( (string) $i, 'Boca', "Jugador {$i}, Nombre" );
        }

        $result = EleccionSheetParser::parseEquipoSheet( $rows );

        $this->assertSame( [], $result['errors'] );
        $this->assertCount( 1, $result['teams'] );
        $this->assertSame( 'Boca', $result['teams'][0]['equipo'] );
        $this->assertCount( 11, $result['teams'][0]['titulares'] );
        $this->assertSame( 'Perez, Juan', $result['teams'][0]['titulares']['CAP'] );
        $this->assertSame( 'Jugador 5, Nombre', $result['teams'][0]['titulares']['5'] );
    }

    public function test_skips_interleaved_repeated_header_rows_between_teams(): void {
        $rows = [ $this->equipoHeader() ];
        $rows = array_merge( $rows, $this->elevenRows( 'Boca' ) );
        $rows[] = $this->equipoHeader(); // Repeated header row, mid-file.
        $rows   = array_merge( $rows, $this->elevenRows( 'River' ) );

        $result = EleccionSheetParser::parseEquipoSheet( $rows );

        $this->assertSame( [], $result['errors'] );
        $this->assertCount( 2, $result['teams'] );
        $this->assertSame( 'Boca', $result['teams'][0]['equipo'] );
        $this->assertSame( 'River', $result['teams'][1]['equipo'] );
    }

    public function test_a_team_with_fewer_than_11_rows_is_a_hard_error(): void {
        $rows = [ $this->equipoHeader() ];
        $rows[] = $this->equipoRow( 'CAP', 'Boca', 'Perez, Juan' );
        for ( $i = 1; $i <= 8; $i++ ) { // Only 8 field rows -> 9 total, not 11.
            $rows[] = $this->equipoRow( (string) $i, 'Boca', "Jugador {$i}, Nombre" );
        }

        $result = EleccionSheetParser::parseEquipoSheet( $rows );

        $this->assertSame( [], $result['teams'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'Boca', $result['errors'][0] );
        $this->assertStringContainsString( '9', $result['errors'][0] );
    }

    public function test_a_team_with_a_duplicated_vuelta_is_a_hard_error(): void {
        $rows = [ $this->equipoHeader() ];
        $rows[] = $this->equipoRow( 'CAP', 'Boca', 'Perez, Juan' );
        $rows[] = $this->equipoRow( '1', 'Boca', 'Jugador Uno, Nombre' );
        $rows[] = $this->equipoRow( '1', 'Boca', 'Jugador Otro, Nombre' ); // Duplicate vuelta '1'.
        for ( $i = 2; $i <= 9; $i++ ) {
            $rows[] = $this->equipoRow( (string) $i, 'Boca', "Jugador {$i}, Nombre" );
        }

        $result = EleccionSheetParser::parseEquipoSheet( $rows );

        $this->assertSame( [], $result['teams'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'Vuelta', $result['errors'][0] );
    }

    public function test_blank_rows_between_teams_are_skipped(): void {
        $rows = [ $this->equipoHeader() ];
        $rows = array_merge( $rows, $this->elevenRows( 'Boca' ) );
        $rows[] = [ '', '', '', '', '', '', '' ];
        $rows   = array_merge( $rows, $this->elevenRows( 'River' ) );

        $result = EleccionSheetParser::parseEquipoSheet( $rows );

        $this->assertSame( [], $result['errors'] );
        $this->assertCount( 2, $result['teams'] );
    }

    public function test_missing_required_column_throws(): void {
        $this->expectException( \InvalidArgumentException::class );

        EleccionSheetParser::parseEquipoSheet( [ [ 'Vuelta', 'Equipo' ] ] );
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function elevenRows( string $equipo ): array {
        $rows   = [ $this->equipoRow( 'CAP', $equipo, "Capitan {$equipo}, Nombre" ) ];
        for ( $i = 1; $i <= 10; $i++ ) {
            $rows[] = $this->equipoRow( (string) $i, $equipo, "Jugador {$equipo} {$i}, Nombre" );
        }
        return $rows;
    }

    // -------------------------------------------------------------------------
    // "Titulares eleccion con datos"
    // -------------------------------------------------------------------------

    public function test_parses_puntajes_keyed_by_normalized_name(): void {
        $rows = [
            [ 'Orden', 'Apellido y Nombre', 'Posicion', 'Puntaje', 'Equipo' ],
            [ '1', 'Perez, Juan', 'Defensor', '3', 'Boca' ],
            [ '2', 'Sinclair , Juan Martin', 'Mediocampista', '2,5', 'River' ],
        ];

        $result = EleccionSheetParser::parseTitularesSheet( $rows );

        $this->assertSame( [], $result['errors'] );
        $this->assertSame( '3', $result['puntajes']['PEREZ, JUAN'] );
        $this->assertSame( '2,5', $result['puntajes']['SINCLAIR , JUAN MARTIN'] );
    }

    public function test_conflicting_duplicate_name_is_an_error(): void {
        $rows = [
            [ 'Orden', 'Apellido y Nombre', 'Posicion', 'Puntaje', 'Equipo' ],
            [ '1', 'Perez, Juan', 'Defensor', '3', 'Boca' ],
            [ '2', 'Perez, Juan', 'Defensor', '4', 'Boca' ],
        ];

        $result = EleccionSheetParser::parseTitularesSheet( $rows );

        $this->assertNotEmpty( $result['errors'] );
    }

    public function test_blank_rows_are_skipped(): void {
        $rows = [
            [ 'Orden', 'Apellido y Nombre', 'Posicion', 'Puntaje', 'Equipo' ],
            [ '', '', '', '', '' ],
            [ '1', 'Perez, Juan', 'Defensor', '3', 'Boca' ],
        ];

        $result = EleccionSheetParser::parseTitularesSheet( $rows );

        $this->assertSame( [], $result['errors'] );
        $this->assertCount( 1, $result['puntajes'] );
    }
}

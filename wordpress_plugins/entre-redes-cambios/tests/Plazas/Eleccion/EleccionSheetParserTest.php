<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas\Eleccion;

use EntreRedes\Cambios\Plazas\Eleccion\EleccionSheetParser;
use PHPUnit\Framework\TestCase;

/**
 * Fixture rows reproduce the REAL shape of "GRILLA ELECCION" the task brief
 * names and this class's own docblock documents — three columns per team,
 * the team name on row 2, the captain on row 3, rounds 1..10 on rows
 * 5,7,...,23, a placeholder block showing a formula-error token, and a
 * trailing empty block — using invented names, never the real spreadsheet.
 */
class EleccionSheetParserTest extends TestCase {

    // -------------------------------------------------------------------------
    // "GRILLA ELECCION"
    // -------------------------------------------------------------------------

    /**
     * Builds one team's block of TWO columns (id, nombre): row 0 => id
     * counter (unused by the parser), row 1 => team name, row 2 => captain,
     * rows 3..22 alternate a puntaje-shaped value (unused by the parser) and
     * a round's (id, nombre) pair, matching the real workbook's shape.
     *
     * @param array<string, string> $nombres vuelta ('CAP','1'..'10') => nombre.
     * @param array<string, string> $ids vuelta => internal id (defaults to a
     *        unique counter per slot when not given).
     * @return array<int, array{0: string, 1: string}> 23 rows, 2 columns each.
     */
    private function grillaBlock( string $equipo, array $nombres = [], array $ids = [], int $idBase = 100 ): array {
        $vueltas = array_merge( [ 'CAP' ], array_map( 'strval', range( 1, 10 ) ) );

        $block   = array_fill( 0, 23, [ '', '' ] );
        $block[0] = [ '1', '' ];
        $block[1] = [ $equipo, '' ];

        foreach ( $vueltas as $k => $vuelta ) {
            $row    = 2 + 2 * ( 'CAP' === $vuelta ? 0 : (int) $vuelta );
            $nombre = $nombres[ $vuelta ] ?? "Jugador {$equipo} {$vuelta}, Nombre";
            $id     = $ids[ $vuelta ] ?? (string) ( $idBase + $k );
            $block[ $row ] = [ $id, $nombre ];
            if ( $row + 1 < 23 ) {
                $block[ $row + 1 ] = [ '', '3.5' ]; // Puntaje-shaped filler; never read by the parser.
            }
        }

        return $block;
    }

    /**
     * @param array<int, array<int, array{0: string, 1: string}>> $blocks Each
     *        block is one `grillaBlock()` (or a raw 23x2 placeholder block).
     * @return array<int, array<int, string>>
     */
    private function assembleGrilla( array $blocks ): array {
        $rowCount = 23;
        $rows     = array_fill( 0, $rowCount, [ '' ] ); // Column A, unused.

        foreach ( $blocks as $block ) {
            for ( $r = 0; $r < $rowCount; $r++ ) {
                $rows[ $r ] = array_merge( $rows[ $r ], $block[ $r ], [ '' ] ); // +1 gap column.
            }
        }

        return $rows;
    }

    /** A placeholder block: every cell blank (the real workbook's trailing, unused blocks). */
    private function emptyBlock(): array {
        return array_fill( 0, 23, [ '', '' ] );
    }

    /** A placeholder block whose team-name cell is a literal Excel error token. */
    private function errorBlock(): array {
        $block    = array_fill( 0, 23, [ '', '' ] );
        $block[1] = [ '#ERROR!', '' ];
        return $block;
    }

    /**
     * @return array<int, array<int, array{0: string, 1: string}>>
     */
    private function thirtyValidTeams(): array {
        $blocks = [];
        for ( $i = 1; $i <= 30; $i++ ) {
            $blocks[] = $this->grillaBlock( "Equipo {$i}", [], [], 1000 * $i );
        }
        return $blocks;
    }

    public function test_parses_thirty_complete_teams_of_eleven(): void {
        $rows = $this->assembleGrilla( $this->thirtyValidTeams() );

        $result = EleccionSheetParser::parseGrillaSheet( $rows );

        $this->assertSame( [], $result['errors'] );
        $this->assertCount( 30, $result['teams'] );
        $this->assertSame( 'Equipo 1', $result['teams'][0]['equipo'] );
        $this->assertCount( 11, $result['teams'][0]['titulares'] );
        $this->assertSame( 'Jugador Equipo 1 CAP, Nombre', $result['teams'][0]['titulares']['CAP'] );
        $this->assertSame( 'Jugador Equipo 1 5, Nombre', $result['teams'][0]['titulares']['5'] );
    }

    public function test_skips_error_token_placeholder_block(): void {
        $blocks   = $this->thirtyValidTeams();
        $blocks[] = $this->errorBlock(); // The 31st block: a broken-formula placeholder.
        $rows     = $this->assembleGrilla( $blocks );

        $result = EleccionSheetParser::parseGrillaSheet( $rows );

        $this->assertSame( [], $result['errors'] );
        $this->assertCount( 30, $result['teams'] );
    }

    public function test_skips_trailing_empty_block(): void {
        $blocks   = $this->thirtyValidTeams();
        $blocks[] = $this->emptyBlock(); // The 31st block: entirely blank, laid out for a team that does not exist.
        $rows     = $this->assembleGrilla( $blocks );

        $result = EleccionSheetParser::parseGrillaSheet( $rows );

        $this->assertSame( [], $result['errors'] );
        $this->assertCount( 30, $result['teams'] );
    }

    public function test_a_team_with_ten_names_is_a_hard_error(): void {
        $blocks    = $this->thirtyValidTeams();
        $blocks[0] = $this->grillaBlock( 'Equipo Incompleto', [ '10' => '' ] ); // Round 10 blank.
        $rows      = $this->assembleGrilla( $blocks );

        $result = EleccionSheetParser::parseGrillaSheet( $rows );

        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'Equipo Incompleto', $result['errors'][0] );
        // The broken team is excluded, never a partial roster; the other 29 are unaffected here —
        // it is `EleccionImporter::planificar()` that refuses to write ANYTHING while `errors` is non-empty.
        $labels = array_column( $result['teams'], 'equipo' );
        $this->assertNotContains( 'Equipo Incompleto', $labels );
        $this->assertCount( 29, $result['teams'] );
    }

    public function test_a_person_duplicated_across_two_teams_by_id_is_a_hard_error(): void {
        $blocks    = $this->thirtyValidTeams();
        // Reuse team 1's captain id as team 2's round-1 id — same internal id, different name typed.
        $blocks[1] = $this->grillaBlock( 'Equipo 2', [ '1' => 'Otro Jugador, Nombre' ], [ '1' => '1000' ] );
        $rows      = $this->assembleGrilla( $blocks );

        $result = EleccionSheetParser::parseGrillaSheet( $rows );

        $errorText = implode( "\n", $result['errors'] );
        $this->assertStringContainsString( 'Equipo 1', $errorText );
        $this->assertStringContainsString( 'Equipo 2', $errorText );
        $this->assertStringContainsString( '1000', $errorText );
    }

    public function test_a_person_duplicated_across_two_teams_by_name_is_a_hard_error(): void {
        $blocks    = $this->thirtyValidTeams();
        // Team 2's round-1 slot: same normalized name as team 1's captain, different id.
        $blocks[1] = $this->grillaBlock( 'Equipo 2', [ '1' => 'Jugador Equipo 1 CAP, Nombre' ] );
        $rows      = $this->assembleGrilla( $blocks );

        $result = EleccionSheetParser::parseGrillaSheet( $rows );

        $errorText = implode( "\n", $result['errors'] );
        $this->assertStringContainsString( 'Equipo 1', $errorText );
        $this->assertStringContainsString( 'Equipo 2', $errorText );
        $this->assertStringContainsString( 'Jugador Equipo 1 CAP, Nombre', $errorText );
    }

    public function test_fewer_than_the_expected_team_blocks_is_a_hard_error(): void {
        $blocks = array_slice( $this->thirtyValidTeams(), 0, 5 ); // Only 5 of the expected 30.
        $rows   = $this->assembleGrilla( $blocks );

        $result = EleccionSheetParser::parseGrillaSheet( $rows );

        $this->assertNotEmpty( $result['errors'] );
        $errorText = implode( "\n", $result['errors'] );
        $this->assertStringContainsString( '5', $errorText );
        $this->assertStringContainsString( '30', $errorText );
    }

    public function test_empty_sheet_throws(): void {
        $this->expectException( \InvalidArgumentException::class );

        EleccionSheetParser::parseGrillaSheet( [] );
    }

    /**
     * REGRESSION for the bug this feature fixes: on the real March 2026
     * workbook, "x Equipo" resolved COLOMBIA's round-10 titular to
     * "CHIESA, GUSTAVO ALEJANDRO" (actually JAMAICA's player) instead of
     * "CAMINADA, FERNANDO" — COLOMBIA's real titular per "GRILLA ELECCION"
     * (confirmed against the process owner). This fixture reproduces that
     * exact disagreement in miniature: a team ("Colombia") whose "GRILLA
     * ELECCION"-shaped round-10 slot names the CORRECT person, standing in
     * for what "x Equipo" would have said instead. Since this parser never
     * reads "x Equipo" at all (see class docblock), asserting the resolved
     * name here is a permanent guard against "simplifying" this importer
     * back onto that broken sheet.
     */
    public function test_regression_grid_disagreement_follows_grilla_not_x_equipo(): void {
        $blocks    = $this->thirtyValidTeams();
        $blocks[8] = $this->grillaBlock( 'Colombia', [ '10' => 'Caminada, Fernando' ] ); // 9th team, matches the real workbook's position.
        $rows      = $this->assembleGrilla( $blocks );

        $result = EleccionSheetParser::parseGrillaSheet( $rows );

        $this->assertSame( [], $result['errors'] );
        $colombia = array_values( array_filter( $result['teams'], static fn ( array $t ): bool => 'Colombia' === $t['equipo'] ) )[0];
        $this->assertSame( 'Caminada, Fernando', $colombia['titulares']['10'] );
        $this->assertNotSame( 'Chiesa, Gustavo Alejandro', $colombia['titulares']['10'] );
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

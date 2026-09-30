<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas\Eleccion;

/**
 * Pure, WordPress-free parsing of the two sheets of the March 2026 election
 * spreadsheet — no `\wpdb`, no file I/O of its own. `tools/importar-eleccion.php`
 * reads the `.xlsx` file (via PhpSpreadsheet) and hands each sheet to this
 * class as a plain 2D array of cell strings, row 0 / column 0 being
 * spreadsheet row 1 / column A — the exact shape
 * `Spreadsheet::getSheetByName( $name )->toArray( null, true, true, false )`
 * returns. This mirrors `Plazas\PlazaImportCsvParser`'s role in the importer
 * this feature replaces: turn raw rows into validated arrays, never touch a
 * database.
 *
 * *** WHY "GRILLA ELECCION", NEVER "x Equipo" ***
 * The workbook has a third sheet, "x Equipo", laid out as 11 rows per team
 * with a `Vuelta`/`Equipo`/`Nombre` header — an EASIER shape to parse than
 * the one below, which is exactly why it must never be revived. Every one of
 * its cells is a formula chasing "GRILLA ELECCION" (`=T('GRILLA
 * ELECCION'!$B$2)` and similar), and it is independently confirmed BROKEN in
 * two ways on the real March 2026 workbook:
 *   1. It resolves two blocks to the WRONG person: COLOMBIA's and COSTA
 *      RICA's round-10 titular both come out as someone else's player
 *      (JAMAICA's and KOSOVO's, respectively) — confirmed against the
 *      process owner, who supplied the correct names independently.
 *      "GRILLA ELECCION" has the right person in both cases.
 *   2. Its formulas for the sheet's own placeholder team blocks are literal
 *      Excel `#REF!` errors (a column got deleted from "GRILLA ELECCION"
 *      after "x Equipo" was built, and its formulas were never repaired) —
 *      PhpSpreadsheet does not silently coerce these to a blank value, it
 *      THROWS trying to calculate them. A sheet that cannot even be read
 *      end-to-end is not a source of truth, it is a stale, unmaintained
 *      derived view. This class refuses to read it at all — there is no
 *      `parseEquipoSheet()` here, on purpose, so nobody can "simplify" this
 *      importer back onto the easier, wrong sheet.
 *
 * *** SHEET "GRILLA ELECCION": THE DRAFT BOARD ITSELF ***
 * Teams run across COLUMNS, three columns per team, verified against the
 * real workbook:
 *   - Row 2 (array index 1) holds the team name, at spreadsheet columns
 *     B, E, H, K, ... — every third column, starting at column B (array
 *     index `self::GRILLA_FIRST_TEAM_COLUMN`). That column and the one
 *     immediately after it are the block's two data columns for the whole
 *     team (id column, name column).
 *   - Row 3 (array index 2) is the captain: internal id in the block's id
 *     column, name in the one after it.
 *   - Rounds 1..10 are on rows 5, 7, 9, ..., 23 (array indices 4, 6, ..., 22)
 *     — round `k` is row `3 + 2k`, same id/name column pair. The row
 *     between each of these (4, 6, 8, ...) carries a numeric value in the
 *     NAME column — this is that titular's puntaje, but it is a VLOOKUP
 *     against "Titulares eleccion con datos" by the same internal id, i.e.
 *     the exact same source `parseTitularesSheet()` already reads directly.
 *     Cross-checking the two would be circular (comparing a value against
 *     itself, one hop removed), not an independent verification, so this
 *     class does not read those cells at all.
 *   - The sheet is laid out for MORE team blocks than exist this season
 *     (32-wide, 30 real, confirmed on the real workbook — the trailing
 *     blocks are simply blank; a block could also show a spreadsheet error
 *     token like `#ERROR!`/`#REF!` if a formula there fails to resolve).
 *     Either shape is a placeholder for a team that does not exist and is
 *     skipped, never counted as data and never a source of an error on its
 *     own.
 * The team-name cells are ALSO formulas (VLOOKUP against "Titulares eleccion
 * con datos" by an internal id stored in the id column) — that does not
 * make this sheet another "x Equipo": PhpSpreadsheet computes every one of
 * them without throwing, and the two blocks "x Equipo" gets wrong both
 * resolve correctly here (verified against the process owner, see above).
 *
 * A team whose 11 slots (CAP + rounds 1..10) do not each hold a non-empty
 * name, or that contains the same person (by internal id OR by normalized
 * name — either can happen if the id was mistyped) more than once, is a
 * hard error naming that team — never a partial roster, never silently
 * dropped (see `EleccionImporter`'s own class docblock, "VALIDATE THE WHOLE
 * FILE FIRST"). The SAME person appearing as a titular in TWO DIFFERENT
 * teams is likewise a hard error naming both teams — this is exactly the
 * defect that made "x Equipo" wrong (see above): a person silently
 * double-booked into the wrong team, displacing that team's real titular
 * without a trace. Finding fewer team blocks than this election is known to
 * have (`self::MIN_EQUIPOS_ESPERADOS`) is a hard error too — a truncated
 * read of the sheet must never be mistaken for "everyone resolved".
 *
 * *** SHEET "Titulares eleccion con datos": THE PUNTAJE LOOKUP ***
 * Columns matched by name: `Apellido y Nombre`, `Puntaje`. `Orden`,
 * `Posicion` and any other column between `Puntaje` and `Equipo` are not
 * needed by this importer and are never read. This sheet's `Equipo` column
 * is ALSO not read here — the link between a titular's name and their
 * puntaje is by EXACT NORMALIZED NAME (`TextNormalizer::normalize()`),
 * verified against a 90-player sample of `sp_metrics` with zero differences
 * (see this feature's own task brief) — never by team, and never by the
 * fuzzy token matching `NombreMatcher` applies only when resolving a name
 * against WordPress. This sheet is confirmed to contain ZERO formula cells
 * on the real workbook — unlike both "GRILLA ELECCION" and "x Equipo", it is
 * raw stored data, indexed by PERSON (its own `Orden` column) rather than by
 * team (its `Equipo`-shaped column is populated only on a captain's own
 * row, used by "GRILLA ELECCION"'s formulas to label a team block — nothing
 * in this codebase reads it). `EleccionImporter::planificar()` is what
 * enforces that every titular "GRILLA ELECCION" yields actually has a
 * puntaje here — a hard error naming the team, the vuelta and the person if
 * not, because a plaza with no ceiling is not a plaza.
 */
final class EleccionSheetParser {

    private const REQUIRED_TITULARES_COLUMNS  = [ 'apellido y nombre', 'puntaje' ];

    /** 0-based array index of "GRILLA ELECCION"'s first team's id column (spreadsheet column B). */
    private const GRILLA_FIRST_TEAM_COLUMN = 1;

    /** Columns per team block: one id column, one name column. */
    private const GRILLA_BLOCK_STRIDE = 2;

    /** Columns of AIR between the end of one block and the start of the next (verified: 1). */
    private const GRILLA_BLOCK_GAP = 1;

    /** 0-based array index of the team-name row (spreadsheet row 2). */
    private const GRILLA_TEAM_NAME_ROW = 1;

    /** 0-based array index of the captain row (spreadsheet row 3). */
    private const GRILLA_CAPTAIN_ROW = 2;

    /** How many numbered rounds follow the captain (1..10). */
    private const GRILLA_ROUNDS = 10;

    /**
     * The real March 2026 workbook has exactly 30 teams. A read that finds
     * fewer is treated as a truncated/misconfigured read of the sheet, never
     * as "this season simply has fewer teams" — see class docblock.
     */
    private const MIN_EQUIPOS_ESPERADOS = 30;

    /**
     * @param array<int, array<int, mixed>> $rows Row 0 is spreadsheet row 1,
     *        column 0 is spreadsheet column A (see class docblock, "SHEET
     *        'GRILLA ELECCION'").
     * @return array{
     *     teams: array<int, array{equipo: string, line: int, titulares: array<string, string>}>,
     *     errors: array<int, string>
     * } Same output shape `EleccionImporter::planificar()` has always
     *   consumed: `titulares` maps a VUELTA label ('CAP','1'..'10') to the
     *   (untrimmed from Excel, still-not-normalized) name in that slot —
     *   only present for a team whose block was fully valid. `line` is kept
     *   as an int for that existing `(int) $team['line']` cast, but here it
     *   is the team block's 1-indexed spreadsheet COLUMN (this sheet lays
     *   teams out across columns, not rows) — every message this parser
     *   itself raises says "columna", never "linea".
     */
    public static function parseGrillaSheet( array $rows ): array {
        if ( [] === $rows ) {
            throw new \InvalidArgumentException(
                "EleccionSheetParser::parseGrillaSheet(): la hoja 'GRILLA ELECCION' esta vacia."
            );
        }

        $nameRow    = (array) ( $rows[ self::GRILLA_TEAM_NAME_ROW ] ?? [] );
        $width      = count( $nameRow );
        $blockWidth = self::GRILLA_BLOCK_STRIDE + self::GRILLA_BLOCK_GAP;

        $teams  = [];
        $errors = [];

        // Every non-placeholder block found, whether or not it later
        // validates cleanly — this is what "fewer team blocks than
        // expected" (see `self::MIN_EQUIPOS_ESPERADOS`) actually counts: a
        // truncated READ of the sheet, never a team that was found but
        // rejected for its own, separately-reported reasons.
        $discoveredBlocks = 0;

        // Cross-team de-duplication — the exact defect that made "x Equipo"
        // wrong (see class docblock): a person silently double-booked into
        // two teams, one of them losing their real titular. Keyed by
        // internal id (this workbook's own numbering) AND, separately, by
        // normalized name — either match is a hard error, since a mistyped
        // id could still be the same human under a matching name.
        $seenById   = []; // id => team label
        $seenByName = []; // normalized name => team label

        for ( $col = self::GRILLA_FIRST_TEAM_COLUMN; $col < $width; $col += $blockWidth ) {
            $nameCol      = $col + 1;
            $columnNumber = $col + 1; // 1-indexed spreadsheet column, for messages.
            $columnLabel  = self::columnLetter( $columnNumber );

            $teamName = self::cell( $nameRow, $col );

            if ( '' === $teamName || self::looksLikeFormulaError( $teamName ) ) {
                continue; // Placeholder block — see class docblock.
            }

            ++$discoveredBlocks;

            $rowIndexByVuelta = [ 'CAP' => self::GRILLA_CAPTAIN_ROW ];
            for ( $k = 1; $k <= self::GRILLA_ROUNDS; $k++ ) {
                $rowIndexByVuelta[ (string) $k ] = self::GRILLA_CAPTAIN_ROW + 2 * $k;
            }

            $titulares  = [];
            $withinTeam = []; // 'id:' . id or 'name:' . normalized => vuelta, for within-block dupes.
            $blockOk    = true;

            foreach ( $rowIndexByVuelta as $vuelta => $rowIndex ) {
                $row    = (array) ( $rows[ $rowIndex ] ?? [] );
                $id     = self::cell( $row, $col );
                $nombre = self::cell( $row, $nameCol );

                if ( '' === $nombre || self::looksLikeFormulaError( $nombre ) ) {
                    $errors[] = "Hoja 'GRILLA ELECCION', equipo '{$teamName}' (columna {$columnLabel}), vuelta {$vuelta}: no tiene nombre.";
                    $blockOk  = false;
                    continue;
                }

                $normalized = TextNormalizer::normalize( $nombre );

                $idKey   = '' !== $id ? 'id:' . $id : null;
                $nameKey = 'name:' . $normalized;

                if ( ( null !== $idKey && isset( $withinTeam[ $idKey ] ) ) || isset( $withinTeam[ $nameKey ] ) ) {
                    $errors[] = "Hoja 'GRILLA ELECCION', equipo '{$teamName}' (columna {$columnLabel}): '{$nombre}' aparece mas de una vez en el mismo equipo (vuelta {$vuelta}).";
                    $blockOk  = false;
                    continue;
                }

                if ( null !== $idKey ) {
                    $withinTeam[ $idKey ] = $vuelta;
                }
                $withinTeam[ $nameKey ] = $vuelta;

                $titulares[ $vuelta ] = $nombre;
            }

            if ( ! $blockOk || count( $titulares ) !== 11 ) {
                if ( $blockOk ) {
                    $n        = count( $titulares );
                    $errors[] = "Hoja 'GRILLA ELECCION', equipo '{$teamName}' (columna {$columnLabel}): tiene {$n} titular(es) valido(s); se esperan exactamente 11 (CAP + 1..10).";
                }
                continue;
            }

            // Cross-team de-duplication, only for a block that is itself clean.
            $crossTeamOk = true;
            foreach ( $rowIndexByVuelta as $vuelta => $rowIndex ) {
                $row        = (array) ( $rows[ $rowIndex ] ?? [] );
                $id         = self::cell( $row, $col );
                $normalized = TextNormalizer::normalize( (string) $titulares[ $vuelta ] );

                if ( '' !== $id && isset( $seenById[ $id ] ) && $seenById[ $id ] !== $teamName ) {
                    $errors[]    = "Hoja 'GRILLA ELECCION': el titular con id '{$id}' aparece en los equipos '{$seenById[ $id ]}' y '{$teamName}' (columna {$columnLabel}).";
                    $crossTeamOk = false;
                    continue;
                }

                if ( isset( $seenByName[ $normalized ] ) && $seenByName[ $normalized ] !== $teamName ) {
                    $errors[]    = "Hoja 'GRILLA ELECCION': el titular '{$titulares[ $vuelta ]}' aparece en los equipos '{$seenByName[ $normalized ]}' y '{$teamName}' (columna {$columnLabel}).";
                    $crossTeamOk = false;
                    continue;
                }
            }

            if ( ! $crossTeamOk ) {
                continue;
            }

            foreach ( $rowIndexByVuelta as $vuelta => $rowIndex ) {
                $row        = (array) ( $rows[ $rowIndex ] ?? [] );
                $id         = self::cell( $row, $col );
                $normalized = TextNormalizer::normalize( (string) $titulares[ $vuelta ] );

                if ( '' !== $id ) {
                    $seenById[ $id ] = $teamName;
                }
                $seenByName[ $normalized ] = $teamName;
            }

            $teams[] = [ 'equipo' => $teamName, 'line' => $columnNumber, 'titulares' => $titulares ];
        }

        if ( $discoveredBlocks < self::MIN_EQUIPOS_ESPERADOS ) {
            $errors[] = "Hoja 'GRILLA ELECCION': se encontraron {$discoveredBlocks} bloque(s) de equipo; se esperan al menos " . self::MIN_EQUIPOS_ESPERADOS . '.';
        }

        return [ 'teams' => $teams, 'errors' => $errors ];
    }

    /**
     * A cell holding a literal spreadsheet error token, e.g. `#ERROR!`,
     * `#REF!`, `#N/A` — a formula that failed to resolve, never valid data.
     */
    private static function looksLikeFormulaError( string $value ): bool {
        return '' !== $value && '#' === $value[0];
    }

    /** 1-indexed column number to spreadsheet column letters (1 => 'A', 27 => 'AA'). */
    private static function columnLetter( int $columnNumber ): string {
        $letters = '';
        while ( $columnNumber > 0 ) {
            --$columnNumber;
            $letters       = chr( 65 + ( $columnNumber % 26 ) ) . $letters;
            $columnNumber  = intdiv( $columnNumber, 26 );
        }
        return $letters;
    }

    /**
     * @param array<int, array<int, mixed>> $rows Row 0 is the header.
     * @return array{
     *     puntajes: array<string, string>,
     *     errors: array<int, string>
     * } `puntajes` maps a NORMALIZED name (`TextNormalizer::normalize()`) to
     *   its raw (still not `Puntaje`-validated) puntaje string.
     */
    public static function parseTitularesSheet( array $rows ): array {
        if ( [] === $rows ) {
            throw new \InvalidArgumentException(
                'EleccionSheetParser::parseTitularesSheet(): la hoja "Titulares eleccion con datos" esta vacia.'
            );
        }

        $columnIndex = self::indexHeader( (array) $rows[0], self::REQUIRED_TITULARES_COLUMNS, 'Titulares eleccion con datos' );

        $puntajes = [];
        $errors   = [];

        $count = count( $rows );
        for ( $i = 1; $i < $count; $i++ ) {
            $lineNumber = $i + 1;
            $row        = (array) $rows[ $i ];

            $nombre  = self::cell( $row, $columnIndex['apellido y nombre'] );
            $puntaje = self::cell( $row, $columnIndex['puntaje'] );

            if ( '' === $nombre && '' === $puntaje ) {
                continue; // Blank row.
            }

            if ( '' === $nombre ) {
                $errors[] = "Hoja 'Titulares eleccion con datos', linea {$lineNumber}: falta el nombre.";
                continue;
            }

            if ( '' === $puntaje ) {
                $errors[] = "Hoja 'Titulares eleccion con datos', linea {$lineNumber}: '{$nombre}' no tiene puntaje.";
                continue;
            }

            $normalized = TextNormalizer::normalize( $nombre );

            if ( isset( $puntajes[ $normalized ] ) && $puntajes[ $normalized ] !== $puntaje ) {
                $errors[] = "Hoja 'Titulares eleccion con datos', linea {$lineNumber}: '{$nombre}' aparece mas de una vez con puntajes distintos ({$puntajes[ $normalized ]} y {$puntaje}).";
                continue;
            }

            $puntajes[ $normalized ] = $puntaje;
        }

        return [ 'puntajes' => $puntajes, 'errors' => $errors ];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<int, mixed> $headerRow
     * @param array<int, string> $required
     * @return array<string, int>
     */
    private static function indexHeader( array $headerRow, array $required, string $sheetName ): array {
        $byName = [];
        foreach ( $headerRow as $index => $cell ) {
            $byName[ strtolower( trim( (string) $cell ) ) ] = $index;
        }

        $columnIndex = [];
        $missing     = [];

        foreach ( $required as $name ) {
            if ( ! array_key_exists( $name, $byName ) ) {
                $missing[] = $name;
                continue;
            }
            $columnIndex[ $name ] = $byName[ $name ];
        }

        if ( [] !== $missing ) {
            throw new \InvalidArgumentException(
                "EleccionSheetParser: la hoja '{$sheetName}' no tiene la(s) columna(s): " . implode( ', ', $missing ) . '.'
            );
        }

        return $columnIndex;
    }

    /**
     * @param array<int, mixed> $row
     */
    private static function cell( array $row, int $index ): string {
        return trim( (string) ( $row[ $index ] ?? '' ) );
    }
}

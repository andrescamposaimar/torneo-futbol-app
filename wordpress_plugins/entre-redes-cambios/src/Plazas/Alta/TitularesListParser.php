<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas\Alta;

/**
 * Parses the plain CSV backfill input — one row per official titular,
 * resolved to WordPress ids ahead of time by whoever ran the actual election
 * cross-reference — into structurally valid rows plus a list of every
 * FORMAT problem found. Pure: no `\wpdb`, no WordPress, no season/database
 * lookups of any kind. This class answers exactly one question — "is this
 * CSV shaped the way `TitularesListImporter::planificar()` needs it to be?"
 * — never "does `team_id` 21568 actually exist". That second question needs
 * `\wpdb` and belongs entirely to `TitularesListImporter`; see that class's
 * own docblock for the full division of labor.
 *
 * *** WHY THIS EXISTS AT ALL (replacing this plugin's previous, spreadsheet-based, parser) ***
 * The `.xlsx` this plugin used to parse with a third-party spreadsheet
 * library was a one-off cross-reference the process owner used, in March
 * 2026, to settle who the official titulares of each team actually are — it
 * was never meant to be a data source this plugin parses on an ongoing
 * basis, and the process owner has since ruled that design out entirely.
 * That cross-reference is done. Its OUTPUT — the 330 official titulares of
 * season 2026, already resolved to WordPress `sp_player`/`sp_team` ids, with
 * zero name matching left to do — is a plain CSV now. This class reads that
 * CSV; it never opens a spreadsheet, and this plugin no longer depends on
 * any spreadsheet-parsing library at all (removed from `composer.json`
 * together with the whole namespace this class replaces).
 *
 * *** FORMAT ***
 * A CSV with a header row naming four REQUIRED columns, matched
 * case-insensitively and in any order, same convention as this plugin's other
 * CSV formats (see the override-file format of the previous importer, now removed):
 *   - `team_id`            The WordPress `sp_team` post id. Must be a
 *                           positive integer; existence is checked later, by
 *                           `TitularesListImporter`, not here.
 *   - `titular_player_id`  The WordPress `sp_player` post id. Same format
 *                           rule, same "existence is someone else's job".
 *   - `puntaje_techo`      The puntaje as a plain decimal string (e.g. "2.5"
 *                           or "2,5" — both separators are accepted; see
 *                           `Plazas\Puntaje::fromDecimal()`, which is where
 *                           this class hands the raw string off to be turned
 *                           into one of the 9 valid values, or rejected).
 *                           This class only checks the cell is non-empty —
 *                           whether it is actually one of the 9 valid
 *                           puntajes is a business rule, not a format rule,
 *                           and belongs to `TitularesListImporter`.
 *   - `es_capitan`         Must be the literal string `1` or `0` — nothing
 *                           else (no `true`/`false`, no `si`/`no`). A column
 *                           this narrow is easy to validate completely here,
 *                           in one place, rather than half in this class and
 *                           half in the importer.
 *
 * An `equipo` column MAY also be present. When it is, this class carries its
 * value through unchanged so `TitularesListImporter` can use it to make an
 * error message legible ("Equipo 'ALBANIA' (team_id=21568)..." reads far
 * better than a bare id) — but `equipo` is NEVER read to resolve, validate,
 * or cross-check anything: `team_id` is the only identifier this whole
 * import trusts, exactly as the task brief for this feature requires. A file
 * with no `equipo` column at all is just as valid; every row then carries an
 * empty string, and every message falls back to naming the team by its id
 * alone.
 *
 * A row whose first cell starts with `#` is a comment and is skipped, same
 * convention as every other CSV format in this codebase. A blank line is
 * skipped too.
 *
 * *** EVERY FORMAT PROBLEM IS REPORTED, NEVER JUST THE FIRST ***
 * Same discipline as the spreadsheet parser this class replaces (now
 * removed): a malformed row is appended to `errors` and excluded from `rows`, and parsing
 * continues to the end of the file. `TitularesListImporter::planificar()`
 * treats ANY non-empty `errors` (from here, or from its own DB-backed checks)
 * as a reason to refuse every write — see that class's own docblock.
 */
final class TitularesListParser {

    private const REQUIRED_COLUMNS = [ 'team_id', 'titular_player_id', 'puntaje_techo', 'es_capitan' ];

    /**
     * @return array{rows: array<int, array{line:int, team_id:int, equipo:string, titular_player_id:int, puntaje_raw:string, es_capitan:bool}>, errors: array<int, string>}
     * @throws \InvalidArgumentException When the header is missing one of the
     *         four required columns — a problem with the WHOLE file, not a
     *         single row, so it is never merely collected into `errors`
     *         alongside per-row problems.
     */
    public static function parse( string $csvContent ): array {
        $csvContent = preg_replace( '/^\xEF\xBB\xBF/', '', $csvContent ) ?? $csvContent;

        $lines = preg_split( "/\r\n|\r|\n/", $csvContent );
        if ( false === $lines ) {
            $lines = [];
        }
        if ( [] !== $lines && '' === trim( (string) end( $lines ) ) ) {
            array_pop( $lines );
        }

        if ( [] === $lines ) {
            throw new \InvalidArgumentException(
                'TitularesListParser::parse(): el archivo esta vacio — falta incluso la fila de encabezado.'
            );
        }

        $headerCells = str_getcsv( (string) $lines[0], ',', '"', '\\' );
        $columnIndex = self::indexHeader( $headerCells );

        $rows   = [];
        $errors = [];

        for ( $i = 1, $count = count( $lines ); $i < $count; $i++ ) {
            $lineNumber = $i + 1;
            $rawLine    = (string) $lines[ $i ];

            if ( '' === trim( $rawLine ) ) {
                continue;
            }

            $cells = str_getcsv( $rawLine, ',', '"', '\\' );

            if ( str_starts_with( trim( (string) ( $cells[0] ?? '' ) ), '#' ) ) {
                continue;
            }

            $row = self::parseRow( $cells, $columnIndex, $lineNumber, $errors );

            if ( null !== $row ) {
                $rows[] = $row;
            }
        }

        return [ 'rows' => $rows, 'errors' => $errors ];
    }

    /**
     * @param array<int, string|null>       $cells
     * @param array<string, int>            $columnIndex
     * @param array<int, string>            $errors
     * @return array{line:int, team_id:int, equipo:string, titular_player_id:int, puntaje_raw:string, es_capitan:bool}|null
     *         Null when this row has any format problem — the problem itself
     *         is appended to $errors, and the row is excluded from the
     *         result entirely rather than included with a placeholder value.
     */
    private static function parseRow( array $cells, array $columnIndex, int $lineNumber, array &$errors ): ?array {
        $equipo = isset( $columnIndex['equipo'] ) ? trim( (string) ( $cells[ $columnIndex['equipo'] ] ?? '' ) ) : '';

        $teamIdRaw  = trim( (string) ( $cells[ $columnIndex['team_id'] ] ?? '' ) );
        $playerIdRaw = trim( (string) ( $cells[ $columnIndex['titular_player_id'] ] ?? '' ) );
        $puntajeRaw = trim( (string) ( $cells[ $columnIndex['puntaje_techo'] ] ?? '' ) );
        $capitanRaw = trim( (string) ( $cells[ $columnIndex['es_capitan'] ] ?? '' ) );

        $label = '' !== $equipo ? "'{$equipo}'" : '(sin nombre de equipo)';
        $ok    = true;

        if ( '' === $teamIdRaw || ! ctype_digit( $teamIdRaw ) ) {
            $errors[] = "Fila {$lineNumber}, equipo {$label}: 'team_id' ('{$teamIdRaw}') debe ser un entero positivo.";
            $ok       = false;
        }

        if ( '' === $playerIdRaw || ! ctype_digit( $playerIdRaw ) ) {
            $errors[] = "Fila {$lineNumber}, equipo {$label}: 'titular_player_id' ('{$playerIdRaw}') debe ser un entero positivo.";
            $ok       = false;
        }

        if ( '' === $puntajeRaw ) {
            $errors[] = "Fila {$lineNumber}, equipo {$label}: falta 'puntaje_techo'.";
            $ok       = false;
        }

        if ( '0' !== $capitanRaw && '1' !== $capitanRaw ) {
            $errors[] = "Fila {$lineNumber}, equipo {$label}: 'es_capitan' ('{$capitanRaw}') debe ser exactamente '0' o '1'.";
            $ok       = false;
        }

        if ( ! $ok ) {
            return null;
        }

        return [
            'line'              => $lineNumber,
            'team_id'           => (int) $teamIdRaw,
            'equipo'            => $equipo,
            'titular_player_id' => (int) $playerIdRaw,
            'puntaje_raw'       => $puntajeRaw,
            'es_capitan'        => '1' === $capitanRaw,
        ];
    }

    /**
     * @param array<int, string|null> $headerCells
     * @return array<string, int>
     * @throws \InvalidArgumentException When a required column is missing.
     */
    private static function indexHeader( array $headerCells ): array {
        $byName = [];
        foreach ( $headerCells as $index => $cell ) {
            $byName[ strtolower( trim( (string) $cell ) ) ] = $index;
        }

        $columnIndex = [];
        $missing     = [];

        foreach ( self::REQUIRED_COLUMNS as $name ) {
            if ( ! array_key_exists( $name, $byName ) ) {
                $missing[] = $name;
                continue;
            }
            $columnIndex[ $name ] = $byName[ $name ];
        }

        if ( [] !== $missing ) {
            throw new \InvalidArgumentException(
                'TitularesListParser::parse(): el archivo no tiene la(s) columna(s): '
                . implode( ', ', $missing ) . '. Columnas requeridas: ' . implode( ', ', self::REQUIRED_COLUMNS ) . '.'
            );
        }

        // 'equipo' is optional, informational-only (see class docblock) — carried
        // through when present, absent from $columnIndex (never $missing) otherwise.
        if ( array_key_exists( 'equipo', $byName ) ) {
            $columnIndex['equipo'] = $byName['equipo'];
        }

        return $columnIndex;
    }
}

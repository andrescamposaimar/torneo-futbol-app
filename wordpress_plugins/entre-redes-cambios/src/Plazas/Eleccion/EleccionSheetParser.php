<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas\Eleccion;

/**
 * Pure, WordPress-free parsing of the two sheets of the March 2026 election
 * spreadsheet — no `\wpdb`, no file I/O of its own. `tools/importar-eleccion.php`
 * reads the `.xlsx` file (via PhpSpreadsheet) and hands each sheet to this
 * class as a plain 2D array of cell strings, row 0 being the header — the
 * exact shape `Spreadsheet::getSheetByName( $name )->toArray()` returns. This
 * mirrors `Plazas\PlazaImportCsvParser`'s role in the importer this feature
 * replaces: turn raw rows into validated arrays, never touch a database.
 *
 * *** SHEET "x Equipo": 11 ROWS PER TEAM, HEADER ROWS INTERLEAVED ***
 * Columns (matched by name, case-insensitive, any order — see
 * `parseEquipoSheet()`): `Vuelta`, `Equipo`, `Nombre`. `id`, `Celular`,
 * `mail`, `Fijo` are read by NOTHING in this codebase — this class never
 * even looks at their column indexes, so a phone number or email address
 * from the source spreadsheet is never held in memory by this importer, let
 * alone written anywhere (see this feature's own task brief: personal data
 * must never reach a fixture, a log, or a commit).
 *
 * `Vuelta` is `CAP` for the team's captain, then `1`..`10` — 11 rows form one
 * team's complete official roster for the whole year. The source spreadsheet
 * is a single export with the header row repeated between teams (`Equipo`
 * appearing again as a literal cell value); `parseEquipoSheet()` recognizes
 * and discards those rows without counting them as data. A team whose block
 * does not resolve to EXACTLY 11 rows with EXACTLY the vuelta values
 * `CAP,1,2,...,10` (each once) is a hard error naming that team and how many
 * rows it actually had — never a partial roster, never silently dropped (see
 * `EleccionImporter`'s own class docblock, "VALIDATE THE WHOLE FILE FIRST").
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
 * against WordPress.
 */
final class EleccionSheetParser {

    private const REQUIRED_EQUIPO_COLUMNS     = [ 'vuelta', 'equipo', 'nombre' ];
    private const REQUIRED_TITULARES_COLUMNS  = [ 'apellido y nombre', 'puntaje' ];

    /** The 11 valid `Vuelta` labels, in the canonical order a plaza list is reported. */
    private const VUELTAS = [ 'CAP', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10' ];

    /**
     * @param array<int, array<int, mixed>> $rows Row 0 is the header.
     * @return array{
     *     teams: array<int, array{equipo: string, line: int, titulares: array<string, string>}>,
     *     errors: array<int, string>
     * } `titulares` maps a VUELTA label ('CAP','1'..'10') to the (untrimmed
     *   from Excel, still-not-normalized) name in that row — only present for
     *   a team whose block was fully valid. `line` is the 1-indexed row of
     *   the team's FIRST data row (header is row 1), for error messages that
     *   reference a team but were not themselves raised by this parser.
     */
    public static function parseEquipoSheet( array $rows ): array {
        if ( [] === $rows ) {
            throw new \InvalidArgumentException(
                'EleccionSheetParser::parseEquipoSheet(): la hoja "x Equipo" esta vacia.'
            );
        }

        $columnIndex = self::indexHeader( (array) $rows[0], self::REQUIRED_EQUIPO_COLUMNS, 'x Equipo' );

        // Group contiguous data rows sharing the same (trimmed) 'equipo'
        // cell into one team block, skipping blank rows and repeated header
        // rows entirely — neither ends nor starts a block.
        $blocks  = [];
        $current = null;

        $count = count( $rows );
        for ( $i = 1; $i < $count; $i++ ) {
            $lineNumber = $i + 1;
            $row        = (array) $rows[ $i ];

            $vuelta = self::cell( $row, $columnIndex['vuelta'] );
            $equipo = self::cell( $row, $columnIndex['equipo'] );
            $nombre = self::cell( $row, $columnIndex['nombre'] );

            if ( '' === $vuelta && '' === $equipo && '' === $nombre ) {
                continue; // Blank separator row.
            }

            if ( 'EQUIPO' === TextNormalizer::normalize( $equipo ) ) {
                continue; // Repeated header row, interleaved between teams.
            }

            if ( null === $current || $current['equipo'] !== $equipo ) {
                if ( null !== $current ) {
                    $blocks[] = $current;
                }
                $current = [ 'equipo' => $equipo, 'line' => $lineNumber, 'rows' => [] ];
            }

            $current['rows'][] = [ 'line' => $lineNumber, 'vuelta' => $vuelta, 'nombre' => $nombre ];
        }
        if ( null !== $current ) {
            $blocks[] = $current;
        }

        $teams  = [];
        $errors = [];

        foreach ( $blocks as $block ) {
            $label = '' !== $block['equipo'] ? $block['equipo'] : '(sin nombre, linea ' . $block['line'] . ')';
            $n     = count( $block['rows'] );

            if ( 11 !== $n ) {
                $errors[] = "Hoja 'x Equipo', equipo '{$label}' (linea {$block['line']}): tiene {$n} fila(s); se esperan exactamente 11 (CAP + 1..10).";
                continue;
            }

            $byVuelta = [];
            foreach ( $block['rows'] as $row ) {
                $vuelta = strtoupper( trim( $row['vuelta'] ) );
                $byVuelta[ $vuelta ][] = $row['line'];
            }

            $missing = array_diff( self::VUELTAS, array_keys( $byVuelta ) );
            $extra   = array_diff( array_keys( $byVuelta ), self::VUELTAS );
            $repeated = array_keys( array_filter( $byVuelta, static fn ( array $lines ): bool => count( $lines ) > 1 ) );

            if ( [] !== $missing || [] !== $extra || [] !== $repeated ) {
                $errors[] = "Hoja 'x Equipo', equipo '{$label}' (linea {$block['line']}): los valores de Vuelta no son exactamente CAP,1..10, cada uno una vez"
                    . ( [] !== $missing ? ' (faltan: ' . implode( ',', $missing ) . ')' : '' )
                    . ( [] !== $extra ? ' (sobran: ' . implode( ',', $extra ) . ')' : '' )
                    . ( [] !== $repeated ? ' (repetidos: ' . implode( ',', $repeated ) . ')' : '' ) . '.';
                continue;
            }

            $titulares = [];
            foreach ( $block['rows'] as $row ) {
                $titulares[ strtoupper( trim( $row['vuelta'] ) ) ] = $row['nombre'];
            }

            $teams[] = [ 'equipo' => $block['equipo'], 'line' => $block['line'], 'titulares' => $titulares ];
        }

        return [ 'teams' => $teams, 'errors' => $errors ];
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

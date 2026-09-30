<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas\Eleccion;

/**
 * Parses the operator-supplied override file — a small CSV the process
 * owner fills in for the titulares `NombreMatcher` cannot resolve on its
 * own (see `EleccionImporter`'s class docblock, "THE FIVE THAT DO NOT
 * RESOLVE") — into a normalized-name-to-player-id map. Pure, no `\wpdb`; the
 * override file is TEXT the CLI tool reads from disk exactly like the
 * election spreadsheet's own sheets are handed to `EleccionSheetParser`.
 *
 * *** FORMAT ***
 * A CSV with a header naming the two required columns, matched
 * case-insensitively and in any order, same convention as this codebase's
 * only other CSV format (see `Plazas\PlazaImportCsvParser`, now removed —
 * see this feature's own task brief for why): `nombre_excel` (the name
 * EXACTLY as it appears in the "x Equipo" sheet — this class normalizes it
 * the same way `NombreMatcher` normalizes every name it compares, so case,
 * accents and whitespace never matter) and `titular_player_id` (the
 * WordPress `sp_player` post id the operator has manually confirmed IS that
 * person). A row whose first cell starts with `#` is a comment and is
 * skipped, same convention as the removed CSV importer.
 *
 * *** WHY A CONFLICTING DUPLICATE IS A HARD ERROR, NEVER "LAST ONE WINS" ***
 * Two rows for the same normalized name mapping to two DIFFERENT player ids
 * is a contradiction only a human can resolve — silently keeping whichever
 * row happened to parse last would make the override file itself a source of
 * exactly the silent-guess failure mode this whole importer exists to avoid.
 * Two rows for the same name mapping to the SAME id are harmless and are not
 * an error.
 */
final class EleccionOverrides {

    private const REQUIRED_COLUMNS = [ 'nombre_excel', 'titular_player_id' ];

    /**
     * @return array<string, int> normalized name => player_id.
     * @throws \InvalidArgumentException On a missing header column, a
     *         non-numeric `titular_player_id`, or a conflicting duplicate.
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
            return [];
        }

        $headerCells = str_getcsv( (string) $lines[0], ',', '"', '\\' );
        $columnIndex = self::indexHeader( $headerCells );

        $overrides = [];

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

            $nombre = trim( (string) ( $cells[ $columnIndex['nombre_excel'] ] ?? '' ) );
            $idRaw  = trim( (string) ( $cells[ $columnIndex['titular_player_id'] ] ?? '' ) );

            if ( '' === $nombre || '' === $idRaw ) {
                throw new \InvalidArgumentException(
                    "EleccionOverrides::parse(): fila {$lineNumber} tiene 'nombre_excel' o 'titular_player_id' vacio."
                );
            }

            if ( ! ctype_digit( $idRaw ) ) {
                throw new \InvalidArgumentException(
                    "EleccionOverrides::parse(): fila {$lineNumber}: 'titular_player_id' ('{$idRaw}') debe ser un entero positivo."
                );
            }

            $normalized = TextNormalizer::normalize( $nombre );
            $playerId   = (int) $idRaw;

            if ( isset( $overrides[ $normalized ] ) && $overrides[ $normalized ] !== $playerId ) {
                throw new \InvalidArgumentException(
                    "EleccionOverrides::parse(): '{$nombre}' aparece mas de una vez con ids distintos ({$overrides[ $normalized ]} y {$playerId})."
                );
            }

            $overrides[ $normalized ] = $playerId;
        }

        return $overrides;
    }

    /**
     * @param array<int, string|null> $headerCells
     * @return array<string, int>
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
                'EleccionOverrides::parse(): el archivo de overrides no tiene la(s) columna(s): '
                . implode( ', ', $missing ) . '. Columnas requeridas: ' . implode( ', ', self::REQUIRED_COLUMNS ) . '.'
            );
        }

        return $columnIndex;
    }
}

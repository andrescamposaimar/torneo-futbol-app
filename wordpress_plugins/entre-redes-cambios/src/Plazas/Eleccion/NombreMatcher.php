<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas\Eleccion;

/**
 * Token-based name matching between an Excel name (the March 2026 election
 * spreadsheet) and a pool of WordPress `sp_player` titles — pure, no `\wpdb`,
 * no I/O.
 *
 * *** WHY NOT EXACT-STRING EQUALITY ***
 * Exact-string comparison (after `TextNormalizer::normalize()`) left 8 of the
 * 330 official titulares unmatched against a season's `sp_player` pool. The
 * survivors were never typos in the sense of a wrong letter — they were
 * different but legitimate ways of writing the SAME person's name:
 * `SINCLAIR , JUAN MARTIN` (a stray space before the comma — normalization
 * alone fixes this one), `MORALEJO RIVERA, EZEQUIEL` vs `MORALEJO, EZEQUIEL`
 * (a compound surname written in full on one side, shortened on the other),
 * `PALOU, JAVIER` vs `PALOU DE COMASEMA, JAVIER IGNACIO` (same shape). A
 * matcher that requires only "at least one surname token in common AND the
 * first given name to match" resolves all of these down to 5 genuinely
 * unresolved names (see `EleccionImporter`'s class docblock).
 *
 * *** THE CONDITION, PRECISELY ***
 * A name `$a` and a candidate `$b` match when, after splitting each on its
 * FIRST comma into `[apellidos, nombres]` and tokenizing on whitespace:
 *   1. Both sides parsed to at least one apellido token and at least one
 *      nombre token — a name with no comma, or a comma with nothing on one
 *      side, never matches anything (this codebase's real names are always
 *      "APELLIDO(S), NOMBRE(S)"; a name that does not fit that shape is a
 *      data problem for a human to look at, not a reason to guess a match).
 *   2. `$a`'s apellido tokens and `$b`'s apellido tokens share AT LEAST ONE
 *      token (set intersection is non-empty).
 *   3. `$a`'s FIRST nombre token equals `$b`'s FIRST nombre token exactly.
 *
 * *** TIES ARE NEVER BROKEN — THEY ARE REPORTED ***
 * `candidatosCoincidentes()` returns EVERY candidate id that satisfies the
 * condition above, never just the "best" one — there is no scoring beyond
 * the boolean condition, so nothing here could even define "best". A caller
 * that gets back more than one id has an ambiguous name and MUST report it as
 * an error; resolving it by picking the first, the shortest list, or any
 * other tie-break would silently assign a titular's plaza to the wrong
 * person. See `EleccionImporter::resolveTitular()`.
 */
final class NombreMatcher {

    /**
     * @param string $excelName Raw (not yet normalized) name from the Excel.
     * @param array<int, string> $pool player_id => raw (not yet normalized)
     *        `sp_player` `post_title`, already scoped by the caller to
     *        whatever candidate pool is appropriate (see class docblock on
     *        `EleccionImporter`, "THE CANDIDATE POOL").
     * @return array<int, int> Every player_id in $pool that matches — 0, 1,
     *         or (an ambiguous tie) more than 1. Order is not significant.
     */
    public static function candidatosCoincidentes( string $excelName, array $pool ): array {
        $needle = self::parts( $excelName );

        if ( null === $needle ) {
            return [];
        }

        $matches = [];

        foreach ( $pool as $playerId => $rawTitle ) {
            $candidate = self::parts( $rawTitle );

            if ( null === $candidate ) {
                continue;
            }

            if ( self::matches( $needle, $candidate ) ) {
                $matches[] = (int) $playerId;
            }
        }

        return $matches;
    }

    /**
     * @param array{apellidos: array<int,string>, nombres: array<int,string>} $a
     * @param array{apellidos: array<int,string>, nombres: array<int,string>} $b
     */
    private static function matches( array $a, array $b ): bool {
        $sharedApellido = array_intersect( $a['apellidos'], $b['apellidos'] );

        if ( [] === $sharedApellido ) {
            return false;
        }

        return $a['nombres'][0] === $b['nombres'][0];
    }

    /**
     * Splits a normalized-or-not name on its FIRST comma into apellido and
     * nombre tokens. Returns null when the name does not have the
     * "APELLIDO(S), NOMBRE(S)" shape this matcher requires (no comma, or
     * nothing but whitespace on either side of it) — see class docblock,
     * condition 1.
     *
     * @return array{apellidos: array<int,string>, nombres: array<int,string>}|null
     */
    private static function parts( string $rawName ): ?array {
        $normalized = TextNormalizer::normalize( $rawName );

        $commaAt = strpos( $normalized, ',' );

        if ( false === $commaAt ) {
            return null;
        }

        $apellidos = self::tokenize( substr( $normalized, 0, $commaAt ) );
        $nombres   = self::tokenize( substr( $normalized, $commaAt + 1 ) );

        if ( [] === $apellidos || [] === $nombres ) {
            return null;
        }

        return [ 'apellidos' => $apellidos, 'nombres' => $nombres ];
    }

    /**
     * @return array<int, string>
     */
    private static function tokenize( string $segment ): array {
        $trimmed = trim( $segment );

        if ( '' === $trimmed ) {
            return [];
        }

        return array_values( array_filter( explode( ' ', $trimmed ), static fn ( string $t ): bool => '' !== $t ) );
    }
}

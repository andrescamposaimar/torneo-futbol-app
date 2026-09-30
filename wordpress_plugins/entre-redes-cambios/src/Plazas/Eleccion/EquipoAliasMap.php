<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas\Eleccion;

/**
 * The ONE place a team name that differs between the election spreadsheet
 * and its WordPress `sp_team` `post_title` gets documented. Every entry is a
 * normalized-name-to-normalized-name substitution (both sides already run
 * through `TextNormalizer::normalize()`, so accents/case/apostrophes never
 * need to be repeated here) — a plain equality check after this substitution
 * is still all `EleccionImporter` ever does for a team name; this class adds
 * an exception list, never a second matching strategy.
 *
 * `COTE DIVOIRE` -> `COSTA DE MARFIL`: the Excel spells Ivory Coast in
 * French, WordPress's `sp_team` post 15796 is titled "Costa De Marfil" —
 * confirmed by direct lookup, not a guess.
 *
 * Add a new entry here — never a special case anywhere else — the moment a
 * future season's spreadsheet uses another team name WordPress does not.
 */
final class EquipoAliasMap {

    /**
     * @var array<string, string> normalized Excel name => normalized
     *      `sp_team.post_title`.
     */
    private const ALIASES = [
        'COTE DIVOIRE' => 'COSTA DE MARFIL',
    ];

    /**
     * @param string $normalizedEquipo Already run through
     *        `TextNormalizer::normalize()`.
     */
    public static function resolve( string $normalizedEquipo ): string {
        return self::ALIASES[ $normalizedEquipo ] ?? $normalizedEquipo;
    }
}

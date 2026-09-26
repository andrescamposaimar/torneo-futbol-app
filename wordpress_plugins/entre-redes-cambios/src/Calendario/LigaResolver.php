<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Calendario;

/**
 * Pure, network-free translator from raw `/ligas` items to the
 * (liga name -> {id, torneo_label}) and (liga_id -> torneo_label) lookups the
 * rest of the Calendario slice needs. Zero HTTP, zero DB, zero globals.
 *
 * THE TWO-DASH TRAP (read this before touching torneoLabelFromName()):
 * SportsPress league names are typed by hand season over season and mix an
 * ASCII hyphen ("2026 - Apertura Zona A") with a Unicode EN DASH U+2013
 * ("2026 – Clausura Zona B") — verified against the live API on 2026-09-26.
 * A parser that SPLITS the name on "the dash" to pull out a torneo segment
 * will work for whichever variant it was written against and silently break
 * on the other — and keep breaking intermittently as new fixtures get typed
 * with a different character by a different person. Searching for the
 * normalized KEYWORD anywhere inside the whole name sidesteps the problem
 * entirely: it does not care where a dash sits, which one was used, or
 * whether there is a dash at all. Do NOT "fix" this into a split/explode on a
 * dash character, however tidy that looks — see FechaRepositoryTest-style
 * regression risk notes elsewhere in this plugin for why that always comes
 * back.
 *
 * Matching happens against a normalized copy of the name (lower-cased,
 * accents stripped): production league names store "Clasificacion" WITHOUT
 * its accent today, but stripping accents defensively also protects against a
 * future edit that adds it back (or a league named with an accent by
 * mistake), without needing a second code path.
 */
final class LigaResolver {

    /**
     * Normalized keyword -> canonical torneo_label. Order does not matter:
     * the three keywords are mutually exclusive substrings of any real league
     * name (a league is never simultaneously named for two torneos).
     *
     * @var array<string, string>
     */
    private const KEYWORDS = [
        'clasificacion' => 'Clasificacion',
        'apertura'      => 'Apertura',
        'clausura'      => 'Clausura',
    ];

    /**
     * @param array<int, array{id?:int|string, name?:string, seasons?:mixed}> $ligas
     *        Raw `/ligas` response items. `seasons` is accepted in whatever
     *        shape the API happens to send (a plain JSON list, or a
     *        PHP-array-with-gaps serialized as a JSON object with numeric
     *        string keys, e.g. {"0":143,"19":359}) but is intentionally NEVER
     *        read here: matching is done by NAME, which already
     *        disambiguates different seasons because SportsPress league
     *        names embed the year (e.g. "2024 - Apertura Zona A" vs
     *        "2026 - Apertura Zona A"). Filtering by `seasons` would be a
     *        second, brittle source of truth for exactly the same fact the
     *        name already encodes — so this method simply ignores the key
     *        and never breaks no matter which shape it arrives in.
     * @return array<string, array{id:int, torneo_label:string}> Keyed by the
     *         liga's exact name. Ligas whose name resolves to none of the
     *         three torneos (old noise years, unrelated leagues) are excluded
     *         from the index — see torneoLabelFromName().
     */
    public static function index( array $ligas ): array {
        $index = [];

        foreach ( $ligas as $liga ) {
            $name = (string) ( $liga['name'] ?? '' );

            $label = self::torneoLabelFromName( $name );
            if ( null === $label ) {
                continue;
            }

            $index[ $name ] = [
                'id'           => (int) ( $liga['id'] ?? 0 ),
                'torneo_label' => $label,
            ];
        }

        return $index;
    }

    /**
     * Derives 'Clasificacion' | 'Apertura' | 'Clausura' from a raw liga name,
     * or null when none of the three keywords is present — that liga is
     * noise from an old season or an unrelated league, and is excluded by
     * index() rather than raising an error, since it never appears among the
     * season's actual partidos either.
     *
     * Deliberately does NOT split the name on a dash — see class docblock's
     * "TWO-DASH TRAP". It normalizes (lower-case, accents stripped) and looks
     * for the keyword as a substring anywhere in the name instead.
     */
    public static function torneoLabelFromName( string $name ): ?string {
        $normalized = self::normalize( $name );

        foreach ( self::KEYWORDS as $keyword => $label ) {
            if ( str_contains( $normalized, $keyword ) ) {
                return $label;
            }
        }

        return null;
    }

    /**
     * @param array<string, array{id:int, torneo_label:string}> $index As
     *        produced by index().
     * @return array<int, string> liga_id -> torneo_label — exactly the shape
     *         SeedTemporadaService's constructor expects as its third
     *         argument ($ligaToTorneoLabel).
     */
    public static function torneoMap( array $index ): array {
        $map = [];

        foreach ( $index as $entry ) {
            $map[ (int) $entry['id'] ] = (string) $entry['torneo_label'];
        }

        return $map;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function normalize( string $name ): string {
        static $accents = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ñ' => 'n', 'ü' => 'u',
        ];

        return strtr( mb_strtolower( $name ), $accents );
    }
}

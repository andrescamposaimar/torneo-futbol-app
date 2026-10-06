<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

/**
 * Resolves each player's "main" `sp_position` NAME, batched across a whole
 * PAGE of player ids in a single `wp_get_object_terms()` call — never one
 * `wp_get_post_terms()` call PER PLAYER, the exact N+1 shape this plugin's
 * `Rest\PlazasController` already removed from this same endpoint for the
 * name/photo (see that class's own docblock on `primePlayerTitles()` /
 * `fotoJugador()` — this class applies the identical batching discipline to
 * posición).
 *
 * *** MIRRORS `entre-redes-api`'S OWN "MAIN POSITION" CHOICE, EXACTLY ***
 * A player can hold more than one `sp_position` term. `entre-redes-api`'s
 * own `/jugadores` endpoint (`entre-redes-api.php`, ~lines 1034-1055) is
 * what the Players screen and the Team roster already show a captain — it
 * resolves "the" position for a player by mapping term ids through a
 * literal `$pos_map`, then walking `wp_get_post_terms( $id, 'sp_position',
 * [ 'fields' => 'ids' ] )`'s OWN return order and taking the FIRST id that
 * is a key in that map — never the lowest id, never alphabetical by name,
 * whatever order WP itself hands back that player's terms in (WP core's own
 * taxonomy query default, since neither caller passes an explicit
 * `orderby`). `self::POS_MAP` below is a literal copy of that same map, and
 * `resolverParaIds()` applies the identical "first match in returned order"
 * loop — so a player can never show one position here and a DIFFERENT one
 * on the Players/Team screens, which is the exact bug this class exists to
 * avoid (see this plugin's task brief).
 *
 * `wp_get_object_terms()` — the batched, multi-object sibling of
 * `wp_get_post_terms()` — is used instead of looping a single-post call per
 * candidate: same taxonomy query, same default ordering, issued ONCE for
 * the whole page instead of once per row.
 */
final class PosicionResolver {

    /**
     * Literal copy of `entre-redes-api`'s own `$pos_map` — see this class's
     * docblock. Do NOT add/remove/reorder entries here without checking
     * that file first: any divergence is exactly the cross-screen mismatch
     * this class exists to prevent.
     */
    private const POS_MAP = [
        3   => 'Arquero',
        5   => 'Defensor',
        8   => 'Mediocampista',
        9   => 'Delantero',
        125 => 'Arquero Sup.',
    ];

    /**
     * Same fallback `entre-redes-api` returns for a player with no
     * recognized `sp_position` term (none assigned, or only ids this map
     * does not know).
     */
    public const SIN_POSICION = 'Sin Posicion';

    /** The position name for term 3 — see self::POS_MAP. */
    public const POSICION_ARQUERO = 'Arquero';

    /** The position name for term 125 — see self::POS_MAP. */
    public const POSICION_ARQUERO_SUPLENTE = 'Arquero Sup.';

    /**
     * Whether $posicionName makes a PLAYER "a goalkeeper" for
     * `Dictamen\Reglas\ArqueroNoOcupaPlazaDeCampo`'s CANDIDATE definition —
     * true for a titular goalkeeper (term 3, "Arquero") OR a backup
     * goalkeeper (term 125, "Arquero Sup."). The process owner confirmed
     * explicitly that a backup goalkeeper is a goalkeeper for that rule.
     *
     * *** DELIBERATELY A DIFFERENT SET THAN esPosicionDelArqueroTitular()
     * BELOW *** These two predicates must never be unified into one — see
     * that method's own docblock for why.
     */
    public static function esPosicionDeArquero( string $posicionName ): bool {
        return self::POSICION_ARQUERO === $posicionName || self::POSICION_ARQUERO_SUPLENTE === $posicionName;
    }

    /**
     * Whether $posicionName makes a PLAZA "the goalkeeper's plaza" for
     * `Dictamen\Reglas\ArqueroNoOcupaPlazaDeCampo`'s PLAZA definition — true
     * ONLY for a titular goalkeeper (term 3, "Arquero"). Deliberately
     * excludes term 125 ("Arquero Sup."): there are exactly 30 "Arquero"
     * titulares in season 2026 (one per team), which is what makes "the
     * goal" an identifiable, singular plaza per team at all. Including term
     * 125 here would let up to 13 additional plazas (season 2026) count as
     * "the goalkeeper's plaza" too, breaking that one-per-team invariant —
     * a plaza whose titular is "Arquero Sup." must be treated as an
     * ordinary FIELD plaza by this predicate, even though that SAME player
     * counts as a goalkeeper under esPosicionDeArquero() above.
     */
    public static function esPosicionDelArqueroTitular( string $posicionName ): bool {
        return self::POSICION_ARQUERO === $posicionName;
    }

    /**
     * @param array<int, int> $playerIds
     * @return array<int, string> player_id => main position name. EVERY id
     *         in $playerIds is present in the result (defaulted to
     *         self::SIN_POSICION when unresolved), so a caller can always
     *         safely index into it for every id it asked for, rather than
     *         having to fall back on a missing key itself.
     */
    public function resolverParaIds( array $playerIds ): array {
        $resultado = array_fill_keys( $playerIds, self::SIN_POSICION );

        if ( empty( $playerIds ) ) {
            return $resultado;
        }

        $terms = wp_get_object_terms( $playerIds, 'sp_position', [ 'fields' => 'all_with_object_id' ] );

        if ( ! is_array( $terms ) ) {
            return $resultado;
        }

        // Grouped by object_id, preserving wp_get_object_terms()'s own
        // returned order within each group — the exact order a per-post
        // wp_get_post_terms() call would also return for that SAME player
        // (same taxonomy query, same default ordering), which is what
        // "first match wins" below depends on — see this class's own
        // docblock.
        $termIdsPorJugador = [];
        foreach ( $terms as $term ) {
            $termIdsPorJugador[ (int) $term->object_id ][] = (int) $term->term_id;
        }

        foreach ( $termIdsPorJugador as $playerId => $termIds ) {
            foreach ( $termIds as $termId ) {
                if ( isset( self::POS_MAP[ $termId ] ) ) {
                    $resultado[ $playerId ] = self::POS_MAP[ $termId ];
                    break;
                }
            }
        }

        return $resultado;
    }
}

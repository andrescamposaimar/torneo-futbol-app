<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Observability\WpEventLog;

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
 *
 * *** A FAILED QUERY MUST NEVER READ AS "NOBODY HAS A POSITION" (0.1.14) ***
 * `wp_get_object_terms()` returns a `WP_Error` — not an array — when the
 * taxonomy it is asked about does not exist yet, among other failure modes.
 * Before this fix, `resolverParaIds()` treated anything that was not an
 * `array` (a `WP_Error` included) as "found nothing" and returned every
 * requested id mapped to `self::SIN_POSICION` — exactly like a genuine "this
 * player has no sp_position term". That silent degradation is the root cause
 * of a real production incident: `Migrations\MigrationRunner::runIfOutdated()`
 * used to run on `plugins_loaded`, BEFORE SportsPress registers its
 * `sp_position` taxonomy on `init` (priority 10) — see `Plugin::boot()`'s own
 * docblock for the fix on that side. With the taxonomy not yet registered,
 * every single call this class made during the 0.1.13 backfill got a
 * `WP_Error` back, silently read as "nobody is a goalkeeper", and
 * `Migrations\MigrationRunner::backfillEsArco()` wrote `es_arco = 0` for
 * every one of the 330 live plazas — the EXACT broken state that column
 * exists to prevent.
 *
 * `resolverParaIds()` now follows the same "fail loud, never silently
 * degrade" discipline as `Support\ChecksReads::assertReadSucceeded()`: a
 * non-array return (`WP_Error` or anything else `wp_get_object_terms()` might
 * someday return that is not an array) is logged as `posicion.resolucion_fallida`
 * on this instance's EventLog, then thrown as a `\RuntimeException` — a
 * caller can no longer mistake "I could not ask" for "the answer is no one".
 * See each caller's own docblock (`Plazas\PlazaRepository::doOpenPlaza()`,
 * `Plazas\Alta\TitularesListImporter::planificar()`,
 * `Dictamen\DictamenContextAssembler::resolverPosicionesArquero()`,
 * `Plazas\CandidatosResolver::buscarPaginado()`) for what each one does with
 * that exception — every one of them already lets it propagate rather than
 * catching it, which is itself the fix: a candidate list that could not
 * resolve positions must fail the whole request, never silently admit a
 * goalkeeper into a field plaza.
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

    private EventLog $eventLog;

    /**
     * @param EventLog|null $eventLog Defaults to a plain `WpEventLog`
     *        instance — overridable in tests so a forced failure can be
     *        asserted against an `InMemoryEventLog` instead. See class
     *        docblock, "A FAILED QUERY MUST NEVER READ AS 'NOBODY HAS A
     *        POSITION'". Every current caller of this class
     *        (`Plazas\PlazaRepository`, `Plazas\Alta\TitularesListImporter`,
     *        `Dictamen\DictamenContextAssembler`, `Plazas\CandidatosResolver`,
     *        `Migrations\MigrationRunner::backfillEsArco()`) already carries
     *        its own `EventLog` and now threads it through here explicitly,
     *        so a `posicion.resolucion_fallida` event always lands in the
     *        SAME log the rest of that caller's own events go to — the
     *        default below only matters for a call site that does not
     *        (currently none in production).
     */
    public function __construct( ?EventLog $eventLog = null ) {
        $this->eventLog = $eventLog ?? new WpEventLog();
    }

    /**
     * @param array<int, int> $playerIds
     * @return array<int, string> player_id => main position name. EVERY id
     *         in $playerIds is present in the result (defaulted to
     *         self::SIN_POSICION when unresolved), so a caller can always
     *         safely index into it for every id it asked for, rather than
     *         having to fall back on a missing key itself.
     * @throws \RuntimeException When `wp_get_object_terms()` fails (returns a
     *         `WP_Error` or anything else that is not an array) — see class
     *         docblock, "A FAILED QUERY MUST NEVER READ AS 'NOBODY HAS A
     *         POSITION'".
     */
    public function resolverParaIds( array $playerIds ): array {
        $resultado = array_fill_keys( $playerIds, self::SIN_POSICION );

        if ( empty( $playerIds ) ) {
            return $resultado;
        }

        $terms = wp_get_object_terms( $playerIds, 'sp_position', [ 'fields' => 'all_with_object_id' ] );

        if ( ! is_array( $terms ) ) {
            $mensaje = $terms instanceof \WP_Error ? $terms->message : 'wp_get_object_terms() did not return an array.';

            $this->eventLog->record( 'posicion.resolucion_fallida', [
                'operacion'  => 'resolverParaIds',
                'player_ids' => $playerIds,
                'mensaje'    => $mensaje,
            ] );

            throw new \RuntimeException(
                'PosicionResolver::resolverParaIds(): wp_get_object_terms() failed to resolve sp_position for '
                . count( $playerIds ) . " player id(s) ({$mensaje}). Refusing to silently treat this as "
                . '"nobody has a position" — see class docblock, "A FAILED QUERY MUST NEVER READ AS \'NOBODY '
                . 'HAS A POSITION\'".'
            );
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

<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\DictamenContext;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\Regla;

/**
 * A goalkeeper may not take over a plaza that is not the goalkeeper's plaza —
 * as the process owner stated it, literally. This rule is THE gate: it is
 * what actually refuses a solicitud a captain (or a hand-crafted request, or
 * an older app build) sends for a field plaza naming a goalkeeper as
 * entrante. `Plazas\CandidatosResolver::buscarPaginado()` ALSO hides a
 * goalkeeper from the candidate list for a field plaza — that is a
 * convenience (so a captain never sees a candidate this rule would reject
 * anyway), never a substitute for this rule: `Solicitudes\SolicitudRepository::publicarLote()`
 * re-runs the whole dictamen fresh before applying, so a request built by
 * hand against the raw REST endpoint still gets refused here regardless of
 * what the candidate list ever showed.
 *
 * *** TWO DEFINITIONS, DELIBERATELY NOT THE SAME SET ***
 * This rule reads two independently-resolved booleans off DictamenContext —
 * see Plazas\PosicionResolver's own docblocks for each predicate's exact
 * contract:
 *
 *   - `entranteEsArquero()` — the CANDIDATE definition. True when the
 *     entrante's `sp_position` is term 3 ("Arquero") OR term 125 ("Arquero
 *     Sup."). The process owner confirmed explicitly that a BACKUP
 *     goalkeeper is a goalkeeper for this rule.
 *   - `plazaEsDelArquero()` — the PLAZA definition. True ONLY when the
 *     plaza's TITULAR position is term 3 ("Arquero"). There are exactly 30
 *     of those in season 2026 — one per team — which is what makes "the
 *     goal" an identifiable, singular plaza per team at all. Including term
 *     125 here would create up to 13 EXTRA "goal plazas" and destroy that
 *     invariant.
 *
 * Unifying these two predicates into one would be a bug, not a
 * simplification: a backup goalkeeper (term 125) must still be refused for
 * a field plaza (candidate definition includes them), but a plaza whose OWN
 * titular happens to be a backup goalkeeper must NOT be treated as "the
 * goalkeeper's plaza" (plaza definition excludes them) — see
 * Plazas\PosicionResolver::esPosicionDeArquero() /
 * ::esPosicionDelArqueroTitular() for where each set is defined once.
 *
 * *** THE RULE IS ASYMMETRIC, ON PURPOSE ***
 * This rule ONLY blocks goalkeeper -> field plaza. It must NEVER block
 * field player -> goalkeeper's plaza: that direction is the whole point of
 * the pending "exención del arco" (see this plugin's own README /
 * PuntajeDentroDelTecho's class docblock, "KNOWN GAP, DELIBERATELY NOT
 * IMPLEMENTED: EL ARQUERO QUE PASA AL CAMPO") and is legitimate today. This
 * rule therefore has no opinion at all when `entranteEsArquero()` is false —
 * it returns null immediately, regardless of `plazaEsDelArquero()`.
 *
 * *** DOES NOT APPLY TO A `regreso` ***
 * A `regreso` never carries an entrante (SolicitudDeCambio::regreso()'s own
 * guarantee) — `DictamenContext::entranteEsArquero()` is `false` by
 * convention for one, same as `entrantePuntaje()`/`entranteEsPadre()` — so
 * this rule never fires for one.
 */
final class ArqueroNoOcupaPlazaDeCampo implements Regla {

    private const CODE = 'arquero_no_ocupa_plaza_de_campo';

    public function evaluate( DictamenContext $ctx ): ?Motivo {
        if ( ! $ctx->entranteEsArquero() ) {
            // Covers BOTH a `regreso` (entranteEsArquero() is always false —
            // see class docblock) and the legitimate field-player-into-goal
            // direction this rule must never block — see class docblock,
            // "THE RULE IS ASYMMETRIC, ON PURPOSE".
            return null;
        }

        if ( $ctx->plazaEsDelArquero() ) {
            // A goalkeeper taking over the goalkeeper's own plaza is exactly
            // what the reglamento expects — no objection.
            return null;
        }

        return new Motivo(
            self::CODE,
            'El jugador elegido es arquero y esta plaza no es la plaza del arquero: un arquero no puede ocupar una plaza de campo.'
        );
    }
}

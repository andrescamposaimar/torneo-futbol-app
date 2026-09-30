<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\DictamenContext;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\Regla;
use EntreRedes\Cambios\Plazas\Puntaje;

/**
 * The entrante's puntaje must not exceed the plaza's techo — delegated
 * entirely to Plazas\Puntaje::allows(), which already folds in "la regla del
 * 2,5" via `techoEfectivo()`. This rule does NOT re-implement that floor; it
 * only reads `cambios_plaza.puntaje_techo` and asks the value object.
 *
 * Does not apply to a `regreso` — the titular's own puntaje already fixed
 * the plaza's techo when Plazas\PlazaRepository::openPlaza() created it, and
 * a return is never re-evaluated against it.
 *
 * *** A MISSING PUNTAJE IS NEVER READ AS "NO OBJECTION" ***
 * `DictamenContext::entrantePuntaje()` is `null` in TWO situations that must
 * NOT be treated the same. For a `regreso` it is null by the type's own
 * guarantee (SolicitudDeCambio::regreso() cannot carry an entrante — see that
 * class's docblock), exactly like EntranteDisponible/EntranteNoEsElSaliente
 * gate on `entrantePlayerId() === null`. But for a `sustitucion`, a null
 * puntaje means the caller failed to resolve the entrante's actual score —
 * an external-data gap, not a fact the type system promises. Letting THAT
 * case fall through to "no objection" would mean a corrupted lookup (a
 * `?? null` upstream, a JOIN that misses) silently lets any player into any
 * plaza, defeating the exact ceiling this rule exists to enforce. So a
 * `sustitucion` with no resolvable puntaje is reported as its own motivo
 * instead — fail closed, never fail open.
 *
 * *** KNOWN GAP, DELIBERATELY NOT IMPLEMENTED: EL ARQUERO QUE PASA AL CAMPO
 * ***
 * The reglamento's text reads as allowing a field player to occupy a
 * goalkeeper's plaza even when that player's own puntaje exceeds the plaza's
 * techo — goalkeeping is treated as a different skill, not a higher-scoring
 * substitute position. This rule enforces the GENERAL case only (a techo
 * blocks regardless of position) because implementing the exception needs
 * TWO things this slice does not have:
 *
 *   1. The process owner's confirmation that the exception is real policy,
 *      not just informal understanding.
 *   2. A MODEL FIELD that does not exist yet: nothing in `cambios_plaza`
 *      marks a plaza as "the goalkeeper's plaza" — no column on that
 *      table distinguishes playing position at all, and goalkeeper-ness
 *      lives on `sp_position` (SportsPress), a concept this schema never
 *      joins against. Adding a
 *      one-off `if ($esArquero)` here without that column would mean
 *      guessing at a data point that plainly is not there — see this
 *      slice's task instructions: "no inventes el dato".
 *
 * Both must exist before a second implementation (arquero-aware) can be
 * added; until then, this rule stays the strict, position-agnostic reading.
 */
final class PuntajeDentroDelTecho implements Regla {

    private const CODE               = 'puntaje_excede_techo';
    private const CODE_INDETERMINADO = 'entrante_puntaje_indeterminado';

    public function evaluate( DictamenContext $ctx ): ?Motivo {
        $entrantePuntaje = $ctx->entrantePuntaje();

        if ( null === $entrantePuntaje ) {
            if ( $ctx->solicitud()->isRegreso() ) {
                // Legitimate: a `regreso` never carries an entrante (see
                // class docblock and SolicitudDeCambio's own guarantee).
                return null;
            }

            // A `sustitucion` with no resolvable puntaje is a data gap, not
            // an absence of objection — see class docblock, "A MISSING
            // PUNTAJE IS NEVER READ AS 'NO OBJECTION'".
            return new Motivo(
                self::CODE_INDETERMINADO,
                'No se pudo determinar el puntaje del entrante: la solicitud no puede evaluarse contra el techo de la plaza.'
            );
        }

        $techo = Puntaje::fromHalfPoints( (int) $ctx->plaza()['puntaje_techo'] );

        if ( $techo->allows( $entrantePuntaje ) ) {
            return null;
        }

        return new Motivo(
            self::CODE,
            sprintf(
                'El puntaje del entrante (%s) supera el techo admitido por la plaza (techo %s, techo efectivo %s).',
                (string) $entrantePuntaje,
                (string) $techo,
                (string) $techo->techoEfectivo()
            )
        );
    }
}

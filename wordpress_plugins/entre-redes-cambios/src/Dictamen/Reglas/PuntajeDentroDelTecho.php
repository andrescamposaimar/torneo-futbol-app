<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\ContextoDeDictamen;
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
 *      marks a plaza as "the goalkeeper's plaza" — `tipo` only distinguishes
 *      'campo' vs 'suplente', and goalkeeper-ness lives on `sp_position`
 *      (SportsPress), a concept this schema never joins against. Adding a
 *      one-off `if ($esArquero)` here without that column would mean
 *      guessing at a data point that plainly is not there — see this
 *      slice's task instructions: "no inventes el dato".
 *
 * Both must exist before a second implementation (arquero-aware) can be
 * added; until then, this rule stays the strict, position-agnostic reading.
 */
final class PuntajeDentroDelTecho implements Regla {

    private const CODE = 'puntaje_excede_techo';

    public function evaluar( ContextoDeDictamen $ctx ): ?Motivo {
        $entrantePuntaje = $ctx->entrantePuntaje();

        if ( null === $entrantePuntaje ) {
            return null;
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

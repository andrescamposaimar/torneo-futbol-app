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
 * *** THE GAP ABOVE IS NOW CLOSED: EL CAMPO QUE PASA AL ARCO (0.1.15) ***
 * Earlier releases of this rule enforced the techo unconditionally and
 * documented a known, deliberately-unimplemented gap: the reglamento reads as
 * allowing a field player to occupy the goalkeeper's plaza even when that
 * player's own puntaje exceeds the plaza's techo — goalkeeping is a
 * different skill, not a higher-scoring substitute position. Closing that
 * gap needed two things this rule's own docblock named explicitly: the
 * process owner's confirmation that the exception is real policy (it is —
 * see `Solicitudes\SolicitudRepository`'s class docblock), and a model field
 * identifying "the goalkeeper's plaza" without guessing (`cambios_plaza.es_arco`,
 * added in 0.1.13, well before this gap closed). Both now exist, so THIS
 * rule skips its own check entirely for that one leg — see
 * `DictamenContext::exencionArco()`'s own docblock for exactly which
 * evaluation that is, and why the flag lives on the context rather than on
 * this rule's constructor (unlike `Reglas\PrioridadDePadresRespetada`'s
 * `$activa`, which is a global POLICY toggle — this is a per-evaluation FACT
 * about which leg of which solicitud is being judged, so it cannot be fixed
 * once at `DictamenEngineFactory::create()` time the way a policy can).
 */
final class PuntajeDentroDelTecho implements Regla {

    private const CODE               = 'puntaje_excede_techo';
    private const CODE_INDETERMINADO = 'entrante_puntaje_indeterminado';

    public function evaluate( DictamenContext $ctx ): ?Motivo {
        if ( $ctx->exencionArco() ) {
            // Movement 1 of a grouped goalkeeper reassignment — the goal
            // plaza's techo does not apply. See class docblock, "THE GAP
            // ABOVE IS NOW CLOSED: EL CAMPO QUE PASA AL ARCO". Skips the
            // check entirely, including the "indeterminado" fail-closed
            // branch below: with no ceiling to compare against, an
            // unresolved puntaje is nothing this rule has an opinion about
            // for this leg.
            return null;
        }

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

<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\DictamenContext;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\Regla;

/**
 * The reglamento's "prioridad para padres" — historically a SOFT rule nobody
 * ever enforced: an invitado histórico could be requested as a reemplazo even
 * while a padre of the same puntaje waited on the candidate pool, and nothing
 * in this engine ever objected. This rule is what makes that preference an
 * actual gate, but ONLY when the process owner has turned it on — see
 * Calendario\Settings::prioridadPadresActiva(), default OFF.
 *
 * *** THE RULE, PRECISELY *** A non-padre entrante is ineligible for a
 * `sustitucion` when — and ONLY when — ALL THREE hold:
 *   1. `Calendario\Settings::prioridadPadresActiva()` is `true`.
 *   2. The entrante is NOT a padre (`DictamenContext::entranteEsPadre()`).
 *   3. At least one padre is VIABLE for this exact plaza right now
 *      (`DictamenContext::padresViablesParaLaPlaza() > 0`).
 *
 * This is NOT "sort candidates by padre-ness" — it is a CONDITIONAL
 * restriction: a non-padre's eligibility depends on the rest of the pool,
 * which is exactly why `Plazas\CandidatosResolver` exists (see its own class
 * docblock) rather than this rule trying to answer "does a padre exist" on
 * its own from raw context data.
 *
 * *** DOES NOT APPLY TO A `regreso` *** A regreso has no entrante at all
 * (`DictamenContext::entranteEsPadre()` defaults `false` for one, same
 * convention as `entrantePuntaje()` — see DictamenContext's own docblock),
 * and `padresViablesParaLaPlaza()` is always `0` for one too (the assembler
 * never computes it without an entrante) — this rule's guard clause on
 * `entrantePlayerId() === null` makes that explicit rather than relying on
 * the zero-value default to happen to be correct.
 *
 * *** WHY THE MOTIVO REPORTS THE COUNT *** A captain told simply "hay
 * prioridad de padres" with no number has no way to judge whether asking
 * again next week might go differently — `Motivo::datos()['padresViables']`
 * and the Spanish message both carry the exact count
 * `Plazas\CandidatosResolver::contarPadresViables()` found, mirroring
 * Reglas\RegresoSoloConMinimoCumplido's own `fechasFaltantes` convention.
 */
final class PrioridadDePadresRespetada implements Regla {

    private const CODE = 'prioridad_de_padres_no_respetada';

    private bool $activa;

    /**
     * @param bool $activa Mirrors `Calendario\Settings::prioridadPadresActiva()`
     *        — injected explicitly by whoever wires the ruleset together
     *        (see `DictamenEngineFactory::create()` and `Plugin::boot()`),
     *        never read from Settings by this rule itself: a Regla is a
     *        PURE function of `DictamenContext`, zero DB (see `Regla`'s own
     *        interface docblock).
     */
    public function __construct( bool $activa = false ) {
        $this->activa = $activa;
    }

    public function evaluate( DictamenContext $ctx ): ?Motivo {
        if ( ! $this->activa ) {
            return null;
        }

        if ( null === $ctx->solicitud()->entrantePlayerId() ) {
            return null;
        }

        if ( $ctx->entranteEsPadre() ) {
            return null;
        }

        $padresViables = $ctx->padresViablesParaLaPlaza();

        if ( $padresViables <= 0 ) {
            return null;
        }

        return new Motivo(
            self::CODE,
            sprintf(
                'El entrante no es padre y hay %d padre(s) viable(s) para esta plaza — el reglamento da prioridad a los padres.',
                $padresViables
            ),
            [ 'padresViables' => $padresViables ]
        );
    }
}

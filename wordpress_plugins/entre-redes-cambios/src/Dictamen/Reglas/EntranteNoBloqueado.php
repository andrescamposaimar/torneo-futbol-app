<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\BloqueoReemplazoEvaluator;
use EntreRedes\Cambios\Dictamen\BloqueoReemplazoPolicy;
use EntreRedes\Cambios\Dictamen\DictamenContext;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\Regla;

/**
 * The entrante of a `sustitucion` cannot be blocked by having left ANOTHER
 * plaza `trunca` (before meeting the 3-fecha mínimo) — see
 * Plazas\CadenaResolver's class docblock for what `trunca` means and why the
 * whole plaza (not the individual ex-occupant) liberates at once.
 *
 * *** CC5b — WHICH POLICY DECIDES HOW LONG THE BLOCK LASTS IS INJECTED, NOT
 * HARDCODED *** See BloqueoReemplazoPolicy's class docblock for the full
 * rationale of the ambiguity and why `topeTresFechas()` is the default. This
 * class exists specifically so that policy is a constructor parameter, never
 * an `if` buried in a method body — a later confirmation from the process
 * owner should be a one-line change at whoever wires DictamenEngine's rules
 * together, not a code change here.
 *
 * `DictamenContext::entrantePlazasConCierreTruncado()` hands this rule
 * the FULL chain of every other plaza where the entrante has a trunca
 * closure — this rule evaluates the injected policy against every one of
 * them and blocks on the first match; an entrante with no trunca closures
 * anywhere is never blocked, trivially.
 *
 * *** THE ACTUAL PER-CHAIN ALGORITHM LIVES IN BloqueoReemplazoEvaluator ***
 * Extracted so Plazas\CandidatosResolver can answer "is this OTHER candidate
 * blocked" with the exact same logic — see that class's own docblock,
 * "WHY THIS EXISTS AT ALL". This rule is now a thin adapter: it reads
 * DictamenContext, delegates the actual blocking decision, and shapes the
 * Motivo.
 */
final class EntranteNoBloqueado implements Regla {

    private const CODE = 'entrante_bloqueado_por_cierre_truncado';

    private BloqueoReemplazoPolicy $politica;
    private BloqueoReemplazoEvaluator $evaluador;

    public function __construct( ?BloqueoReemplazoPolicy $politica = null, ?BloqueoReemplazoEvaluator $evaluador = null ) {
        $this->politica  = $politica ?? BloqueoReemplazoPolicy::topeTresFechas();
        $this->evaluador = $evaluador ?? new BloqueoReemplazoEvaluator();
    }

    public function evaluate( DictamenContext $ctx ): ?Motivo {
        $entrantePlayerId = $ctx->solicitud()->entrantePlayerId();

        if ( null === $entrantePlayerId ) {
            return null;
        }

        if ( $this->evaluador->isBlockedEnAlguna(
            $entrantePlayerId,
            $ctx->entrantePlazasConCierreTruncado(),
            $this->politica,
            $ctx->countResolvedFechasSinceFn()
        ) ) {
            return new Motivo(
                self::CODE,
                'El entrante está bloqueado por haber dejado trunca otra plaza — ver CC5b (política pendiente de confirmación).',
                // See CC5b in this class's docblock and BloqueoReemplazoPolicy's
                // own docblock: WHICH reading decided this motivo is not yet
                // confirmed policy, so it must travel with the dictamen rather
                // than live only in this rule's constructor argument — the
                // process owner's eventual answer needs to be checkable
                // against what was actually applied, not re-derived from a
                // wiring decision made months earlier.
                [ 'politica' => $this->politica->isHastaLiberacionDePlaza() ? 'hasta_liberacion_de_plaza' : 'tope_tres_fechas' ]
            );
        }

        return null;
    }
}

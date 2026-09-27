<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\ContextoDeDictamen;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\Regla;
use EntreRedes\Cambios\Plazas\CadenaResolver;
use EntreRedes\Cambios\Plazas\Exception\FechaCountUnavailableException;

/**
 * Only applies to `regreso`: the titular may not return before the plaza's
 * vigent occupant has cleared the 3-fecha mínimo — delegated entirely to
 * Plazas\CadenaResolver::canTitularReturn(), which is already fail-closed
 * (see that class's docblock).
 *
 * `Motivo::datos()['fechasFaltantes']` carries the missing count so a human
 * reading the dictamen — or `Dictamen::fechasFaltantesParaLiberacion()` —
 * does not have to parse it back out of the Spanish message. It is `null`
 * specifically when `Plazas\CadenaResolver::countFechasUntilLiberacion()`
 * itself throws `FechaCountUnavailableException` while computing that exact
 * number — a SECOND, independent call to the same injected counter than the
 * one `canTitularReturn()` already made internally (and already caught,
 * fail-closed, to reach `false` in the first place). This rule cannot
 * silently report an exact count it could not actually verify, so it
 * reports the block without one rather than guessing.
 */
final class RegresoSoloConMinimoCumplido implements Regla {

    private const CODE = 'regreso_antes_del_minimo';

    public function evaluar( ContextoDeDictamen $ctx ): ?Motivo {
        if ( ! $ctx->solicitud()->esRegreso() ) {
            return null;
        }

        $vigente = $ctx->vigente();

        if ( null === $vigente ) {
            // Reglas\PlazaConOcupacionVigente already reports this; there is
            // no occupant to evaluate a "return" against.
            return null;
        }

        $titularPlayerId = (int) $ctx->plaza()['titular_player_id'];

        if ( (int) $vigente['player_id'] === $titularPlayerId ) {
            // The titular already occupies the plaza — nothing to return
            // FROM. Not this rule's problem to report.
            return null;
        }

        $resolver = new CadenaResolver( $ctx->countResolvedFechasSinceFn() );

        if ( $resolver->canTitularReturn( $ctx->plaza(), $ctx->ocupaciones() ) ) {
            return null;
        }

        try {
            $faltan = $resolver->countFechasUntilLiberacion( $ctx->ocupaciones() );
        } catch ( FechaCountUnavailableException $e ) {
            return new Motivo(
                self::CODE,
                'El titular todavía no puede volver, pero no se pudo determinar cuántas fechas faltan (dato no disponible).',
                [ 'fechasFaltantes' => null ]
            );
        }

        return new Motivo(
            self::CODE,
            sprintf( 'Faltan %d fecha(s) resuelta(s) para que el titular pueda volver.', $faltan ),
            [ 'fechasFaltantes' => $faltan ]
        );
    }
}

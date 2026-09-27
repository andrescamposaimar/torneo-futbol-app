<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\DictamenContext;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\Regla;

/**
 * A plaza cannot be succeeded (by a `sustitucion`) or returned to (by a
 * `regreso`) unless it currently has a VIGENT ocupación — see
 * DictamenContext::vigente()'s docblock.
 *
 * In practice this should never fire — every plaza is created with a
 * genesis ocupación in the same transaction as the plaza itself (see
 * Plazas\PlazaRepository::openPlaza()'s docblock), and every subsequent
 * change opens a new link in the same transaction it closes the old one.
 * This rule exists as the ruleset's own safety net against a corrupted or
 * incorrectly assembled DictamenContext, reported the same way as any
 * other business motivo rather than as an uncaught exception — a captain
 * reading a dictamen should never see a stack trace where a Motivo belongs.
 *
 * Applies to BOTH tipos: a `regreso` needs a vigent occupant to close just
 * as much as a `sustitucion` does.
 */
final class PlazaConOcupacionVigente implements Regla {

    private const CODE = 'plaza_sin_ocupacion_vigente';

    public function evaluate( DictamenContext $ctx ): ?Motivo {
        if ( null !== $ctx->vigente() ) {
            return null;
        }

        return new Motivo(
            self::CODE,
            'La plaza no tiene una ocupación vigente para suceder.'
        );
    }
}

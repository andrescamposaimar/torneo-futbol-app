<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\DictamenContext;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\Regla;

/**
 * The entrante of a `sustitucion` cannot be the very player currently vigent
 * in the plaza — nobody replaces themself.
 *
 * DELIBERATELY compares against `DictamenContext::vigente()` — whoever
 * currently occupies the plaza — never against `titular_player_id`. That is
 * what lets "el cambio de cambio" (superseding a suplente who is not the
 * titular) fall under this exact same check with no special case: the
 * saliente is whoever is vigent, full stop, same as every other rule that
 * needs to know who is being succeeded.
 *
 * Does not apply to a `regreso` — there is no entrante to compare.
 */
final class EntranteNoEsElSaliente implements Regla {

    private const CODE = 'entrante_es_el_saliente';

    public function evaluate( DictamenContext $ctx ): ?Motivo {
        $entrantePlayerId = $ctx->solicitud()->entrantePlayerId();

        if ( null === $entrantePlayerId ) {
            return null;
        }

        $vigente = $ctx->vigente();

        if ( null === $vigente ) {
            // Reglas\PlazaConOcupacionVigente reports the real problem;
            // there is no saliente here to compare the entrante against.
            return null;
        }

        if ( $entrantePlayerId !== (int) $vigente['player_id'] ) {
            return null;
        }

        return new Motivo(
            self::CODE,
            'El entrante ya ocupa esta plaza: no puede reemplazarse a sí mismo.'
        );
    }
}

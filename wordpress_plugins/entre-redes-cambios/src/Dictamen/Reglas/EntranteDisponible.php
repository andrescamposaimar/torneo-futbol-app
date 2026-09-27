<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\DictamenContext;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\Regla;

/**
 * The entrante of a `sustitucion` cannot already be occupying a DIFFERENT
 * plaza vigently, within the same season — a player fields for one plaza at
 * a time.
 *
 * `DictamenContext::entranteOcupacionesEnOtrasPlazas()` is handed BOTH
 * vigent and already-closed rows deliberately (see that accessor's
 * docblock) — this rule owns the "vigent" filter itself
 * (`fecha_hasta_id === null`) rather than trusting a pre-filtered list,
 * exactly like every other rule reads "vigent" the same way (see
 * DictamenContext::vigente()'s docblock for the plaza's OWN chain — this
 * rule inlines the identical check because it is scanning OTHER plazas'
 * rows, not the one `vigente()` exposes).
 *
 * Does not apply to a `regreso` — the titular returning to their own plaza
 * is never "occupying another plaza" by virtue of this request.
 */
final class EntranteDisponible implements Regla {

    private const CODE = 'entrante_ocupa_otra_plaza_vigente';

    public function evaluate( DictamenContext $ctx ): ?Motivo {
        if ( null === $ctx->solicitud()->entrantePlayerId() ) {
            return null;
        }

        foreach ( $ctx->entranteOcupacionesEnOtrasPlazas() as $ocupacion ) {
            if ( null === ( $ocupacion['fecha_hasta_id'] ?? null ) ) {
                return new Motivo(
                    self::CODE,
                    'El entrante ya ocupa otra plaza vigente en esta misma temporada.'
                );
            }
        }

        return null;
    }
}

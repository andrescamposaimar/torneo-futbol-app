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
 *
 * *** SCOPED EXEMPTION: MOVEMENT 1 OF A GROUPED GOALKEEPER REASSIGNMENT
 * (0.1.15) ***
 * `DictamenContext::exencionArco()` is `true` ONLY when this context is
 * movement 1 of a grouped reassignment (the goal plaza, entrante = the field
 * titular moving into it) — see that accessor's own docblock. The field
 * titular is, BY CONSTRUCTION of this request shape, currently occupying his
 * OWN field plaza vigently — that is not a conflict this rule exists to
 * catch, it is exactly the fact that makes him eligible to be asked to move
 * at all. `Solicitudes\SolicitudRepository::publicarLote()` applies BOTH
 * legs of a grouped reassignment atomically (closing that field-plaza
 * occupation in the very same transaction that opens this one), so he never
 * actually ends up double-booked — see that class's own docblock.
 *
 * This exemption is scoped to EXACTLY this leg: movement 2 (the outside
 * player filling the vacated field plaza) is assembled with
 * `exencionArco=false` always, so an entrante for THAT leg who already
 * occupies another plaza vigently is still rejected here, same as any
 * ordinary `sustitucion`.
 */
final class EntranteDisponible implements Regla {

    private const CODE = 'entrante_ocupa_otra_plaza_vigente';

    public function evaluate( DictamenContext $ctx ): ?Motivo {
        if ( null === $ctx->solicitud()->entrantePlayerId() ) {
            return null;
        }

        if ( $ctx->exencionArco() ) {
            // Movement 1 of a grouped goalkeeper reassignment — see class
            // docblock, "SCOPED EXEMPTION".
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

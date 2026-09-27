<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen\Reglas;

use EntreRedes\Cambios\Calendario\PlazosCalculator;
use EntreRedes\Cambios\Dictamen\ContextoDeDictamen;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\Regla;

/**
 * The solicitud must fall within its fecha's plazo window — a `regreso`
 * bounded by `cierre_regresos`, a `sustitucion` by `cierre_solicitudes`. See
 * "TWO DIFFERENT DEADLINES, ONE WINDOW METHOD" below for why these are not
 * the same check.
 *
 * *** THE TIME FRAME CONTRACT IS UTC, EXPLICITLY — NOT CIVIL ***
 * `Calendario\PlazosCalculator` offers two frames for the same four plazos:
 * `compute()` (civil strings, timezone-invariant by construction) and
 * `computeUtc()` (absolute instants). `SolicitudDeCambio::instanteEpoch()`
 * is a Unix epoch — an absolute instant, not a civil reading — so the ONLY
 * frame it can be compared against without silently reintroducing the
 * three-hour bug PlazosCalculator's own class docblock describes is
 * `computeUtc()`'s. `ContextoDeDictamen::plazosUtc()`'s docblock says so
 * explicitly and this rule trusts that contract rather than re-deriving a
 * timezone here: it converts the epoch to a UTC civil string via `gmdate()`
 * and compares it directly against `plazosUtc()`, never against
 * `compute()`'s output.
 *
 * *** TWO DIFFERENT DEADLINES, ONE WINDOW METHOD ***
 * `PlazosCalculator::isWithinSolicitudWindow()` deliberately checks ONLY
 * `apertura_solicitudes`..`cierre_solicitudes` — its own docblock says
 * `cierre_regresos` is a sub-deadline that lives INSIDE that window, not a
 * bound of it, because that method is generic across every use of the
 * calendar, not specific to este dictamen. But the reglamento's actual
 * operating calendar has TWO closing deadlines, not one — regresos close
 * Tuesday 23:59, sustituciones (cambios) close Thursday 23:59, publicación
 * Friday — so a `regreso` submitted Wednesday must be rejected even though
 * it is still comfortably inside `isWithinSolicitudWindow()`'s outer bound.
 * This rule is where that distinction belongs: it uses
 * `isWithinSolicitudWindow()` as-is for a `sustitucion` (its exact contract),
 * and a direct comparison against `cierre_regresos` for a `regreso`, without
 * asking PlazosCalculator to change its own generic method.
 */
final class SolicitudEnPlazo implements Regla {

    private const CODE = 'fuera_de_plazo';

    public function evaluar( ContextoDeDictamen $ctx ): ?Motivo {
        $plazos = $ctx->plazosUtc();
        $nowUtc = gmdate( 'Y-m-d H:i:s', $ctx->solicitud()->instanteEpoch() );

        if ( $ctx->solicitud()->esRegreso() ) {
            $dentroDePlazo = $nowUtc >= $plazos['apertura_solicitudes'] && $nowUtc <= $plazos['cierre_regresos'];
            $cierre        = $plazos['cierre_regresos'];
            $etiqueta      = 'de regreso';
        } else {
            $dentroDePlazo = PlazosCalculator::isWithinSolicitudWindow( $nowUtc, $plazos );
            $cierre        = $plazos['cierre_solicitudes'];
            $etiqueta      = 'de cambio';
        }

        if ( $dentroDePlazo ) {
            return null;
        }

        return new Motivo(
            self::CODE,
            sprintf( 'La solicitud %s está fuera de plazo (cierre: %s UTC).', $etiqueta, $cierre )
        );
    }
}

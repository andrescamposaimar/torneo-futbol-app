<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Calendario;

/**
 * "The open fecha" of a season, defined in ONE place.
 *
 * It is the earliest fecha whose `estado` is NOT resolved. Shared by the
 * captain-facing `Rest\FechaController` and the commission's
 * `Admin\NuevaSolicitudPage`, so a request loaded from the admin attaches to
 * exactly the fecha the app shows the captain.
 */
final class FechaAbierta {

    /**
     * `listBySeason()` already orders by `orden` ASC, so the first unresolved
     * row IS the earliest one.
     *
     * @return array<string, mixed>|null Null when the season has no
     *         unresolved fecha.
     */
    public static function de( FechaRepository $fechaRepository, int $seasonId ): ?array {
        foreach ( $fechaRepository->listBySeason( $seasonId ) as $fecha ) {
            if ( ! FechaRepository::esResuelta( (string) ( $fecha['estado'] ?? '' ) ) ) {
                return $fecha;
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

/**
 * THE two sections `Rest\PlazasController::listarCandidatos()` lets a captain
 * choose between, and the only two values `CandidatosResolver::paraSeccion()`
 * accepts — see that method's own docblock for what each one enumerates.
 *
 * Neither name editorializes about approval odds — see this slice's own task
 * brief: "the committee's business, and the app must never predict it". Both
 * are neutral descriptions of WHERE a candidate comes from, never a hint
 * about how likely the committee is to approve bringing them in.
 *
 * Same "plain string constants in a final, non-instantiable class" shape as
 * `Solicitudes\EstadoSolicitud` — not a native PHP enum, for consistency with
 * every other closed set of string values this plugin persists or accepts
 * over its REST surface.
 */
final class CandidatosSeccion {

    /** Players on the "lista de espera" pseudo-team — see ListaEsperaResolver. */
    public const LISTA_ESPERA = 'lista_espera';

    /** Every OTHER published `sp_player`, i.e. the whole padrón minus the lista de espera team. */
    public const PADRON_COMPLETO = 'padron_completo';

    /** @var string[] */
    private const TODAS = [ self::LISTA_ESPERA, self::PADRON_COMPLETO ];

    /**
     * Not instantiable — this class is a namespace for closed-set string
     * constants, the same shape as `Solicitudes\EstadoSolicitud`.
     */
    private function __construct() {
    }

    /** @return string[] Every valid `seccion` value. */
    public static function todas(): array {
        return self::TODAS;
    }

    public static function esValida( string $seccion ): bool {
        return in_array( $seccion, self::TODAS, true );
    }
}

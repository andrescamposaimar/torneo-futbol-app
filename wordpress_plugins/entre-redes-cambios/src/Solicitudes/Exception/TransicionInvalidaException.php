<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Solicitudes\Exception;

/**
 * A caller asked `EstadoSolicitud`/`SolicitudRepository` to move a solicitud
 * from one estado to another, and that move is not in the transition graph
 * (`EstadoSolicitud::TRANSICIONES`) — either because $desde is terminal,
 * because $hasta is not one of $desde's legal next states, or because
 * either string is not a recognized estado at all.
 */
class TransicionInvalidaException extends \InvalidArgumentException {

    public function __construct( string $desde, string $hasta ) {
        parent::__construct(
            sprintf(
                "No se puede pasar una solicitud de estado '%s' a '%s' — transición inválida.",
                $desde,
                $hasta
            )
        );
    }
}

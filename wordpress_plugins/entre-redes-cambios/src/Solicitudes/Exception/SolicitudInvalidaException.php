<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Solicitudes\Exception;

/**
 * `Solicitudes\CrearSolicitud` rejected its INPUT before evaluating or
 * storing anything: a missing/invalid field, an unknown tipo, or a plaza that
 * is not the given team's.
 *
 * Distinct from `SolicitudAbiertaException` (a refusal of a well-formed
 * request) and from any other \Throwable (a system failure): callers turn
 * this one into a 400 (REST) or an inline admin error, carrying the stable
 * `codigo` the REST client already keys on.
 */
class SolicitudInvalidaException extends \RuntimeException {

    private string $codigo;

    public function __construct( string $codigo, string $mensaje ) {
        parent::__construct( $mensaje );
        $this->codigo = $codigo;
    }

    public function codigo(): string {
        return $this->codigo;
    }
}

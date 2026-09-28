<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Solicitudes\Exception;

/**
 * A wpdb write against `cambios_solicitud` failed. Same rationale as
 * `Plazas\Exception\PlazaPersistenceException` and
 * `Capitania\Exception\CapitanPersistenceException`: `$wpdb->insert()` /
 * `$wpdb->update()` do not throw on failure, they return `false` — this is
 * thrown as soon as that happens, before any surrounding transaction's
 * COMMIT, so a failed write never silently falls through.
 */
class SolicitudPersistenceException extends \RuntimeException {

    public function __construct( string $operation, ?string $wpdbLastError ) {
        parent::__construct(
            sprintf(
                'SolicitudRepository failed to %s a row: %s',
                $operation,
                $wpdbLastError ?? '(wpdb reported no error message)'
            )
        );
    }
}

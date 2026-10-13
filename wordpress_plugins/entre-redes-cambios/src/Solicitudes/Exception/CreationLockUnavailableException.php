<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Solicitudes\Exception;

/**
 * The per-season creation lock could not be acquired within its bounded wait
 * (0.1.21) — see `Solicitudes\Lock\CreationLock`. Nothing was evaluated and
 * nothing was written. Unlike `SolicitudAbiertaException`, this refusal IS
 * retryable: someone else's creation held the lock; a moment later it is
 * free. `Rest\SolicitudesController` therefore answers 503, never a 409.
 */
class CreationLockUnavailableException extends \RuntimeException {

    public function __construct( int $seasonId ) {
        parent::__construct( "Could not acquire the solicitud-creation lock for season {$seasonId} in time." );
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Notificaciones;

/**
 * The seam `Rest\SolicitudesController` depends on, so the controller's
 * notify guard can be tested without a mailer, a database or a real
 * solicitud — the same reason `Solicitudes\Lock\CreationLock` is an
 * interface rather than the Mysql class.
 *
 * One method, and it RETURNS VOID AND NEVER THROWS by contract: by the time
 * any implementation runs, the solicitud is stored and the captain's
 * request has succeeded. An alert that fails is an operational problem to
 * log, never an error to hand back to a captain who did nothing wrong.
 */
interface NotificaSolicitudes {

    /**
     * Announce to the PROCESS OWNER that $solicitudId was just created.
     * MUST NOT throw.
     */
    public function notificarCreada( int $solicitudId ): void;

    /**
     * Acknowledge to the CAPTAIN that his request was received.
     * MUST NOT throw.
     */
    public function notificarCreadaAlCapitan( int $solicitudId ): void;

    /**
     * Tell the CAPTAIN what the comisión decided. MUST NOT throw, and must
     * ignore any $estado it has no copy written for rather than improvising
     * one.
     */
    public function notificarDecision( int $solicitudId, string $estado ): void;
}

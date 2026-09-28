<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Rest;

/**
 * Registers every /entre-redes/v1/cambios/* REST route this plugin exposes.
 *
 * Slice 4d scope: the CAPTAIN-facing endpoints only —
 * Rest\SolicitudesController (create/list solicitudes) and
 * Rest\PlazasController (plantel status). The process owner's tray
 * (approve/reject/publish the lote) is a later slice — see this plugin's
 * task brief.
 *
 * Mirrors entre-redes-prode's Rest\RestController: one class per route
 * group, each responsible for registering its OWN routes
 * (register_routes()); this class only wires the group together, exactly
 * like that plugin's own top-level controller.
 */
final class RestController {

    public const API_NAMESPACE = 'entre-redes/v1';
    public const BASE          = 'cambios';

    private SolicitudesController $solicitudesController;
    private PlazasController $plazasController;

    public function __construct(
        SolicitudesController $solicitudesController,
        PlazasController $plazasController
    ) {
        $this->solicitudesController = $solicitudesController;
        $this->plazasController      = $plazasController;
    }

    public function register_routes(): void {
        $this->solicitudesController->register_routes();
        $this->plazasController->register_routes();
    }
}

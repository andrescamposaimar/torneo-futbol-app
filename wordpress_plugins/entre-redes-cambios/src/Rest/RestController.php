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
 * Also wires Rest\CapitanController (`/cambios/mis-equipos`) — the
 * bootstrap endpoint every other captain-facing route above assumes the
 * client already got its season_id/team_id from — and Rest\FechaController
 * (`/cambios/fecha-abierta`), the equivalent bootstrap for `fecha_id`: see
 * that controller's own docblock for why `POST /cambios/solicitudes`
 * required a value no route exposed before this one (slice 5 task brief,
 * FIX 2).
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
    private CapitanController $capitanController;
    private FechaController $fechaController;

    public function __construct(
        SolicitudesController $solicitudesController,
        PlazasController $plazasController,
        CapitanController $capitanController,
        FechaController $fechaController
    ) {
        $this->solicitudesController = $solicitudesController;
        $this->plazasController      = $plazasController;
        $this->capitanController     = $capitanController;
        $this->fechaController       = $fechaController;
    }

    public function register_routes(): void {
        $this->solicitudesController->register_routes();
        $this->plazasController->register_routes();
        $this->capitanController->register_routes();
        $this->fechaController->register_routes();
    }
}

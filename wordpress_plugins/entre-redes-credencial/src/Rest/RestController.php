<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Rest;

/**
 * Registers every /entre-redes/v1/credencial/* REST route this plugin
 * exposes. Slice 1b scope: CredencialController's GET route only (POST
 * /credencial/foto is slice 2a's PhotoUploadController, added here later).
 *
 * Mirrors entre-redes-cambios's own Rest\RestController: one class per route
 * group, each responsible for registering its OWN routes
 * (register_routes()); this class only wires the group together.
 */
final class RestController {

    public const API_NAMESPACE = 'entre-redes/v1';
    public const BASE          = 'credencial';

    private CredencialController $credencialController;

    public function __construct( CredencialController $credencialController ) {
        $this->credencialController = $credencialController;
    }

    public function register_routes(): void {
        $this->credencialController->register_routes();
    }
}

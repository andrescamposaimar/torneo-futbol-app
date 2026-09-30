<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Rest;

/**
 * Registers every /entre-redes/v1/credencial/* REST route this plugin
 * exposes: CredencialController's GET route (slice 1b) and
 * PhotoUploadController's POST route (slice 2a).
 *
 * Mirrors entre-redes-cambios's own Rest\RestController: one class per route
 * group, each responsible for registering its OWN routes
 * (register_routes()); this class only wires the group together.
 */
final class RestController {

    public const API_NAMESPACE = 'entre-redes/v1';
    public const BASE          = 'credencial';

    private CredencialController $credencialController;
    private PhotoUploadController $photoUploadController;

    public function __construct( CredencialController $credencialController, PhotoUploadController $photoUploadController ) {
        $this->credencialController  = $credencialController;
        $this->photoUploadController = $photoUploadController;
    }

    public function register_routes(): void {
        $this->credencialController->register_routes();
        $this->photoUploadController->register_routes();
    }
}

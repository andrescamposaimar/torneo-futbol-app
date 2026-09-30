<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Rest;

/**
 * Shared error-envelope plumbing for every credencial-facing REST endpoint
 * (CredencialController, PhotoUploadController) — same rationale as
 * entre-redes-cambios's own Rest\HandlesCapitanAuthorization (a trait, not a
 * base class, because each controller already has its own distinct
 * constructor dependencies).
 *
 * UNLIKE that trait, this one does NOT collapse every authorization failure
 * into one generic body — design D1: "App branches on `code`; fail closed".
 * `respuestaDesdeWpError()` passes CredencialAuthorizer's own WP_Error
 * straight through, reading it via the REAL WP_Error accessor methods
 * (`get_error_code()` etc.), never public properties.
 *
 * `respuestaErrorInterno()` IS the same generic-500 discipline as every other
 * controller in this codebase: never leak an exception's message, class, or
 * trace. Logging the real reason via EventLog is always the caller's own job,
 * BEFORE calling this method — it only builds the body.
 */
trait HandlesCredencialAuthorization {

    private static function respuestaDesdeWpError( \WP_Error $error ): \WP_REST_Response {
        $data   = $error->get_error_data();
        $status = (int) ( is_array( $data ) ? ( $data['status'] ?? 401 ) : 401 );

        return new \WP_REST_Response(
            [
                'code'    => $error->get_error_code(),
                'message' => $error->get_error_message(),
                'data'    => [ 'status' => $status ],
            ],
            $status
        );
    }

    private static function respuestaErrorInterno(): \WP_REST_Response {
        return new \WP_REST_Response(
            [
                'code'    => 'error_interno',
                'message' => 'Ocurrió un error al procesar la solicitud. Probá de nuevo en unos minutos.',
                'data'    => [ 'status' => 500 ],
            ],
            500
        );
    }
}

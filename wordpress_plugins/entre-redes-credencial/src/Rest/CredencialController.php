<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Rest;

use EntreRedes\Credencial\Auth\CredencialAuthorizer;
use EntreRedes\Credencial\Credencial\CredencialService;
use EntreRedes\Credencial\Observability\EventLog;

/**
 * GET /entre-redes/v1/credencial/credencial — the ONLY endpoint this slice
 * ships (POST /credencial/foto is slice 2a).
 *
 * *** AUTHORIZATION RUNS FIRST, ALWAYS *** — same discipline as
 * entre-redes-cambios's own controllers (see their
 * Rest\HandlesCapitanAuthorization docblock). UNLIKE that plugin, this
 * controller does NOT collapse every auth failure into one generic body:
 * CredencialAuthorizer already returns the exact WP_Error prode's own
 * AuthMiddleware would (design D1: "App branches on `code`; fail closed"),
 * so this handler passes it straight through, unchanged.
 *
 * A failure INSIDE CredencialService::resolve() (design D4: an issuance row
 * that cannot be produced) is the ONLY case that gets a generic 500 — same
 * "never leak an exception message/class/trace to the caller" discipline as
 * every other controller in this codebase; the real reason is always logged
 * via EventLog BEFORE this method decides what to answer.
 */
final class CredencialController {

    private CredencialAuthorizer $authorizer;
    private CredencialService $service;
    private EventLog $eventLog;

    /** @var callable(): int */
    private $clockFn;

    /**
     * @param callable(): int|null $clockFn Returns the current instant as a
     *        Unix epoch — see entre-redes-cambios's Rest\PlazasController
     *        constructor docblock for why this is injectable rather than a
     *        direct time() call. Defaults to the real clock.
     */
    public function __construct(
        CredencialAuthorizer $authorizer,
        CredencialService $service,
        EventLog $eventLog,
        ?callable $clockFn = null
    ) {
        $this->authorizer = $authorizer;
        $this->service    = $service;
        $this->eventLog   = $eventLog;
        $this->clockFn    = $clockFn ?? static fn (): int => time();
    }

    public function register_routes(): void {
        register_rest_route(
            RestController::API_NAMESPACE,
            '/' . RestController::BASE . '/credencial',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Response 200: `{state, photo_request, credential}` — design Interfaces
     * section, shaped by Credencial\CredencialState::toArray().
     * Response 401: `{code, message, data:{status:401}}` — one of prode's own
     * `token_missing`/`token_expired`/`token_invalid`/`session_revoked`.
     * Response 500: `{code: 'error_interno', message, data:{status:500}}`.
     */
    public function get( \WP_REST_Request $request ): \WP_REST_Response {
        $now = ( $this->clockFn )();

        $authResult = $this->authorizer->authorize(
            (string) ( $request->get_header( 'authorization' ) ?? '' ),
            $now
        );

        if ( $authResult instanceof \WP_Error ) {
            return self::fromWpError( $authResult );
        }

        try {
            $state = $this->service->resolve( (int) $authResult['player_id'], (int) $authResult['user_id'], $now );

            return new \WP_REST_Response( $state->toArray(), 200 );
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'rest.credencial_get_failed', [
                'excepcion' => get_class( $e ),
                'mensaje'   => $e->getMessage(),
            ] );

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

    /**
     * Reads via the real WP_Error accessor methods (get_error_code() etc.),
     * NEVER via public properties — real WordPress's WP_Error stores these
     * behind accessors; CredencialAuthorizer's own return-type docblock only
     * promises a WP_Error, not this test suite's simplified shim shape.
     */
    private static function fromWpError( \WP_Error $error ): \WP_REST_Response {
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
}

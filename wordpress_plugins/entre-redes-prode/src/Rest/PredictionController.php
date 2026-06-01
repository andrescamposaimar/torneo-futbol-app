<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Rest;

use EntreRedes\Prode\Auth\AuthMiddleware;
use EntreRedes\Prode\Fecha\FechaRepository;
use EntreRedes\Prode\Fecha\Settings;
use EntreRedes\Prode\Predictions\PredictionRepository;

/**
 * REST controller for POST /prode/prediccion.
 *
 * Request contract (JSON body):
 *   { fecha_id: int, match_id: int, score_home: int, score_away: int }
 *
 * Response shapes:
 *   200  { status: "ok" }                         — prediction written
 *   400  { code, message, data: { status: 400 } } — validation failure
 *   401  { code, message, data: { status: 401 } } — auth failure (via requireAuth)
 *   423  { code, message, data: { status: 423 } } — fecha locked, no write
 *
 * All validation errors (invalid score, missing field, match not in fecha)
 * return 400 (user decision — no 422 in this plugin). Lock → 423. Auth → 401.
 *
 * Lock read strategy (ADR-G2-6):
 *   Read prode_fechas.locked_at immediately before writing and compare against
 *   current_time('mysql') to minimize the TOCTOU window. The UNIQUE KEY on
 *   prode_predictions is the final safety net.
 */
class PredictionController {

    private const NAMESPACE = 'entre-redes/v1';

    private PredictionRepository $predRepo;
    private FechaRepository      $fechaRepo;
    private AuthMiddleware       $middleware;

    public function __construct(
        PredictionRepository $predRepo,
        FechaRepository $fechaRepo,
        AuthMiddleware $middleware
    ) {
        $this->predRepo   = $predRepo;
        $this->fechaRepo  = $fechaRepo;
        $this->middleware = $middleware;
    }

    /**
     * Register the POST /prode/prediccion route.
     *
     * requireAuth is used as the permission callback so that auth is enforced
     * before submitPrediction() is called — _prode_user is attached to the
     * request by the time the handler runs.
     */
    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE,
            '/prode/prediccion',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'submitPrediction' ],
                'permission_callback' => [ $this->middleware, 'requireAuth' ],
            ]
        );
    }

    /**
     * Delegate to AuthMiddleware::requireAuth for tests that call it directly.
     *
     * This method exists as a convenience entry point for unit tests that need
     * to test the auth behaviour in isolation without going through
     * register_rest_route(). Production code always enters via the permission
     * callback registered in register_routes().
     *
     * @param \WP_REST_Request $request
     * @return true|\WP_Error
     */
    public function requireAuth( \WP_REST_Request $request ) {
        return $this->middleware->requireAuth( $request );
    }

    /**
     * POST /prode/prediccion
     *
     * Validates the body, enforces the lock, and upserts the prediction.
     *
     * @param \WP_REST_Request $request  Request with _prode_user already set.
     * @return \WP_REST_Response
     */
    public function submitPrediction( \WP_REST_Request $request ): \WP_REST_Response {
        // 1. Parse and validate required fields.
        $fechaId   = $request->get_param( 'fecha_id' );
        $matchId   = $request->get_param( 'match_id' );
        $scoreHome = $request->get_param( 'score_home' );
        $scoreAway = $request->get_param( 'score_away' );

        $requiredFields = [
            'fecha_id'   => $fechaId,
            'match_id'   => $matchId,
            'score_home' => $scoreHome,
            'score_away' => $scoreAway,
        ];

        foreach ( $requiredFields as $field => $value ) {
            if ( null === $value ) {
                return $this->error400(
                    'missing_field',
                    "Required field '{$field}' is missing."
                );
            }
        }

        // 2. Validate score_home and score_away: must be integers in [0, 255].
        if ( ! $this->isValidScore( $scoreHome ) ) {
            return $this->error400(
                'invalid_score',
                'score_home must be an integer between 0 and 255.'
            );
        }
        if ( ! $this->isValidScore( $scoreAway ) ) {
            return $this->error400(
                'invalid_score',
                'score_away must be an integer between 0 and 255.'
            );
        }

        $scoreHomeInt = (int) $scoreHome;
        $scoreAwayInt = (int) $scoreAway;
        $fechaIdInt   = (int) $fechaId;
        $matchIdInt   = (int) $matchId;

        // 3. Validate that match_id belongs to the active fecha.
        $tenantId = defined( 'PRODE_TENANT_ID' ) ? (string) PRODE_TENANT_ID : '';
        $activeFecha = $this->loadActiveFecha( $tenantId, $fechaIdInt );

        if ( null === $activeFecha ) {
            return $this->error400( 'match_not_found', 'No active fecha found for the given fecha_id.' );
        }

        $matchIds = array_column( $activeFecha['matches'], 'match_id' );
        $matchIds = array_map( 'intval', $matchIds );

        if ( ! in_array( $matchIdInt, $matchIds, true ) ) {
            return $this->error400( 'match_not_found', 'The given match_id does not belong to the active fecha.' );
        }

        // 4. Read locked_at immediately before write (ADR-G2-6 — minimize TOCTOU).
        $lockedAt = (string) $activeFecha['fecha']['locked_at'];
        $now      = current_time( 'mysql' );

        if ( $now >= $lockedAt ) {
            return new \WP_REST_Response(
                [
                    'code'    => 'fecha_locked',
                    'message' => 'This fecha is no longer accepting predictions.',
                    'data'    => [ 'status' => 423 ],
                ],
                423
            );
        }

        // 5. Upsert the prediction.
        $user   = $request->get_param( '_prode_user' );
        $userId = (int) ( $user['id'] ?? 0 );

        $this->predRepo->upsert(
            $userId,
            $fechaIdInt,
            $matchIdInt,
            $scoreHomeInt,
            $scoreAwayInt,
            $lockedAt
        );

        return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true when $value is an integer (or integer-typed) in [0, 255].
     *
     * Rejects strings that are not numeric integers and floats.
     */
    private function isValidScore( mixed $value ): bool {
        if ( is_float( $value ) ) {
            return false;
        }
        if ( is_string( $value ) && ! ctype_digit( ltrim( $value, '-' ) ) ) {
            return false;
        }
        if ( is_string( $value ) && str_contains( $value, '-' ) ) {
            // Negative string like "-1".
            return false;
        }
        if ( ! is_int( $value ) && ! is_string( $value ) ) {
            return false;
        }
        $int = (int) $value;
        return $int >= 0 && $int <= 255;
    }

    /**
     * Load the fecha row and its match ids for the given fecha_id.
     *
     * Fetches only open/locked fechas. Uses global $wpdb (established WP pattern,
     * consistent with RestController::healthcheck). Returns null when the fecha is
     * not found or already evaluated.
     *
     * @return array{fecha: array<string, mixed>, matches: array<int, array<string, mixed>>}|null
     */
    private function loadActiveFecha( string $tenantId, int $fechaId ): ?array {
        global $wpdb;
        $p = $wpdb->prefix;

        // Fetch the fecha row directly by id + state to avoid relying on
        // findActiveFecha's season_id lookup (we don't have season_id in the
        // POST body). This also confirms the fecha is in open/locked state.
        $fecha = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$p}prode_fechas
                  WHERE id = %d
                    AND state IN ('open', 'locked')
                  LIMIT 1",
                $fechaId
            ),
            ARRAY_A
        );

        if ( empty( $fecha ) ) {
            return null;
        }

        $matchRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT match_id FROM {$p}prode_fecha_matches
                  WHERE fecha_id = %d",
                $fechaId
            ),
            ARRAY_A
        );

        return [
            'fecha'   => $fecha,
            'matches' => $matchRows ?: [],
        ];
    }

    /**
     * Build a 400 error response using the plugin's established envelope shape.
     */
    private function error400( string $code, string $message ): \WP_REST_Response {
        return new \WP_REST_Response(
            [
                'code'    => $code,
                'message' => $message,
                'data'    => [ 'status' => 400 ],
            ],
            400
        );
    }
}

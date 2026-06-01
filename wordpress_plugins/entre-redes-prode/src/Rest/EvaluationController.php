<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Rest;

use EntreRedes\Prode\Scoring\FechaEvaluator;

/**
 * REST controller for POST /prode/evaluar-fecha.
 *
 * Request contract (JSON body):
 *   { fecha_id: int }
 *
 * Response shapes:
 *   200  { status: "ok", evaluated_matches: int, pending_matches: int, fecha_state: string }
 *   400  { code, message, data: { status: 400 } }  — validation / state failure
 *   401  { code, message, data: { status: 401 } }  — capability check failed
 *
 * Auth gate (ADR-G3-4):
 *   Capability check is an INJECTED callable defaulting to
 *   current_user_can('manage_options'). Injection is mandatory because the
 *   test shim has no current_user_can / wp_set_current_user. Mirrors
 *   FechaResolver's dispatcher seam (ADR-G0-4).
 *
 * Spec A3: endpoint only accepts fechas in 'locked' state. Attempting to
 * evaluate an already-'evaluated' fecha returns 400 fecha_not_locked.
 * Idempotent re-evaluation is available via the cron (EvaluatorCron::run).
 *
 * Spec A2: evaluated_matches = COUNT(DISTINCT match_id) WHERE method != 'no_match_score'.
 *          pending_matches   = COUNT(DISTINCT match_id) WHERE method = 'no_match_score'.
 */
class EvaluationController {

    private const NAMESPACE = 'entre-redes/v1';

    private FechaEvaluator $evaluator;
    /** @var callable */
    private $capabilityCheck;

    /**
     * @param FechaEvaluator $evaluator       Injected evaluator (shared with cron).
     * @param callable|null  $capabilityCheck Defaults to current_user_can('manage_options').
     *                                        Injected for testability (ADR-G3-4).
     */
    public function __construct(
        FechaEvaluator $evaluator,
        ?callable $capabilityCheck = null
    ) {
        $this->evaluator       = $evaluator;
        $this->capabilityCheck = $capabilityCheck ?? static fn() => current_user_can( 'manage_options' );
    }

    /**
     * Register the POST /prode/evaluar-fecha route.
     */
    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE,
            '/prode/evaluar-fecha',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handleEvaluate' ],
                'permission_callback' => [ $this, 'checkPermission' ],
            ]
        );
    }

    /**
     * Permission callback — called by WP REST before the handler.
     *
     * Returns WP_Error (401) when the capability check fails, so WP REST
     * wraps it in the standard { code, message, data: { status: 401 } } envelope.
     *
     * @return true|\WP_Error
     */
    public function checkPermission(): bool|\WP_Error {
        if ( ! ( $this->capabilityCheck )() ) {
            return new \WP_Error(
                'unauthorized',
                'You do not have permission to perform this action.',
                [ 'status' => 401 ]
            );
        }
        return true;
    }

    /**
     * POST /prode/evaluar-fecha
     *
     * Validates fecha_id, checks fecha state, runs FechaEvaluator, and returns
     * a summary of the evaluation pass.
     *
     * NOTE: handleEvaluate is called DIRECTLY from tests (bypassing WP REST's
     * permission_callback gate). Tests that need to test auth failure call
     * handleEvaluate directly and expect it to recheck capability internally.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handleEvaluate( \WP_REST_Request $request ): \WP_REST_Response {
        // Auth check — repeated here so tests calling handleEvaluate directly
        // also hit the gate. In production the permission_callback fires first,
        // but this double-check costs nothing and ensures correctness.
        if ( ! ( $this->capabilityCheck )() ) {
            return new \WP_REST_Response(
                [
                    'code'    => 'unauthorized',
                    'message' => 'You do not have permission to perform this action.',
                    'data'    => [ 'status' => 401 ],
                ],
                401
            );
        }

        // 1. Validate fecha_id present and numeric.
        $rawFechaId = $request->get_param( 'fecha_id' );
        if ( null === $rawFechaId ) {
            return $this->error400( 'missing_fecha_id', "Required field 'fecha_id' is missing." );
        }

        $fechaId = (int) $rawFechaId;

        // 2. Load fecha row and check it exists.
        global $wpdb;
        $fecha = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}prode_fechas WHERE id = %d LIMIT 1",
                $fechaId
            ),
            ARRAY_A
        );

        if ( empty( $fecha ) ) {
            return $this->error400( 'fecha_not_found', "Fecha with id={$fechaId} not found." );
        }

        // 3. Enforce locked state (R7.5, spec A3).
        if ( 'locked' !== $fecha['state'] ) {
            return $this->error400(
                'fecha_not_locked',
                "Fecha {$fechaId} is not in 'locked' state (current: {$fecha['state']})."
            );
        }

        // 4. Run evaluation.
        $this->evaluator->evaluateFecha( $fechaId );

        // 5. Build response counts (spec A2: distinct match_id counts).
        $evaluatedMatches = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT match_id)
                   FROM {$wpdb->prefix}prode_scores
                  WHERE fecha_id = %d
                    AND evaluation_method IN ('exact_score','result_only','no_prediction')",
                $fechaId
            )
        );

        $pendingMatches = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT match_id)
                   FROM {$wpdb->prefix}prode_scores
                  WHERE fecha_id = %d
                    AND evaluation_method = 'no_match_score'",
                $fechaId
            )
        );

        // Re-read fecha state after evaluation (may have flipped to 'evaluated').
        $newState = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT state FROM {$wpdb->prefix}prode_fechas WHERE id = %d",
                $fechaId
            )
        );

        return new \WP_REST_Response(
            [
                'status'            => 'ok',
                'evaluated_matches' => $evaluatedMatches,
                'pending_matches'   => $pendingMatches,
                'fecha_state'       => $newState,
            ],
            200
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Build a 400 error response using the plugin's established envelope shape.
     * Mirrors PredictionController::error400.
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

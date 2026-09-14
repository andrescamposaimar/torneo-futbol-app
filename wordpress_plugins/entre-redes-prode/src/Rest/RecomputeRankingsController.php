<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Rest;

/**
 * REST controller for POST /prode/recompute-rankings.
 *
 * Problem (ADR-G8-1): `prode_ranking_fecha_cache` is only ever rebuilt as a
 * side effect of FechaEvaluator::evaluateFecha(), which fires
 * 'prode_recompute_rankings_cron' at the end. Until this controller existed
 * there was NO operator-facing way to force that rebuild on its own — no REST
 * route, and the only WP-CLI command is `wp prode seed-fecha`, which is
 * useless on the production cPanel host anyway (it has no WP-CLI). The only
 * workaround was dropping a temporary mu-plugin that fired the cron action by
 * hand. This endpoint replaces that workaround permanently.
 *
 * Scope is deliberately global, not per-fecha (ADR-G8-1): the handler simply
 * fires 'prode_recompute_rankings_cron', which RankingCron::run() answers by
 * recomputing EVERY evaluated fecha of the tenant. That is exactly what an
 * operator reaching for "force a rankings rebuild" wants — "rebuild
 * everything, just in case" — and it is exactly what the cron already does,
 * so no new aggregation path is introduced. A `fecha_id` parameter to scope
 * the rebuild to one fecha was deliberately left OUT of scope: it would need
 * its own gate/validation (see EvaluationController's fecha_id handling) and
 * no caller has asked for it yet.
 *
 * Synchronous and potentially slow (ADR-G8-1): RankingCron::run() recomputes
 * every evaluated fecha from a full `SUM(points)` aggregation each time — it
 * is NOT incremental. On a tenant with many evaluated fechas this REST call
 * can take a few seconds. That is acceptable here because this route is only
 * ever operator-triggered on demand (never on a request path a player waits
 * on), unlike the scheduled cron which runs unattended.
 *
 * Auth gate (ADR-G3-4, mirrors EvaluationController):
 *   Capability check is an INJECTED callable defaulting to
 *   current_user_can('manage_options'). Injection is mandatory because the
 *   test shim has no current_user_can() / wp_set_current_user().
 *
 * Response shapes:
 *   200  { status: "ok", fechas_processed: int, skipped_unscored: int,
 *          skipped_empty: int, computed_at: string }
 *   401  { code, message, data: { status: 401 } } — capability check failed
 */
class RecomputeRankingsController {

    private const NAMESPACE = 'entre-redes/v1';

    /** @var callable */
    private $capabilityCheck;

    /**
     * @param callable|null $capabilityCheck Defaults to current_user_can('manage_options').
     *                                       Injected for testability (ADR-G3-4).
     */
    public function __construct( ?callable $capabilityCheck = null ) {
        $this->capabilityCheck = $capabilityCheck ?? static fn() => current_user_can( 'manage_options' );
    }

    /**
     * Register the POST /prode/recompute-rankings route.
     */
    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE,
            '/prode/recompute-rankings',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handleRecompute' ],
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
     * POST /prode/recompute-rankings
     *
     * Takes no parameters. Forces a full rebuild of every evaluated fecha's
     * ranking cache for the active tenant by firing
     * 'prode_recompute_rankings_cron' — the same action RankingCron is bound
     * to in Plugin::boot(), so this call runs the identical code path the
     * scheduled cron does, just on demand and synchronously.
     *
     * NOTE: handleRecompute is called DIRECTLY from tests (bypassing WP REST's
     * permission_callback gate), mirroring EvaluationController — the auth
     * check is repeated here so tests calling handleRecompute directly also
     * hit the gate.
     *
     * Counter capture: a temporary listener is registered on
     * 'prode_ranking_cron_ran' (the observability hook RankingCron::run()
     * fires with $processed, $skippedUnscored, $skippedEmpty — ADR-G8-1)
     * BEFORE firing the cron action, so it captures the counts from this
     * exact invocation. It is removed again immediately after via
     * remove_action() with the same callable/priority, so a second call
     * within the same request (or the same long-running PHP process) never
     * stacks listeners — a stacked listener would silently double-count.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handleRecompute( \WP_REST_Request $request ): \WP_REST_Response {
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

        $processed       = 0;
        $skippedUnscored = 0;
        $skippedEmpty    = 0;

        $captureCounters = static function ( int $p, int $su, int $se ) use ( &$processed, &$skippedUnscored, &$skippedEmpty ): void {
            $processed       = $p;
            $skippedUnscored = $su;
            $skippedEmpty    = $se;
        };

        add_action( 'prode_ranking_cron_ran', $captureCounters, 10, 3 );

        try {
            do_action( 'prode_recompute_rankings_cron' );
        } finally {
            // Unhook unconditionally. If any listener on the action throws, an
            // abandoned listener would survive in a process that keeps running
            // (WP-CLI, a test suite, a long-lived worker) and silently
            // double-count on the next call.
            remove_action( 'prode_ranking_cron_ran', $captureCounters, 10 );
        }

        return new \WP_REST_Response(
            [
                'status'           => 'ok',
                'fechas_processed' => $processed,
                'skipped_unscored' => $skippedUnscored,
                'skipped_empty'    => $skippedEmpty,
                'computed_at'      => current_time( 'mysql' ),
            ],
            200
        );
    }
}

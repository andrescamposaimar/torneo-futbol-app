<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Cron;

use EntreRedes\Prode\Fecha\BackfillMatchMetaService;

/**
 * Daily cron handler that backfills team-meta snapshots onto fecha-match rows
 * that still have none (see BackfillMatchMetaService).
 *
 * Why daily (not one-time): a fecha seeded before v0.5.2 keeps an empty snapshot
 * and is only listed by /partidos once it has been PLAYED. A single run on
 * upgrade would fill already-played fechas (e.g. Fecha 1) but miss a not-yet-
 * played one (e.g. Fecha 2), which would then break the same way once it plays.
 * A daily, idempotent pass fills each row the day after its match appears in
 * /partidos, and is a cheap no-op once every row is snapshotted.
 *
 * Runs on a normal request (where rest_do_request is fully available, unlike the
 * activation hook). Design mirrors FechaCreationCron (ADR-G0-7): the WP hook
 * binds the frozen static run() entrypoint; all logic lives in execute().
 */
class BackfillMatchMetaCron {

    /** WP-cron hook name. */
    public const HOOK = 'prode_backfill_match_meta_daily';

    /**
     * WP hook entrypoint — keep this signature unchanged.
     */
    public static function run(): void {
        global $wpdb;

        $service  = new BackfillMatchMetaService( $wpdb, self::defaultDispatcher() );
        ( new self() )->execute( $service );
    }

    /**
     * Core logic — collaborator injected for testability.
     *
     * @return int Number of rows backfilled.
     */
    public function execute( BackfillMatchMetaService $service ): int {
        $updated = $service->run();
        do_action( 'prode_backfill_match_meta_ran', $updated );
        return $updated;
    }

    /**
     * Production dispatcher: GET /entre-redes/v1/partidos?fecha={date}.
     *
     * /partidos lists PLAYED matches (with full team meta + escudos), which is
     * exactly the data missing for already-locked fechas.
     *
     * @return callable(string):array<int, array<string, mixed>>
     */
    private static function defaultDispatcher(): callable {
        return static function ( string $date ): array {
            $request = new \WP_REST_Request( 'GET', '/entre-redes/v1/partidos' );
            $request->set_param( 'fecha', $date );
            $request->set_param( 'per_page', 200 );

            $response = rest_do_request( $request );
            if ( is_wp_error( $response ) ) {
                return [];
            }

            $body = $response->get_data();
            if ( ! is_array( $body ) ) {
                return [];
            }

            // Endpoint may wrap rows in { total, items: [...] } or return a bare list.
            if ( isset( $body['items'] ) && is_array( $body['items'] ) ) {
                return array_values( $body['items'] );
            }

            return $body;
        };
    }
}

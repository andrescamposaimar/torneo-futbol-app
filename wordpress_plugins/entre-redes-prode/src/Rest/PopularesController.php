<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Rest;

use EntreRedes\Prode\Predictions\PredictionRepository;

/**
 * REST controller for GET /prode/populares.
 *
 * Serves the distribution of predictions for one match so the match detail
 * screen can show what people bet, without knowing which fecha holds it.
 * FechaController exposes the same numbers keyed by fecha; this reads by match.
 *
 * Auth: public. The payload is aggregate and anonymous — no user is named and
 * no per-user row is reachable through it.
 *
 * Params:
 *   match_id (required int) — the sp_event id predictions were stored against.
 *                             Missing or < 1 → 400.
 *
 * Gate (mirrors FechaController): while any fecha holding the match is still
 * open, `populares` is null. Publishing the split before predictions close
 * would let a late voter copy the crowd, which is exactly what the fecha lock
 * exists to prevent. `total` is withheld as well — a bare count still leaks
 * how much traffic a fecha is getting mid-round.
 *
 * Envelope: { match_id:int, total:int, populares:{'1':float,'X':float,'2':float}|null }
 *   populares is null both when the round is still open and when nobody
 *   predicted this match; `total` disambiguates the two for the client.
 */
class PopularesController {

    private const NAMESPACE = 'entre-redes/v1';

    public function __construct( private PredictionRepository $repo ) {}

    /**
     * Register the GET /prode/populares route.
     * Called by RestController::register_routes() via the nullable slot pattern.
     */
    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE,
            '/prode/populares',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'getPopulares' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * GET /prode/populares?match_id=123
     */
    public function getPopulares( \WP_REST_Request $request ) {
        $matchId = (int) $request->get_param( 'match_id' );

        if ( $matchId < 1 ) {
            return new \WP_Error(
                'invalid_match_id',
                'match_id es requerido y debe ser un entero positivo.',
                [ 'status' => 400 ]
            );
        }

        $agg = $this->repo->aggregateForMatch( $matchId );

        // Round still open, or nobody predicted this match: no split to show.
        $oculto = $agg['open'] || 0 === $agg['total'];

        return new \WP_REST_Response(
            [
                'match_id'  => $matchId,
                'total'     => $agg['open'] ? 0 : $agg['total'],
                'populares' => $oculto ? null : $agg['populares'],
            ],
            200
        );
    }
}

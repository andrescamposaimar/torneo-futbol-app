<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Rest;

use EntreRedes\Prode\Auth\AuthMiddleware;
use EntreRedes\Prode\Fecha\FechaRepository;
use EntreRedes\Prode\Fecha\FechaResolver;
use EntreRedes\Prode\Fecha\LockComputer;
use EntreRedes\Prode\Fecha\Settings;
use EntreRedes\Prode\Predictions\PredictionRepository;

/**
 * REST controller for multi-fecha navigation endpoints.
 *
 * Routes:
 *   GET /prode/fechas         — lightweight list for the "< Fecha N >" selector.
 *   GET /prode/fecha/{id}     — full single-fecha payload, identical shape to fecha-activa.
 *
 * Season resolution for GET /prode/fechas (when ?season_id is absent):
 *   1. Use the season_id of the currently-active fecha (findActiveFecha).
 *   2. If no active fecha exists, fall back to MAX(season_id) across all fechas.
 *   3. If still no data, return { fechas: [] }.
 *
 * Match shaping uses MatchShaper::shapeAll() — identical contract to FechaController.
 *
 * Auth: optionalAuth (mirrors FechaController — ADR-G0-5).
 */
class FechaListController {

    private const NAMESPACE = 'entre-redes/v1';

    private FechaRepository       $repository;
    private FechaResolver         $resolver;
    private LockComputer          $lockComputer;
    private Settings              $settings;
    private ?AuthMiddleware       $middleware;
    private ?PredictionRepository $predRepo;

    public function __construct(
        FechaRepository $repository,
        FechaResolver $resolver,
        LockComputer $lockComputer,
        Settings $settings,
        ?AuthMiddleware $middleware = null,
        ?PredictionRepository $predRepo = null
    ) {
        $this->repository   = $repository;
        $this->resolver     = $resolver;
        $this->lockComputer = $lockComputer;
        $this->settings     = $settings;
        $this->middleware   = $middleware;
        $this->predRepo     = $predRepo;
    }

    /**
     * Register routes. Called by RestController::register_routes() via nullable slot.
     */
    public function register_routes(): void {
        $permissionCallback = null !== $this->middleware
            ? [ $this->middleware, 'optionalAuth' ]
            : '__return_true';

        register_rest_route(
            self::NAMESPACE,
            '/prode/fechas',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'listFechas' ],
                'permission_callback' => $permissionCallback,
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/prode/fecha/(?P<id>\d+)',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'getFechaById' ],
                'permission_callback' => $permissionCallback,
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    /**
     * GET /prode/fechas[?season_id={id}]
     *
     * Returns a lightweight ordered list of fechas for the resolved season.
     * Each item: { fecha_id, season_id, state, locked_at, match_count }.
     * Ordered by locked_at ASC so the client can infer "Fecha 1", "Fecha 2" by index.
     */
    public function listFechas( \WP_REST_Request $request ): \WP_REST_Response {
        $tenantId = defined( 'PRODE_TENANT_ID' ) ? (string) PRODE_TENANT_ID : '';

        // Resolve target season_id.
        $rawSeasonId = $request->get_param( 'season_id' );

        if ( null !== $rawSeasonId ) {
            // Validate: must be a positive integer string (rejects '', '0', negatives, non-digits).
            if ( ! ctype_digit( (string) $rawSeasonId ) || (int) $rawSeasonId < 1 ) {
                return new \WP_REST_Response( [ 'error' => 'invalid_season_id' ], 400 );
            }
            $seasonId = (int) $rawSeasonId;
        } else {
            // Default: use the active fecha's season.
            $settingsSeasonId = $this->settings->seasonId();
            $activeFecha      = $this->repository->findActiveFecha( $tenantId, $settingsSeasonId );

            if ( null !== $activeFecha ) {
                $seasonId = (int) $activeFecha['fecha']['season_id'];
            } else {
                // Fallback: MAX(season_id) across all fechas for this tenant.
                $maxSeason = $this->repository->findMaxSeasonId( $tenantId );
                if ( null === $maxSeason ) {
                    return new \WP_REST_Response( [ 'fechas' => [] ], 200 );
                }
                $seasonId = $maxSeason;
            }
        }

        $list = $this->repository->listFechasBySeasonId( $tenantId, $seasonId, $this->lockComputer );

        return new \WP_REST_Response( [ 'fechas' => $list ], 200 );
    }

    /**
     * GET /prode/fecha/{id}
     *
     * Returns a full single-fecha payload with enriched matches and user predictions.
     * The shape is IDENTICAL to GET /prode/fecha-activa.
     * Returns HTTP 404 when the fecha id does not exist for this tenant.
     */
    public function getFechaById( \WP_REST_Request $request ): \WP_REST_Response {
        $tenantId = defined( 'PRODE_TENANT_ID' ) ? (string) PRODE_TENANT_ID : '';
        $fechaId  = (int) $request->get_param( 'id' );

        $fechaData = $this->repository->findFechaById( $tenantId, $fechaId );

        if ( null === $fechaData ) {
            return new \WP_REST_Response( [ 'error' => 'fecha_not_found' ], 404 );
        }

        $fecha   = $fechaData['fecha'];
        $matches = $fechaData['matches'];

        // Compute state dynamically — same logic as FechaController.
        $now   = current_time( 'mysql' );
        $state = $this->lockComputer->deriveState(
            (string) $fecha['locked_at'],
            (string) $fecha['state'],
            $now
        );

        // Enrich matches with live team names, zona, escudos from the resolver.
        $enrichedMatches = $this->resolver->enrichMatches( $matches );

        // Compute populares when state is locked/evaluated and predRepo is available.
        // Gate: 'open' state → null (no aggregate data leaked before the fecha locks).
        $popularesByMatch = null;
        if ( 'open' !== $state && null !== $this->predRepo ) {
            $popularesByMatch = $this->predRepo->aggregatePopulares( (int) $fecha['id'] );
        }

        // Shape matches to the public contract (shared with FechaController via MatchShaper).
        $matchesResponse = MatchShaper::shapeAll( $enrichedMatches, $popularesByMatch );

        // Populate user_predictions when authenticated (mirrors FechaController).
        $userPredictions = [];
        $prodeUser       = $request->get_param( '_prode_user' );

        if ( null !== $prodeUser && null !== $this->predRepo ) {
            $userId          = (int) ( $prodeUser['id'] ?? 0 );
            $rawPredictions  = $this->predRepo->findByUserAndFecha( (int) $fecha['id'], $userId );
            $userPredictions = array_map( static function ( array $row ): array {
                return [
                    'match_id'          => (int) $row['match_id'],
                    'score_home'        => (int) $row['score_home'],
                    'score_away'        => (int) $row['score_away'],
                    'points'            => isset( $row['points'] ) ? (int) $row['points'] : null,
                    'evaluation_method' => isset( $row['evaluation_method'] ) ? (string) $row['evaluation_method'] : null,
                ];
            }, $rawPredictions );
        }

        return new \WP_REST_Response(
            [
                'fecha_id'         => (int) $fecha['id'],
                'season_id'        => (int) $fecha['season_id'],
                'state'            => $state,
                'locked_at'        => (string) $fecha['locked_at'],
                'matches'          => $matchesResponse,
                'user_predictions' => $userPredictions,
            ],
            200
        );
    }
}

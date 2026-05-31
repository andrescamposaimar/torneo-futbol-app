<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Rest;

use EntreRedes\Prode\Auth\AuthMiddleware;
use EntreRedes\Prode\Fecha\FechaRepository;
use EntreRedes\Prode\Fecha\FechaResolver;
use EntreRedes\Prode\Fecha\LockComputer;
use EntreRedes\Prode\Fecha\Settings;

/**
 * REST controller for the GET /prode/fecha-activa endpoint.
 *
 * Response contract:
 * {
 *   fecha_id:       int,
 *   season_id:      int,
 *   state:          "open"|"locked",
 *   locked_at:      "Y-m-d H:i:s",
 *   matches:        [{match_id, home_team, away_team, kickoff}],
 *   user_predictions: []   // populated in G2
 * }
 *
 * Team names are enriched at read time via FechaResolver::enrichMatches()
 * (ADR-G0-2 / ADR-P008 — not stored in DB).
 *
 * State is computed via LockComputer::deriveState() using real current_time()
 * rather than the stored state column value (which stays 'open' until G3
 * writes 'evaluated'). ADR-G0-5: optionalAuth permission callback so the
 * route serves anonymous reads now and attaches user context in G2.
 */
class FechaController {

    private const NAMESPACE = 'entre-redes/v1';

    private FechaRepository  $repository;
    private FechaResolver    $resolver;
    private LockComputer     $lockComputer;
    private Settings         $settings;
    private ?AuthMiddleware  $middleware;

    public function __construct(
        FechaRepository $repository,
        FechaResolver $resolver,
        LockComputer $lockComputer,
        Settings $settings,
        ?AuthMiddleware $middleware = null
    ) {
        $this->repository   = $repository;
        $this->resolver     = $resolver;
        $this->lockComputer = $lockComputer;
        $this->settings     = $settings;
        $this->middleware   = $middleware;
    }

    /**
     * Register the route. Called by RestController::register_routes() via the
     * nullable $fecha_controller seam (null-guard pattern).
     */
    public function register_routes(): void {
        // Use optionalAuth when middleware is available (ADR-G0-5).
        // Falls back to __return_true for backward compatibility and test isolation.
        $permissionCallback = null !== $this->middleware
            ? [ $this->middleware, 'optionalAuth' ]
            : '__return_true';

        register_rest_route(
            self::NAMESPACE,
            '/prode/fecha-activa',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'getActiveFecha' ],
                'permission_callback' => $permissionCallback,
            ]
        );
    }

    /**
     * GET /prode/fecha-activa
     *
     * Returns the active (open/locked) fecha with enriched match data.
     * Returns HTTP 404 when no active fecha exists.
     */
    public function getActiveFecha( \WP_REST_Request $request ): \WP_REST_Response {
        $tenantId = defined( 'PRODE_TENANT_ID' ) ? (string) PRODE_TENANT_ID : '';
        $seasonId = $this->settings->seasonId();

        $activeFecha = $this->repository->findActiveFecha( $tenantId, $seasonId );

        if ( null === $activeFecha ) {
            return new \WP_REST_Response( [ 'error' => 'no_active_fecha' ], 404 );
        }

        $fecha   = $activeFecha['fecha'];
        $matches = $activeFecha['matches'];

        // Compute state dynamically — do not trust the stored column for open/locked.
        $now   = current_time( 'mysql' );
        $state = $this->lockComputer->deriveState(
            (string) $fecha['locked_at'],
            (string) $fecha['state'],
            $now
        );

        // Enrich match rows with live team names from the resolver.
        $enrichedMatches = $this->resolver->enrichMatches( $matches );

        // Shape the match array to the public contract.
        $matchesResponse = array_map( static function ( array $m ): array {
            return [
                'match_id'  => (int) ( $m['match_id'] ?? 0 ),
                'home_team' => (string) ( $m['home_team'] ?? '' ),
                'away_team' => (string) ( $m['away_team'] ?? '' ),
                'kickoff'   => (string) ( $m['match_kickoff'] ?? '' ),
            ];
        }, $enrichedMatches );

        return new \WP_REST_Response(
            [
                'fecha_id'         => (int) $fecha['id'],
                'season_id'        => (int) $fecha['season_id'],
                'state'            => $state,
                'locked_at'        => (string) $fecha['locked_at'],
                'matches'          => $matchesResponse,
                'user_predictions' => [], // G2 fills this
            ],
            200
        );
    }
}

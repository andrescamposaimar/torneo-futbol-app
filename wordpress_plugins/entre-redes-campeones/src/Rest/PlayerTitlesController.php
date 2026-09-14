<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Rest;

use EntreRedes\Campeones\Titles\SquadRepository;

/**
 * GET /entre-redes/v1/campeones/jugador/{id}/titulos — API-2, API-4.
 *
 * Public, unauthenticated (permission_callback __return_true), same caching
 * posture as HistoryController: no data is caller-specific, so a shared
 * HTTP/CDN cache is fine and no no-store filter is applied.
 */
final class PlayerTitlesController {

    private const CACHE_PREFIX = 'campeones_titulos_jugador_v1_';
    private const CACHE_TTL    = 30 * DAY_IN_SECONDS;

    public function __construct( private readonly SquadRepository $squads ) {
    }

    public function register_routes(): void {
        register_rest_route(
            'entre-redes/v1',
            '/campeones/jugador/(?P<id>\d+)/titulos',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'handle' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function handle( \WP_REST_Request $request ): \WP_REST_Response {
        $jugadorId = (int) $request->get_param( 'id' );
        $cacheKey  = self::CACHE_PREFIX . $jugadorId;

        $cached = get_transient( $cacheKey );
        if ( false !== $cached ) {
            return new \WP_REST_Response( $cached, 200 );
        }

        $rows    = $this->squads->findTitleSummariesByJugadorId( $jugadorId );
        $titulos = array_map( [ TitleShaper::class, 'shapePlayerTitulo' ], $rows );

        $payload = [
            'jugador_id' => $jugadorId,
            // API-4: a player with zero linked titles produces this same
            // shape with total = 0 and titulos = [] — HTTP 200, never a 404
            // or an error. There is no existence check against the player
            // directory here; any id, real or not, simply yields whatever
            // rows reference it.
            'total'      => count( $titulos ),
            'titulos'    => $titulos,
        ];

        set_transient( $cacheKey, $payload, self::CACHE_TTL );

        return new \WP_REST_Response( $payload, 200 );
    }
}

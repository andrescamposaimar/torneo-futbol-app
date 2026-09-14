<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Rest;

use EntreRedes\Campeones\Linking\PlayerDirectoryInterface;
use EntreRedes\Campeones\Linking\PlayerDirectoryQueryException;
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

    public function __construct(
        private readonly SquadRepository $squads,
        private readonly PlayerDirectoryInterface $directory
    ) {
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
        if ( self::isValidCachedPayload( $cached ) ) {
            return new \WP_REST_Response( $cached, 200 );
        }

        // Item 1 (CRITICAL): an id with no matching registered player must
        // never get a transient written for it — an anonymous caller
        // walking every integer would otherwise grow wp_options without
        // bound (two rows per miss, 30-day TTL). A directory outage must
        // not turn this public, unauthenticated endpoint into a fatal error
        // either (the same reasoning as
        // TitleEditorPage::resolvePlayerNames()) — treat "cannot confirm"
        // the same as "does not exist" for caching purposes: the response
        // is still served correctly, it is simply not cached.
        try {
            $playerExists = $this->directory->existsById( $jugadorId );
        } catch ( PlayerDirectoryQueryException $e ) {
            error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                'entre-redes-campeones: could not confirm jugador_id=%d against the player directory before caching campeones/jugador/titulos — the player directory is unavailable. %s',
                $jugadorId,
                $e->getMessage()
            ) );
            $playerExists = false;
        }

        $rows    = $playerExists ? $this->squads->findTitleSummariesByJugadorId( $jugadorId ) : [];
        $titulos = array_map( [ TitleShaper::class, 'shapePlayerTitulo' ], $rows );

        $payload = [
            'jugador_id' => $jugadorId,
            // API-4: a player with zero linked titles produces this same
            // shape with total = 0 and titulos = [] — HTTP 200, never a 404
            // or an error.
            'total'      => count( $titulos ),
            'titulos'    => $titulos,
        ];

        // Item 3 (CRITICAL): set_transient() returning false went unchecked
        // here too — same reasoning as HistoryController's fix above. Only
        // logged when a write was actually attempted (a skipped write for a
        // non-existent player is a deliberate no-op, not a failure).
        if ( $playerExists && ! set_transient( $cacheKey, $payload, self::CACHE_TTL ) ) {
            error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                'entre-redes-campeones: failed to write the %s cache transient for jugador_id=%d — every request for this player will rebuild until this succeeds. The response served here is still correct.',
                self::CACHE_PREFIX,
                $jugadorId
            ) );
        }

        return new \WP_REST_Response( $payload, 200 );
    }

    /**
     * Guards against a corrupt or old-shape cached value that still
     * unserialises to something array-like being served verbatim — a
     * mismatch is treated exactly like a cache miss: rebuild from the DB.
     */
    private static function isValidCachedPayload( mixed $value ): bool {
        return is_array( $value )
            && array_key_exists( 'jugador_id', $value )
            && array_key_exists( 'total', $value )
            && array_key_exists( 'titulos', $value )
            && is_array( $value['titulos'] );
    }
}

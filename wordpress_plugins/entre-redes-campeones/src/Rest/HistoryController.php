<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Rest;

use EntreRedes\Campeones\Titles\SquadRepository;
use EntreRedes\Campeones\Titles\TitleRepository;

/**
 * GET /entre-redes/v1/campeones/historia — API-1, API-3.
 *
 * Public, unauthenticated, byte-identical for every caller (design §7):
 * permission_callback is __return_true and the response is deliberately NOT
 * marked no-store the way entre-redes-prode's Plugin::denyProdeResponseCaching
 * marks every /prode/ response — that filter exists because prode payloads
 * are per-user. This payload contains no user data, so a shared HTTP/CDN
 * cache is exactly the right tool here, not a bug to guard against.
 */
final class HistoryController {

    private const CACHE_KEY = 'campeones_historia_v2';
    private const CACHE_TTL = 30 * DAY_IN_SECONDS; // matches cachear_respuesta_rest()'s 30-day TTL for entre-redes-api's other once-a-year endpoints

    public function __construct(
        private readonly TitleRepository $titles,
        private readonly SquadRepository $squads,
        private readonly PlayerPhotoProviderInterface $photos
    ) {
    }

    public function register_routes(): void {
        register_rest_route(
            'entre-redes/v1',
            '/campeones/historia',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'handle' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function handle( \WP_REST_Request $request ): \WP_REST_Response {
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached ) {
            return new \WP_REST_Response( $cached, 200 );
        }

        $titles = $this->titles->findAll(); // already ordered anio DESC, zona ASC, posicion ASC

        $squadByTituloId = [];
        $linkedIds       = [];
        foreach ( $titles as $title ) {
            $squad                          = $this->squads->findByTitle( $title->id );
            $squadByTituloId[ $title->id ]  = $squad;
            foreach ( $squad as $entry ) {
                if ( null !== $entry->jugadorId ) {
                    $linkedIds[] = $entry->jugadorId;
                }
            }
        }

        // One batched call for the whole response — never one per year, and
        // never one per squad entry (design §7 / ADR-C2).
        $photoUrlsById = $this->photos->findPhotoUrls( array_values( array_unique( $linkedIds ) ) );

        $payload = [
            'titulos' => array_map(
                static fn ( $title ) => TitleShaper::shapeHistoryEntry( $title, $squadByTituloId[ $title->id ], $photoUrlsById ),
                $titles
            ),
        ];

        // Item 3 (CRITICAL): set_transient() returns false on failure
        // (payload too large for max_allowed_packet, a rejected write, an
        // object-cache drop-in) and that was never checked. The response is
        // still correct either way (built from the DB, not from the
        // transient) — but a failed write must be logged, distinguishing it
        // from a plain cache miss, or the cache silently never populates and
        // every request re-runs the full rebuild indefinitely.
        if ( ! set_transient( self::CACHE_KEY, $payload, self::CACHE_TTL ) ) {
            error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                'entre-redes-campeones: failed to write the %s cache transient — every request will rebuild the full history until this succeeds. The response served here is still correct.',
                self::CACHE_KEY
            ) );
        }

        return new \WP_REST_Response( $payload, 200 );
    }
}

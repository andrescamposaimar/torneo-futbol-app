<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Rest;

use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\CambiosVigentesReader;

/**
 * REST controller for the public "Cambios activos" screen:
 *
 *   GET /entre-redes/v1/cambios/vigentes
 *
 * Every live substitution of EVERY team of the season — who is provisionally
 * on each team (`alta`), who left the plaza (`baja`), since which fecha, and
 * how many fechas remain before the titular may come back. The data comes
 * from `Plazas\CambiosVigentesReader`, the same reader the process owner's
 * admin table uses, so both always say the same thing.
 *
 * *** PUBLIC AND READ-ONLY, BY PRODUCT DECISION ***
 * The tournament publishes this list to everyone (players, parents, anyone
 * with the app) for transparency: no session and no captain check. It only
 * reads, and everything it returns is already public elsewhere — names in
 * the rosters, puntaje through the players API. Anything that WRITES, or that
 * is scoped to one team's requests, stays behind the captain authorization
 * of the other controllers; do not copy this permission_callback there.
 *
 * *** ALWAYS THE CURRENT SEASON — THE CLIENT DOES NOT CHOOSE IT ***
 * The season is `Calendario\Settings::seasonId()`, the one the process owner
 * configures, never a request parameter: the list must never show an old
 * season, and "current season" has exactly one definition (the same one
 * `GET /cambios/mis-equipos` hands the captain's app). A `season_id` sent by
 * a client is ignored.
 *
 * Caching: the route lives under `/cambios/`, so `Plugin::denyCambiosResponse
 * Caching()` marks it `no-store` like the rest. Not needed for a public read,
 * but harmless, and it keeps the list fresh right after a lote is published.
 */
final class VigentesController {

    use HandlesCapitanAuthorization;

    private Settings $settings;
    private CambiosVigentesReader $reader;
    private EventLog $eventLog;

    public function __construct( Settings $settings, CambiosVigentesReader $reader, EventLog $eventLog ) {
        $this->settings = $settings;
        $this->reader   = $reader;
        $this->eventLog = $eventLog;
    }

    public function register_routes(): void {
        register_rest_route(
            RestController::API_NAMESPACE,
            '/' . RestController::BASE . '/vigentes',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'listar' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function listar( \WP_REST_Request $request ): \WP_REST_Response {
        $seasonId = 0;

        try {
            $seasonId = $this->settings->seasonId();

            if ( $seasonId <= 0 ) {
                throw new \RuntimeException( 'season_id configurado invalido: ' . $seasonId );
            }

            return new \WP_REST_Response(
                [
                    'season_id' => $seasonId,
                    'vigentes'  => $this->reader->listarParaCapitanes( $seasonId ),
                ],
                200
            );
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'rest.vigentes_fallida', [
                'season_id' => $seasonId,
                'excepcion' => get_class( $e ),
                'mensaje'   => $e->getMessage(),
            ] );

            return $this->respuestaErrorInterno();
        }
    }
}

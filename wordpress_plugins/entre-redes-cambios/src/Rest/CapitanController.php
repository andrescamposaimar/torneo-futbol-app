<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Rest;

use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Capitania\CapitanAuthorizer;
use EntreRedes\Cambios\Capitania\CapitanRepository;
use EntreRedes\Cambios\Capitania\Exception\AuthorizationDeniedException;
use EntreRedes\Cambios\Observability\EventLog;

/**
 * REST controller for the CAPTAIN-facing bootstrap endpoint:
 *
 *   GET /entre-redes/v1/cambios/mis-equipos — the ONE thing every other
 *       captain endpoint in this plugin (Rest\PlazasController,
 *       Rest\SolicitudesController) assumes the client already knows:
 *       WHICH `season_id`/`team_id` pair to send. Every other route
 *       requires both as query params with no way to learn them — this is
 *       that way.
 *
 * *** WHY THIS CANNOT USE authorizeCapitan() / CapitanAuthorizer::authorize()
 * *** Both require a `$teamId` up front to check captaincy against — the
 * exact thing this endpoint exists to hand the client BEFORE it has one.
 * This controller instead calls CapitanAuthorizer::verifyIdentity() (see
 * that method's own docblock for the "this does not authorize anything"
 * warning) to establish WHO is asking, then answers "what do you captain?"
 * for that person and nobody else — the caller only ever learns about
 * themselves, so there is nothing to withhold, and an empty `teams` array
 * is a legitimate 200, not a 403 (see listar()'s own docblock).
 */
final class CapitanController {

    use HandlesCapitanAuthorization;

    private CapitanAuthorizer $authorizer;
    private CapitanRepository $capitanRepository;
    private Settings $settings;
    private EventLog $eventLog;

    /** @var callable(): int */
    private $clockFn;

    /**
     * @param callable(): int|null $clockFn Returns the current instant as a
     *        Unix epoch, for the identity check — see
     *        Rest\HandlesCapitanAuthorization::authorizeCapitan()'s own
     *        docblock for why this is injectable rather than a direct
     *        `time()` call. Defaults to the real clock.
     */
    public function __construct(
        CapitanAuthorizer $authorizer,
        CapitanRepository $capitanRepository,
        Settings $settings,
        EventLog $eventLog,
        ?callable $clockFn = null
    ) {
        $this->authorizer        = $authorizer;
        $this->capitanRepository = $capitanRepository;
        $this->settings          = $settings;
        $this->eventLog          = $eventLog;
        $this->clockFn           = $clockFn ?? static fn (): int => time();
    }

    public function register_routes(): void {
        register_rest_route(
            RestController::API_NAMESPACE,
            '/' . RestController::BASE . '/mis-equipos',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'listar' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    /**
     * GET /entre-redes/v1/cambios/mis-equipos
     *
     * Response 200: { season_id, player_id, teams: [ { team_id, nombre }, ... ] }
     *
     * `teams` is `[]`, with a 200, when the token's own player_id currently
     * captains nothing — "authenticated but captains nothing" is a real
     * state the app has to render ("no sos capitán de ningún equipo"), not
     * an authorization failure: the caller asked about themselves and
     * nothing else, so there is nothing here to refuse them.
     *
     * A player captaining MORE than one team is also represented as-is —
     * CapitanRepository::listEquiposByCapitan()'s own docblock is explicit
     * that the schema does not stop this, so this endpoint does not pretend
     * otherwise either.
     */
    public function listar( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $claims = $this->authorizer->verifyIdentity( $this->extractBearerToken( $request ), ( $this->clockFn )() );
        } catch ( AuthorizationDeniedException $e ) {
            $this->eventLog->record( 'rest.autorizacion_denegada', [
                'endpoint'  => 'GET /cambios/mis-equipos',
                'excepcion' => get_class( $e ),
            ] );

            return $this->respuestaNoAutorizada();
        }

        $playerId = (int) ( $claims['player_id'] ?? 0 );

        try {
            $seasonId = $this->settings->seasonId();
            $teamIds  = $this->capitanRepository->listEquiposByCapitan( $seasonId, $playerId );

            $teams = array_map(
                fn ( int $teamId ): array => [
                    'team_id' => $teamId,
                    'nombre'  => $this->nombreEquipo( $teamId ),
                ],
                $teamIds
            );

            return new \WP_REST_Response(
                [
                    'season_id' => $seasonId,
                    'player_id' => $playerId,
                    'teams'     => $teams,
                ],
                200
            );
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'rest.mis_equipos_fallida', [
                'player_id' => $playerId,
                'excepcion' => get_class( $e ),
                'mensaje'   => $e->getMessage(),
            ] );

            return $this->respuestaErrorInterno();
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * @return string The trimmed post title for $teamId, or "Equipo #<id>"
     *         when it is empty — never a blank name, same discipline as
     *         Admin\BandejaPage::nombrePost().
     */
    private function nombreEquipo( int $teamId ): string {
        $titulo = trim( (string) get_the_title( $teamId ) );

        return '' !== $titulo ? $titulo : 'Equipo #' . $teamId;
    }
}

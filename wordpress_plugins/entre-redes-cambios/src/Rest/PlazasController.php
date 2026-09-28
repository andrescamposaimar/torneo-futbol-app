<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Rest;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Capitania\CapitanAuthorizer;
use EntreRedes\Cambios\Capitania\Exception\AuthorizationDeniedException;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\CadenaResolver;
use EntreRedes\Cambios\Plazas\Exception\FechaCountUnavailableException;
use EntreRedes\Cambios\Plazas\PlazaRepository;

/**
 * REST controller for the CAPTAIN-facing plantel status endpoint:
 *
 *   GET /entre-redes/v1/cambios/plazas — every plaza of the captain's team,
 *       who occupies it TODAY, and how many resolved fechas are still
 *       missing before it liberates (via Plazas\CadenaResolver). This is the
 *       screen that keeps a captain from requesting a change that is
 *       impossible from the start (e.g. a regreso against a plaza that has
 *       not liberated yet).
 *
 * *** AUTHORIZATION RUNS FIRST, ALWAYS *** — see
 * Rest\HandlesCapitanAuthorization's own docblock; same discipline as
 * Rest\SolicitudesController.
 *
 * *** THIS IS A READ. A SINGLE PLAZA'S CALCULATION FAILING DOES NOT FAIL
 * THE WHOLE RESPONSE. *** `Plazas\CadenaResolver::countFechasUntilLiberacion()`
 * can throw `FechaCountUnavailableException` for one specific plaza (a
 * broken counter, an unreachable fecha) while every other plaza in the same
 * team is perfectly fine to compute. Letting that one exception bubble up
 * to `listar()`'s generic `\Throwable` catch would turn ONE plaza's problem
 * into a 500 for the captain's ENTIRE roster — eleven plazas hidden because
 * one could not be counted, when a READ carries no risk of authorizing
 * anything: there is nothing to protect by refusing to show the other ten.
 * `shapePlaza()` therefore catches that exception per plaza, logs it, and
 * degrades that ONE row to `fechas_faltantes_liberacion: null` plus an
 * explicit `fechas_faltantes_liberacion_indeterminado: true` marker — never
 * a silently wrong `0`.
 *
 * *** THIS TOLERANCE DOES NOT EXTEND TO WRITES *** — creating a solicitud,
 * or publishing the Friday lote, still fails closed and loud on the exact
 * same exception (see `Dictamen\Reglas\RegresoSoloConMinimoCumplido` and
 * `Plazas\CadenaResolver::isPlazaLiberable()`, both of which answer
 * conservatively rather than guess). Only a READ that cannot change any
 * roster state gets this per-row degradation; nobody should copy this
 * pattern into a write path.
 */
class PlazasController {

    use HandlesCapitanAuthorization;

    private CapitanAuthorizer $authorizer;
    private PlazaRepository $plazaRepository;
    private FechaRepository $fechaRepository;
    private EventLog $eventLog;

    /** @var callable(): int */
    private $clockFn;

    /**
     * @param callable(): int|null $clockFn Returns the current instant as a
     *        Unix epoch, for the authorization check — see
     *        Rest\SolicitudesController's constructor docblock for why this
     *        is injectable rather than a direct `time()` call. Defaults to
     *        the real clock.
     */
    public function __construct(
        CapitanAuthorizer $authorizer,
        PlazaRepository $plazaRepository,
        FechaRepository $fechaRepository,
        EventLog $eventLog,
        ?callable $clockFn = null
    ) {
        $this->authorizer      = $authorizer;
        $this->plazaRepository = $plazaRepository;
        $this->fechaRepository = $fechaRepository;
        $this->eventLog        = $eventLog;
        $this->clockFn         = $clockFn ?? static fn (): int => time();
    }

    public function register_routes(): void {
        register_rest_route(
            RestController::API_NAMESPACE,
            '/' . RestController::BASE . '/plazas',
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
     * GET /entre-redes/v1/cambios/plazas?season_id=..&team_id=..
     *
     * Response 200: { plazas: [ { plaza_id, tipo, titular_player_id,
     *         ocupante_player_id, es_titular_el_ocupante, cerrada,
     *         fechas_faltantes_liberacion,
     *         fechas_faltantes_liberacion_indeterminado }, ... ] }
     *
     * `ocupante_player_id` is null only when the plaza somehow has no vigent
     * ocupación (should not happen once PlazaRepository::openPlaza() has
     * run, but this endpoint does not assume it — see
     * PlazaRepository::findOcupacionVigente()'s own docblock).
     *
     * `fechas_faltantes_liberacion` is null in TWO distinct cases, both
     * still an honest "unknown", never a fabricated 0 — `fechas_faltantes_
     * liberacion_indeterminado` tells them apart:
     *   - `indeterminado: false` — the plaza's own ocupaciones chain could
     *     not be READ (PlazaRepository::listOcupaciones() reads a wpdb-level
     *     failure as "no rows" rather than throwing — see that method's
     *     docblock, "WHY listOcupaciones() ITSELF WAS NOT CHANGED").
     *   - `indeterminado: true` — the chain WAS read, but the liberation
     *     count itself could not be trusted (CadenaResolver's own injected
     *     counter threw `FechaCountUnavailableException`) — see this class's
     *     docblock, "THIS IS A READ", for why that failure degrades only
     *     THIS plaza's row instead of failing the whole response.
     */
    public function listar( \WP_REST_Request $request ): \WP_REST_Response {
        $seasonId = (int) $request->get_param( 'season_id' );
        $teamId   = (int) $request->get_param( 'team_id' );

        if ( $seasonId <= 0 || $teamId <= 0 ) {
            return $this->respuestaSolicitudInvalida(
                'campos_invalidos',
                'season_id y team_id son obligatorios y deben ser mayores a 0.'
            );
        }

        try {
            $this->authorizeCapitan( $this->authorizer, $request, $seasonId, $teamId, ( $this->clockFn )() );
        } catch ( AuthorizationDeniedException $e ) {
            $this->eventLog->record( 'rest.autorizacion_denegada', [
                'endpoint'  => 'GET /cambios/plazas',
                'season_id' => $seasonId,
                'team_id'   => $teamId,
                'excepcion' => get_class( $e ),
            ] );

            return $this->respuestaNoAutorizada();
        }

        try {
            $plazas = $this->plazaRepository->listPlazasByEquipo( $seasonId, $teamId );

            // Bound to THIS season, exactly like
            // DictamenContextAssembler::boundedCountResolvedFechasSinceFn() —
            // see Plazas\CadenaResolver's class docblock for why the
            // callable is injected rather than read from a global clock.
            $countResolvedFechasSinceFn = fn ( int $fechaId ): int =>
                $this->fechaRepository->countResolvedFechasSince( $seasonId, $fechaId );

            $cadenaResolver = new CadenaResolver( $countResolvedFechasSinceFn );

            $resultado = array_map(
                fn ( array $plaza ): array => $this->shapePlaza( $plaza, $cadenaResolver ),
                $plazas
            );

            return new \WP_REST_Response( [ 'plazas' => $resultado ], 200 );
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'rest.plazas_listar_fallida', [
                'season_id' => $seasonId,
                'team_id'   => $teamId,
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
     * @param array<string, mixed> $plaza As returned by
     *        PlazaRepository::listPlazasByEquipo().
     * @return array<string, mixed>
     */
    private function shapePlaza( array $plaza, CadenaResolver $cadenaResolver ): array {
        $plazaId     = (int) $plaza['id'];
        $ocupaciones = $this->plazaRepository->listOcupaciones( $plazaId );
        $vigente     = $this->vigente( $ocupaciones );

        [ $fechasFaltantes, $indeterminado ] = $this->resolveFechasFaltantes( $plazaId, $ocupaciones, $cadenaResolver );

        return [
            'plaza_id'                                   => $plazaId,
            'tipo'                                        => (string) $plaza['tipo'],
            'titular_player_id'                           => (int) $plaza['titular_player_id'],
            'ocupante_player_id'                          => null !== $vigente ? (int) $vigente['player_id'] : null,
            'es_titular_el_ocupante'                      => null !== $vigente
                && (int) $vigente['player_id'] === (int) $plaza['titular_player_id'],
            'cerrada'                                      => null !== $plaza['closed_at'],
            'fechas_faltantes_liberacion'                  => $fechasFaltantes,
            'fechas_faltantes_liberacion_indeterminado'    => $indeterminado,
        ];
    }

    /**
     * The per-plaza degradation this class's docblock describes
     * ("A SINGLE PLAZA'S CALCULATION FAILING DOES NOT FAIL THE WHOLE
     * RESPONSE"). An empty $ocupaciones chain is the pre-existing "no rows
     * read" case (PlazaRepository::listOcupaciones()'s own docblock) — still
     * an honest `null`, not this method's concern to log, since nothing was
     * even attempted. A THROWN FechaCountUnavailableException is different:
     * something WAS attempted and could not be trusted, so it is logged here
     * — once, with the plaza id — before degrading, exactly like every other
     * failure path in this plugin logs before answering conservatively.
     *
     * @param array<int, array<string, mixed>> $ocupaciones
     * @return array{0: int|null, 1: bool} [fechas_faltantes_liberacion,
     *         fechas_faltantes_liberacion_indeterminado]
     */
    private function resolveFechasFaltantes( int $plazaId, array $ocupaciones, CadenaResolver $cadenaResolver ): array {
        if ( empty( $ocupaciones ) ) {
            return [ null, false ];
        }

        try {
            return [ $cadenaResolver->countFechasUntilLiberacion( $ocupaciones ), false ];
        } catch ( FechaCountUnavailableException $e ) {
            $this->eventLog->record( 'rest.plaza_fechas_faltantes_no_calculable', [
                'plaza_id'  => $plazaId,
                'excepcion' => get_class( $e ),
                'mensaje'   => $e->getMessage(),
            ] );

            return [ null, true ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $ocupaciones
     * @return array<string, mixed>|null
     */
    private function vigente( array $ocupaciones ): ?array {
        foreach ( $ocupaciones as $ocupacion ) {
            if ( null === ( $ocupacion['fecha_hasta_id'] ?? null ) ) {
                return $ocupacion;
            }
        }

        return null;
    }
}

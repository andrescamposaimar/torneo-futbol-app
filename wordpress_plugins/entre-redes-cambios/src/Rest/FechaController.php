<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Rest;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\PlazosCalculator;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Capitania\CapitanAuthorizer;
use EntreRedes\Cambios\Capitania\Exception\AuthorizationDeniedException;
use EntreRedes\Cambios\Observability\EventLog;

/**
 * REST controller for the CAPTAIN-facing "which fecha am I requesting for"
 * bootstrap endpoint:
 *
 *   GET /entre-redes/v1/cambios/fecha-abierta?season_id=<int> — the ONE
 *       thing the "Pedir cambio" screen needs before it can let a captain
 *       submit `POST /cambios/solicitudes`, which REQUIRES a `fecha_id` that
 *       no other route exposes (`Calendario\FechaRepository::listBySeason()`
 *       exists and, before this controller, was wired to no route at all).
 *
 * *** WHY THIS USES verifyIdentity(), NOT authorizeCapitan() *** — same
 * reasoning as Rest\CapitanController's own docblock: "which fecha is open
 * right now" is SEASON-WIDE information, not team-scoped — a captain of ANY
 * team may read it, and there is no `team_id` here to authorize against in
 * the first place. Identity alone (a valid, non-revoked Prode session) is
 * enough.
 *
 * *** "THE OPEN FECHA" IS THE EARLIEST UNRESOLVED ONE, REUSING THE SAME
 * NOTION OF "RESOLVED" `Calendario\BoundedFechaCounter` ALREADY USES ***
 * A fecha counts as resolved when its `estado` is `jugada` or `dirimida` —
 * see `BoundedFechaCounter::countTotalResolvedFechas()`'s own private
 * predicate, which this controller mirrors exactly rather than writing a
 * second "what counts as resolved" definition that could drift from it.
 * `FechaRepository::listBySeason()` already returns rows ordered by `orden`
 * ASC, so the first row that is NOT resolved is, by construction, the
 * earliest one — no extra sort needed here.
 *
 * *** AN EMPTY ANSWER IS A NORMAL 200, NEVER A 404 OR AN ERROR *** — "the
 * season is over" or "nothing is scheduled yet" are real states the screen
 * has to render as a sentence (see this slice's task brief), not failures.
 * `{"fecha": null}` is that sentence's data, with the SAME 200 status as a
 * populated answer.
 *
 * *** TWO WINDOWS, COMPUTED INDEPENDENTLY, NEVER ONE DERIVED FROM THE OTHER
 * *** Mirrors `Dictamen\Reglas\SolicitudEnPlazo`'s own "TWO DIFFERENT
 * DEADLINES, ONE WINDOW METHOD" section exactly: `regreso_abierta` is
 * `apertura_solicitudes <= now <= cierre_regresos`; `sustitucion_abierta` is
 * `PlazosCalculator::isWithinSolicitudWindow()`'s own contract
 * (`apertura_solicitudes <= now <= cierre_solicitudes`). `cierre_regresos`
 * falls BEFORE `cierre_solicitudes` (see `PlazosCalculator::PLAZO_KEYS`'s own
 * chronological ordering), so a captain can be past the regreso deadline
 * while still inside the sustitucion one — the screen needs to say so BEFORE
 * a captain picks a candidate, not learn it from a rejected `POST
 * /solicitudes`.
 *
 * *** UTC, NEVER CIVIL, AND NEVER time() DIRECTLY *** — same discipline as
 * every other clock-comparing class in this plugin (see
 * `Rest\HandlesCapitanAuthorization::authorizeCapitan()`'s own docblock for
 * the incident this guards against): `$now` is injected via `$clockFn` as a
 * Unix epoch, converted to a UTC civil string with `gmdate()`, and compared
 * ONLY against `PlazosCalculator::computeUtc()` — never `compute()`'s civil
 * output, which is timezone-invariant by construction and would silently
 * misjudge the comparison by the configured timezone's offset.
 */
class FechaController {

    use HandlesCapitanAuthorization;

    /**
     * The only `cambios_fecha.estado` values that count as resolved — see
     * class docblock. Kept in lockstep with
     * `Calendario\BoundedFechaCounter::countTotalResolvedFechas()`'s own
     * private predicate; if that list ever changes, this one must change
     * with it.
     */
    private const ESTADOS_RESUELTOS = [ 'jugada', 'dirimida' ];

    private CapitanAuthorizer $authorizer;
    private FechaRepository $fechaRepository;
    private Settings $settings;
    private EventLog $eventLog;

    /** @var callable(): int */
    private $clockFn;

    /**
     * @param callable(): int|null $clockFn Returns the current instant as a
     *        Unix epoch — see class docblock, "UTC, NEVER CIVIL, AND NEVER
     *        time() DIRECTLY". Defaults to the real clock.
     */
    public function __construct(
        CapitanAuthorizer $authorizer,
        FechaRepository $fechaRepository,
        Settings $settings,
        EventLog $eventLog,
        ?callable $clockFn = null
    ) {
        $this->authorizer      = $authorizer;
        $this->fechaRepository = $fechaRepository;
        $this->settings        = $settings;
        $this->eventLog        = $eventLog;
        $this->clockFn         = $clockFn ?? static fn (): int => time();
    }

    public function register_routes(): void {
        register_rest_route(
            RestController::API_NAMESPACE,
            '/' . RestController::BASE . '/fecha-abierta',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'fechaAbierta' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    /**
     * GET /entre-redes/v1/cambios/fecha-abierta?season_id=<int>
     *
     * Response 200: { fecha: { fecha_id, numero_en_torneo, torneo, play_date,
     *         plazos_utc: { apertura_solicitudes, cierre_regresos,
     *         cierre_solicitudes, publicacion }, ventanas: { regreso_abierta,
     *         sustitucion_abierta } } } — or { fecha: null } when the season
     *         has no unresolved fecha (see class docblock).
     */
    public function fechaAbierta( \WP_REST_Request $request ): \WP_REST_Response {
        $seasonId = (int) $request->get_param( 'season_id' );

        if ( $seasonId <= 0 ) {
            return $this->respuestaSolicitudInvalida(
                'campos_invalidos',
                'season_id es obligatorio y debe ser mayor a 0.'
            );
        }

        $ahora = ( $this->clockFn )();

        try {
            $this->authorizer->verifyIdentity( $this->extractBearerToken( $request ), $ahora );
        } catch ( AuthorizationDeniedException $e ) {
            $this->eventLog->record( 'rest.autorizacion_denegada', [
                'endpoint'  => 'GET /cambios/fecha-abierta',
                'season_id' => $seasonId,
                'excepcion' => get_class( $e ),
            ] );

            return $this->respuestaNoAutorizada( $e );
        }

        try {
            $fecha = $this->fechaAbiertaDe( $seasonId );

            if ( null === $fecha ) {
                return new \WP_REST_Response( [ 'fecha' => null ], 200 );
            }

            return new \WP_REST_Response( [ 'fecha' => $this->shapeFecha( $fecha, $ahora ) ], 200 );
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'rest.fecha_abierta_fallida', [
                'season_id' => $seasonId,
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
     * The earliest fecha of $seasonId whose `estado` is NOT resolved — see
     * class docblock. `listBySeason()` already orders by `orden` ASC, so the
     * first unresolved row IS the earliest one.
     *
     * @return array<string, mixed>|null
     */
    private function fechaAbiertaDe( int $seasonId ): ?array {
        foreach ( $this->fechaRepository->listBySeason( $seasonId ) as $fecha ) {
            if ( ! in_array( (string) ( $fecha['estado'] ?? '' ), self::ESTADOS_RESUELTOS, true ) ) {
                return $fecha;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fecha As returned by
     *        FechaRepository::listBySeason().
     * @return array<string, mixed>
     */
    private function shapeFecha( array $fecha, int $ahora ): array {
        $playDate = (string) $fecha['play_date'];

        $plazosUtc = PlazosCalculator::computeUtc(
            $playDate,
            $this->settings->plazosOffsets(),
            $this->settings->timezone()
        );

        // The SAME instant as $ahora, in the UTC civil frame plazos_utc is
        // expressed in — see class docblock, "UTC, NEVER CIVIL". Mirrors
        // Dictamen\Reglas\SolicitudEnPlazo's own $nowUtc derivation exactly.
        $nowUtc = gmdate( 'Y-m-d H:i:s', $ahora );

        $regresoAbierta = $nowUtc >= $plazosUtc['apertura_solicitudes']
            && $nowUtc <= $plazosUtc['cierre_regresos'];

        $sustitucionAbierta = PlazosCalculator::isWithinSolicitudWindow( $nowUtc, $plazosUtc );

        return [
            'fecha_id'         => (int) $fecha['id'],
            'numero_en_torneo' => (int) $fecha['numero_en_torneo'],
            'torneo'           => (string) $fecha['torneo_label'],
            'play_date'        => $playDate,
            'plazos_utc'       => $plazosUtc,
            'ventanas'         => [
                'regreso_abierta'     => $regresoAbierta,
                'sustitucion_abierta' => $sustitucionAbierta,
            ],
        ];
    }
}

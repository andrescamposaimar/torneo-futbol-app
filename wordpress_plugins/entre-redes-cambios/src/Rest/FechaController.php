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
 * *** "THE OPEN FECHA" IS THE EARLIEST UNRESOLVED ONE *** "Resolved" has
 * exactly ONE definition in this codebase — `FechaRepository::esResuelta()`,
 * backed by `FechaRepository::ESTADOS_RESUELTOS` — and this controller calls
 * that predicate rather than holding a second copy of the estado list that
 * could drift from it. `FechaRepository::listBySeason()` already returns
 * rows ordered by `orden` ASC, so the first row that is NOT resolved is, by
 * construction, the earliest one — no extra sort needed here.
 *
 * *** AN EMPTY ANSWER IS A NORMAL 200, NEVER A 404 OR AN ERROR *** — "the
 * season is over" or "nothing is scheduled yet" are real states the screen
 * has to render as a sentence (see this slice's task brief), not failures.
 * `{"fecha": null}` is that sentence's data, with the SAME 200 status as a
 * populated answer.
 *
 * *** TWO WINDOWS, COMPUTED INDEPENDENTLY, NEVER ONE DERIVED FROM THE OTHER
 * *** Mirrors `Dictamen\Reglas\SolicitudEnPlazo`'s own "TWO DIFFERENT
 * DEADLINES, ONE WINDOW METHOD" section exactly: the `regreso` window is
 * `apertura_solicitudes <= now <= cierre_regresos`; the `sustitucion` window
 * is `PlazosCalculator::isWithinSolicitudWindow()`'s own contract
 * (`apertura_solicitudes <= now <= cierre_solicitudes`). `cierre_regresos`
 * falls BEFORE `cierre_solicitudes` (see `PlazosCalculator::PLAZO_KEYS`'s own
 * chronological ordering), so a captain can be past the regreso deadline
 * while still inside the sustitucion one — the screen needs to say so BEFORE
 * a captain picks a candidate, not learn it from a rejected `POST
 * /solicitudes`.
 *
 * *** THREE STATES, NOT TWO — WHY `ventanas` IS NO LONGER A BOOLEAN ***
 * Each window used to be shaped as a single boolean (`regreso_abierta`,
 * `sustitucion_abierta`). That collapses two genuinely different facts into
 * one `false`: "the window has not opened yet" (`now < apertura_solicitudes`)
 * and "the window's own deadline already passed" read identically on the
 * wire, even though the first means "come back later, here is when" and the
 * second means "this fecha is done for this request type". A caller cannot
 * tell them apart from the boolean alone, and both are real: on 2026-10-04,
 * fecha #20's `apertura_solicitudes` is 2026-10-11 — the window has not
 * opened, yet the boolean reads exactly like "already closed". `ventanas` is
 * shaped instead as `{ regreso: <fase>, sustitucion: <fase> }`, each `<fase>`
 * one of `'antes'` (before `apertura_solicitudes`), `'abierta'` (within the
 * window), or `'cerrada'` (past the window's own closing deadline) — see
 * `fase()` below, the one three-way comparison both windows share.
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
     * The three wire values a window's phase can take — see class docblock,
     * "THREE STATES, NOT TWO". Lowercase ASCII, no accents, by design: these
     * are wire values the app switches on, not display copy.
     */
    private const FASE_ANTES   = 'antes';
    private const FASE_ABIERTA = 'abierta';
    private const FASE_CERRADA = 'cerrada';

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
     *         cierre_solicitudes, publicacion }, ventanas: { regreso,
     *         sustitucion } } } — each of `ventanas.regreso` /
     *         `ventanas.sustitucion` one of `'antes'` | `'abierta'` |
     *         `'cerrada'` (see class docblock, "THREE STATES, NOT TWO") — or
     *         { fecha: null } when the season has no unresolved fecha (see
     *         class docblock).
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
            if ( ! FechaRepository::esResuelta( (string) ( $fecha['estado'] ?? '' ) ) ) {
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

        return [
            'fecha_id'         => (int) $fecha['id'],
            'numero_en_torneo' => (int) $fecha['numero_en_torneo'],
            'torneo'           => (string) $fecha['torneo_label'],
            'play_date'        => $playDate,
            'plazos_utc'       => $plazosUtc,
            'ventanas'         => [
                // Same bound SolicitudEnPlazo's own 'de regreso' branch uses
                // (apertura_solicitudes..cierre_regresos).
                'regreso'     => self::fase(
                    $nowUtc,
                    $plazosUtc['apertura_solicitudes'],
                    $plazosUtc['cierre_regresos']
                ),
                // apertura_solicitudes..cierre_solicitudes is exactly
                // PlazosCalculator::isWithinSolicitudWindow()'s own contract
                // — the 'abierta' branch below agrees with it by
                // construction, not by re-deriving the same comparison a
                // second way.
                'sustitucion' => self::fase(
                    $nowUtc,
                    $plazosUtc['apertura_solicitudes'],
                    $plazosUtc['cierre_solicitudes']
                ),
            ],
        ];
    }

    /**
     * The one three-way comparison both windows share — see class docblock,
     * "THREE STATES, NOT TWO". Each window's own ($apertura, $cierre) pair
     * stays independent (see class docblock, "TWO WINDOWS, COMPUTED
     * INDEPENDENTLY"); only this comparison itself is shared code.
     *
     * $now, $apertura and $cierre MUST be expressed in the same frame — UTC
     * here, since $nowUtc is derived from `$ahora` via `gmdate()` and
     * compared against `plazos_utc` (see class docblock, "UTC, NEVER CIVIL").
     */
    private static function fase( string $now, string $apertura, string $cierre ): string {
        if ( $now < $apertura ) {
            return self::FASE_ANTES;
        }

        return $now <= $cierre ? self::FASE_ABIERTA : self::FASE_CERRADA;
    }
}

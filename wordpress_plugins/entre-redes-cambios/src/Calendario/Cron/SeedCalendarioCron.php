<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Calendario\Cron;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\LigaResolver;
use EntreRedes\Cambios\Calendario\PartidosApiClient;
use EntreRedes\Cambios\Calendario\SeedTemporadaService;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Observability\WpEventLog;

/**
 * The production caller `Calendario\SeedTemporadaService` never had. Before
 * this class existed, that service's ONLY caller anywhere in this codebase
 * was `tools/dry-run-calendario.php`, whose own header says it runs "NEVER
 * against a real WordPress install" — there was no cron, no activation hook,
 * no command, so `cambios_fecha` stayed empty in production with nothing to
 * fill it, and `Plazas\Alta\TitularesListImporter` (which needs a
 * `fecha_desde_id` from that table) had nothing to read.
 *
 * *** WHY A DAILY CRON, NOT A save_post LISTENER ***
 * The tempting "improvement" here is to reseed the moment a partido is saved
 * in SportsPress. Do NOT do this — this codebase already paid for that exact
 * mistake once: the `wpm2_jugador_partido` sync bound to `save_post_sp_event`,
 * a hook that fires BEFORE the generic `save_post` where SportsPress itself
 * writes a match's own score/date metas, so that listener read a HALF-SAVED
 * match. No hook priority fixes this, because priority only orders callbacks
 * WITHIN one hook — it cannot reorder two DIFFERENT hooks relative to each
 * other. A daily cron that reads the already-published `/entre-redes/v1/*`
 * REST API avoids the race entirely: by the time this runs, every match it
 * sees already went through its own full save cycle. The committee also
 * loads partidos week by week ON PURPOSE (postponing a jornada means editing
 * every one of its unplayed matches' dates — see FechaRepository's own class
 * docblock), so there is no single "the fixture just finished loading"
 * instant to hook even if the race above did not exist. A schedule is the
 * right mechanism here; a save listener is not. Please do not "fix" this
 * back into one.
 *
 * *** THE tiene_resultado LIMITATION CARRIES OVER — SEE PartidosApiClient ***
 * The default fetcher this class builds (`defaultHttpGetFn()` +
 * `PartidosApiClient`) is exactly the client `PartidosApiClient`'s own
 * docblock names as a REFERENCE implementation "for whoever builds the
 * in-WordPress fetcher" — this class is that. Its docblock ALSO says, in so
 * many words, that "the DEFINITIVE production fetcher should NOT be this
 * REST client" — `/partidos` cannot tell a genuine 0-0 apart from a
 * published-but-resultless partido, so every `publish` partido is assumed
 * resolved. This class wires it up anyway, because it is the only fetcher
 * this codebase has that does not depend on a live `sp_results` postmeta
 * integration — NOT because the limitation is fixed. A future slice that
 * replaces it only needs to change `buildDefaultApiClient()`; `execute()`
 * itself is handed a `PartidosApiClient` and does not know or care how it
 * was built.
 *
 * *** THE LOCK — WHY IT EXISTS ***
 * `FechaRepository::recalculateOrden()` writes `orden` in two passes — every
 * moving row is parked in a disjoint high range first, because
 * `UNIQUE(season_id, orden)` collides transiently otherwise (see that
 * method's own docblock). Two seed runs in flight at once could each be
 * mid-pass when the other starts, interleaving into an ordering neither one
 * produced alone. WP-Cron gives no guarantee against that: it can fire the
 * same due event twice from two different requests, or overlap with an
 * operator running `tools/sembrar-calendario.php --apply` by hand. A
 * transient acts as an advisory lock: whoever sets it first proceeds; anyone
 * else sees it already held, records why, and backs off instead of racing.
 * It carries a finite TTL purely as a safety valve — if a run dies without
 * releasing it (a fatal error, an OOM kill), the calendar must not become
 * permanently unseedable.
 *
 * *** WP-CRON IS TRAFFIC-TRIGGERED, NOT A REAL SCHEDULER ***
 * WP-Cron only checks for due events on a normal front-end request. A quiet
 * site with little traffic can run this hours late — expected and tolerable
 * here, since the committee updates the fixture deliberately, at most a
 * handful of times a week. `tools/sembrar-calendario.php` exists for exactly
 * the moment that lag is NOT tolerable: an operator who needs the calendar
 * current right now does not have to wait for a visitor to show up.
 */
final class SeedCalendarioCron {

    /** WP-Cron hook name — bound to self::run() in Plugin::boot(). */
    public const HOOK = 'entre_redes_cambios_seed_calendario_daily';

    /** Transient key for the overlap lock — see class docblock, "THE LOCK". */
    public const LOCK_KEY = 'cambios_seed_calendario_lock';

    /**
     * Safety valve only: a held lock older than this is assumed to belong to
     * a run that died without releasing it, never to a seed that is
     * genuinely still in progress (fetching + upserting 23 fechas takes
     * seconds, not minutes).
     */
    private const LOCK_TTL_SECONDS = 600;

    /** The REST namespace this plugin's own routes already share (see Rest\RestController::API_NAMESPACE) — same install, no network hop. */
    private const REST_BASE_PATH = '/entre-redes/v1';

    /**
     * Registers the daily schedule if none is registered yet. Called from
     * the plugin's activation hook, and as a same-request safety net from
     * Plugin::boot() for the "updated by file overwrite" case, where no
     * activation hook fires — mirrors entre-redes-prode's own
     * `MigrationRunner::scheduleCrons()` safety net.
     */
    public static function schedule(): void {
        if ( false === wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::HOOK );
        }
    }

    /**
     * Clears every scheduled occurrence of this cron. Called from the
     * plugin's deactivation hook — an orphaned cron event that outlives the
     * plugin is a bug that only shows up as mystery load months later, with
     * nothing pointing back at what caused it.
     */
    public static function unschedule(): void {
        wp_clear_scheduled_hook( self::HOOK );
    }

    /**
     * WP hook entrypoint — keep this signature unchanged; Plugin::boot()
     * binds it directly via `add_action(self::HOOK, [self::class, 'run'])`.
     * Builds every real collaborator and delegates to execute() for the
     * actual logic — same split as entre-redes-prode's
     * Cron\BackfillMatchMetaCron. NOT exercised directly by this plugin's
     * test suite (it needs a real WP-Cron/REST runtime); execute() is the
     * tested seam.
     */
    public static function run(): void {
        global $wpdb;

        ( new self() )->execute( $wpdb, new WpEventLog(), new Settings( $wpdb ), self::buildDefaultApiClient() );
    }

    /**
     * Core logic — every collaborator injected, following this codebase's
     * "dependencies injected, never defaulted silently" convention. Safe to
     * call directly from a test against the SQLite shim.
     *
     * @param null|callable(): string $nowFn Overrides the clock fed to
     *        SeedTemporadaService::seed() — defaults to `current_time('mysql')`
     *        (site LOCAL time, matching the civil wall-clock domain of a
     *        partido's `kickoff`, never the UTC domain this plugin's
     *        persisted audit columns use — see README, "Datetime columns are
     *        UTC", which deliberately does not list `play_date`/`kickoff`).
     * @return array<int, array{fecha_id:int, play_date:string, orden:int, numero_en_torneo:int, torneo_label:string, status:string}>|null
     *         null when the run was skipped (lock already held) or failed
     *         (the fetch or the seed threw) — either way, that outcome is
     *         already recorded through $eventLog before this returns, so a
     *         caller never needs to inspect the null to know what happened.
     */
    public function execute(
        \wpdb $wpdb,
        EventLog $eventLog,
        Settings $settings,
        PartidosApiClient $apiClient,
        ?callable $nowFn = null
    ): ?array {
        if ( ! $this->acquireLock() ) {
            $eventLog->record( 'calendario.seed_bloqueado', [
                'motivo' => 'ya hay una corrida de seed en curso (lock activo); esta corrida se salteo',
            ] );
            return null;
        }

        try {
            $seasonId = $settings->seasonId();

            // Mirrors tools/dry-run-calendario.php's fetch sequence exactly
            // — that script's own header calls itself a reference
            // implementation "for whoever builds the in-WordPress fetcher".
            // This is that fetcher. NONE of this writes anything: a network
            // timeout or a malformed payload throws from one of the three
            // calls below, before FechaRepository is even constructed, let
            // alone opens a write — see PartidosApiClient's own docblock for
            // why its $httpGetFn contract (MUST throw, never return [])
            // is what makes that true, rather than merely convenient.
            $ligasIndex        = $apiClient->fetchLigasIndex();
            $ligaToTorneoLabel = LigaResolver::torneoMap( $ligasIndex );
            $partidos          = $apiClient->fetchPartidos( $seasonId, $ligasIndex );
            $programados       = $apiClient->fetchProgramados( $ligasIndex );
            $allPartidos       = array_merge( $partidos, $programados );

            // Any duplicate match_id between the two calls above (the
            // Saturday-night race — see PartidosApiClient::fetchAll()'s own
            // docblock) is resolved downstream by
            // SeedTemporadaService::dedupeByMatchId(), per day group — this
            // fetcher does not need its own pass for the same reason
            // tools/dry-run-calendario.php's does not.
            $fetcherFn = static fn(): array => $allPartidos;

            $repository = new FechaRepository( $wpdb, $eventLog );
            $service    = new SeedTemporadaService( $repository, $fetcherFn, $ligaToTorneoLabel );

            $now = null !== $nowFn ? $nowFn() : current_time( 'mysql' );

            $result = $service->seed( $seasonId, $now );

            $eventLog->record( 'calendario.seed_exitoso', [
                'season_id' => $seasonId,
                'fechas'    => count( $result ),
            ] );

            return $result;
        } catch ( \Throwable $e ) {
            $eventLog->record( 'calendario.seed_fallido', [
                'error' => $e->getMessage(),
            ] );
            return null;
        } finally {
            $this->releaseLock();
        }
    }

    // -------------------------------------------------------------------------
    // Lock — see class docblock, "THE LOCK"
    // -------------------------------------------------------------------------

    private function acquireLock(): bool {
        if ( false !== get_transient( self::LOCK_KEY ) ) {
            return false;
        }

        set_transient( self::LOCK_KEY, time(), self::LOCK_TTL_SECONDS );
        return true;
    }

    private function releaseLock(): void {
        delete_transient( self::LOCK_KEY );
    }

    // -------------------------------------------------------------------------
    // Production fetcher
    // -------------------------------------------------------------------------

    /**
     * The ONE real fetcher this codebase has — shared by `run()` (the
     * WP-Cron path) and `tools/sembrar-calendario.php` (the manual path), so
     * both production callers read the SAME data the SAME way. Public for
     * exactly that reuse; see class docblock, "THE tiene_resultado
     * LIMITATION", for the one thing it does not yet solve.
     */
    public static function buildDefaultApiClient(): PartidosApiClient {
        return new PartidosApiClient( self::defaultHttpGetFn(), self::REST_BASE_PATH );
    }

    /**
     * Wraps `rest_do_request()` — an IN-PROCESS REST dispatch, never a real
     * HTTP round trip — because `/entre-redes/v1/*` is registered on this
     * SAME WordPress install (this plugin's own `/cambios/*` routes share
     * that exact namespace — see `Rest\RestController::API_NAMESPACE`).
     * Mirrors entre-redes-prode's
     * `Cron\BackfillMatchMetaCron::defaultDispatcher()`, the only other
     * production fetcher in this codebase reading from this namespace, and
     * — like that one — is NOT exercised by this plugin's test suite: it
     * needs a real `WP_REST_Server`, not the SQLite shim. Tests inject a
     * `PartidosApiClient` built on a stub `$httpGetFn` instead (see
     * `PartidosApiClientTest`'s own fixtures for that exact pattern).
     *
     * @return callable(string):array<int, mixed>
     */
    private static function defaultHttpGetFn(): callable {
        return static function ( string $url ): array {
            $path  = $url;
            $query = [];

            $questionMarkPos = strpos( $url, '?' );
            if ( false !== $questionMarkPos ) {
                $path = substr( $url, 0, $questionMarkPos );
                parse_str( substr( $url, $questionMarkPos + 1 ), $query );
            }

            $request = new \WP_REST_Request( 'GET', $path );
            foreach ( $query as $key => $value ) {
                $request->set_param( (string) $key, $value );
            }

            $response = rest_do_request( $request );

            if ( is_wp_error( $response ) ) {
                throw new \RuntimeException( "REST request failed for {$path}: " . $response->get_error_message() );
            }

            if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) ) {
                throw new \RuntimeException( "Unexpected REST response shape for {$path}." );
            }

            if ( method_exists( $response, 'get_status' ) && $response->get_status() >= 400 ) {
                throw new \RuntimeException( "REST request for {$path} returned status " . $response->get_status() . '.' );
            }

            $data = $response->get_data();
            if ( ! is_array( $data ) ) {
                throw new \RuntimeException( "REST response for {$path} is not an array." );
            }

            return $data;
        };
    }
}

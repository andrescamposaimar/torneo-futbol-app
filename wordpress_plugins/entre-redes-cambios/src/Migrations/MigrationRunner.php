<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Migrations;

use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\PosicionResolver;

/**
 * Version-aware migration runner.
 *
 * Compares the stored `cambios_db_version` WP option against the current
 * plugin version constant. dbDelta-backed InitialSchema::up() is always safe
 * to re-run (idempotent), so it runs on every activation regardless of the
 * version gate. The version gate exists only to fence off one-time tasks
 * (none in slice 0 — reserved for future migrations that need to run exactly
 * once per upgrade, e.g. a one-off data backfill).
 *
 * *** INNODB CHECK (slice 4) ***
 * Every invariant Plazas\PlazaRepository and Capitania\CapitanRepository
 * defend ("at most one vigent X") depends on `START TRANSACTION` / `COMMIT` /
 * `ROLLBACK` being real — i.e. every cambios_ table actually using the
 * InnoDB engine, as declared in InitialSchema. Shared hosting occasionally
 * substitutes a different engine (a hosting-level default, a migration tool,
 * a misconfigured server) — MySQL accepts `ENGINE=InnoDB` in the CREATE
 * TABLE statement, WARNS if it could not honor it, but does NOT fail the
 * statement. When that happens, every transaction this plugin runs becomes a
 * silent no-op, and the "one vigent ocupación per plaza" guarantee is gone
 * with no error anywhere. checkStorageEngine() runs once per activation,
 * after migrations, and surfaces this loudly (an EventLog event plus an
 * admin_notice) instead of leaving it to be discovered as data corruption
 * months later.
 *
 * *** ES_ARCO BACKFILL + INVARIANT CHECK (0.1.13) ***
 * `cambios_plaza.es_arco` (added this release — see InitialSchema's own
 * docblock) replaces a previously DERIVED fact ("is this the goalkeeper's
 * plaza") with a STORED one. `backfillEsArco()` derives it once for every
 * plaza that predates the column; `checkEsArcoInvariant()` verifies, loudly,
 * that every team still has EXACTLY one such plaza — same
 * EventLog-event-plus-admin_notice discipline as checkStorageEngine() above,
 * for the same reason: a broken invariant here must never be discovered by
 * accident.
 *
 * *** THE 0.1.13 PRODUCTION INCIDENT THIS CLASS NOW FIXES (0.1.14) ***
 * `runIfOutdated()` used to be called from `Plugin::boot()` on
 * `plugins_loaded` — BEFORE SportsPress registers its `sp_position` taxonomy
 * on `init` (priority 10). The 0.1.13 backfill therefore ran with
 * `sp_position` not yet registered, `wp_get_object_terms()` returned a
 * `WP_Error` for every call, and `Plazas\PosicionResolver::resolverParaIds()`
 * (at the time) silently read that as "nobody has a position" — writing
 * `es_arco = 0` for all 330 live plazas, confirmed in production via `SELECT
 * season_id, team_id, SUM(es_arco) ... HAVING arcos <> 1`, which returned
 * every team. `Plugin::boot()` now defers the migration call to `init`
 * priority 11 (see that class's own docblock), and `PosicionResolver` now
 * throws rather than degrading (see its own docblock) — `backfillEsArco()`
 * below catches that specific failure and refuses to write anything or bump
 * the version, so the corrected backfill retries, and succeeds, on the very
 * next request after this release is deployed.
 *
 * *** THE SAME INCIDENT ALSO EXPOSED A SECOND GAP: THE DETECTIVE CHECK ITSELF
 * NEVER FIRED (0.1.14) *** `checkEsArcoInvariant()` WAS wired (it is called,
 * unconditionally, at the end of every `run()`) — the gap was not that it
 * went uncalled, but WHERE its `admin_notice` got registered: from inside
 * `run()`, which executes at most once per version bump. The one request
 * that ran the broken 0.1.13 backfill was not an admin page load, so the
 * `admin_notices` closure it registered was never invoked, and — because the
 * version was now (wrongly) current — `run()` never executed again to give
 * it a second chance. Confirmed in production: all 30 teams sat at
 * `es_arco = 0` with NO admin notice ever shown. `checkEsArcoInvariant()`
 * now PERSISTS its verdict instead, and `renderEsArcoInvariantNotice()`
 * renders it from a callback `Plugin::boot()` wires unconditionally on every
 * admin request — see both methods' own docblocks. `checkStorageEngine()`
 * above has the IDENTICAL shape (an `admin_notices` closure registered from
 * inside this same `run()`) and is NOT changed by this release — it is a
 * narrower, pre-existing, and lower-probability gap (a non-InnoDB table is a
 * one-time hosting misconfiguration, not something this plugin's own code
 * can silently reintroduce release over release the way a resolver's own
 * degradation could), left as a documented follow-up rather than fixed here
 * to keep this release's diff focused on the incident actually confirmed in
 * production.
 *
 * *** 0.1.15 NEEDS NO BACKFILL, AND NO TAXONOMY ORDERING CARE ***
 * `cambios_solicitud` gains the `reasignacion_arquero` tipo plus
 * `plaza_campo_id` / `entrante_campo_player_id` / `ocupacion_campo_id` (see
 * `InitialSchema::sqlCambiosSolicitud()`'s own docblock) for the grouped
 * goalkeeper reassignment. Unlike `es_arco`'s 0.1.13 backfill above, this
 * needs no one-time upgrade task here at all: `cambios_solicitud` was still
 * EMPTY in production (no existing row to migrate, same precondition the
 * `saliente_player_id` column already relied on in 0.1.11), and none of the
 * new columns, nor widening the `tipo` ENUM, ever reads a SportsPress
 * taxonomy — so the `init`-priority-11 ordering this class's own docblock
 * explains at length for `es_arco` simply does not apply here.
 * `InitialSchema::up()` — already called unconditionally by `run()` above —
 * picks the change up via its own idempotent `dbDelta()`.
 */
class MigrationRunner {

    private const DB_VERSION_OPTION = 'cambios_db_version';

    /**
     * Persists `checkEsArcoInvariant()`'s most recent verdict — see that
     * method's own docblock, "PERSISTED, NOT JUST RECORDED (0.1.14)", for why
     * this exists instead of (as before) an `admin_notices` closure
     * registered directly from inside that method. Autoload OFF — same
     * reasoning as `WpEventLog::OPTION_ULTIMO_ERROR`: read rarely, only by
     * `renderEsArcoInvariantNotice()`, never on every page load.
     */
    private const OPTION_ARCO_INVARIANTE = 'entre_redes_cambios_arco_invariante_violaciones';

    /**
     * Every table this plugin creates — see InitialSchema::up() for the
     * matching CREATE TABLE statements. Kept as its own list here (rather
     * than introspecting InitialSchema) because the storage-engine check is
     * a runtime diagnostic, not a schema definition.
     */
    private const TABLES = [
        'cambios_fecha',
        'cambios_fecha_partido',
        'cambios_settings',
        'cambios_capitan',
        'cambios_plaza',
        'cambios_ocupacion',
        'cambios_solicitud',
        'cambios_decision',
    ];

    /**
     * Run the migrations ONLY when the schema recorded in the database is
     * older than the code's own version — safe to call on every request.
     *
     * *** WHY THIS EXISTS, AND THE INCIDENT THAT MOTIVATED IT ***
     * `run()` is reachable from exactly one place: `register_activation_hook`
     * in the plugin's main file. That hook fires when an operator clicks
     * "Activate" — NOT when they upload a new zip over an already-active
     * plugin, which is how this plugin is actually deployed (see the repo's
     * build-plugin.sh and the "Reemplazar el actual con el subido" flow).
     *
     * Through 0.1.0 → 0.1.10 that went unnoticed because no release changed
     * the schema. 0.1.11 adds `cambios_solicitud.saliente_player_id`, and
     * without this method that column would simply never be created on the
     * live site: the first solicitud would write to a column that does not
     * exist.
     *
     * The `cambios_db_version` option already existed for precisely this
     * purpose — `run()` writes it on every activation — but nothing ever
     * READ it outside that same activation path. A value written and never
     * read is not a guard; it is a comment that looks like one.
     *
     * Cost: one `get_option()` per request against an autoloaded option
     * WordPress has already cached, and `dbDelta` runs only when the version
     * actually moved.
     */
    public static function runIfOutdated( EventLog $eventLog ): void {
        $installed = (string) get_option( self::DB_VERSION_OPTION, '0' );

        if ( version_compare( $installed, ENTRE_REDES_CAMBIOS_VERSION, '>=' ) ) {
            return;
        }

        self::run( $eventLog );
    }

    public static function run( EventLog $eventLog ): void {
        $installed = get_option( self::DB_VERSION_OPTION, '0' );
        $current   = ENTRE_REDES_CAMBIOS_VERSION;

        // Always run dbDelta on activation — safe to re-run, no-op if the
        // schema already matches. On upgrades this picks up new columns.
        InitialSchema::up();

        if ( version_compare( (string) $installed, $current, '<' ) ) {
            // One-time upgrade task (0.1.13): backfill cambios_plaza.es_arco
            // for every plaza that existed BEFORE this column did — see
            // backfillEsArco()'s own docblock. Runs BEFORE the version option
            // is bumped, same ordering discipline as every other migration
            // step here.
            //
            // *** THE VERSION OPTION IS BUMPED ONLY WHEN THE BACKFILL ACTUALLY
            // COMPLETED (0.1.14) *** `backfillEsArco()` now returns `false`,
            // instead of throwing, when `PosicionResolver::resolverParaIds()`
            // could not resolve any titular's position (see that method's own
            // docblock, "MUST NEVER WRITE es_arco = 0 ON A FAILED
            // RESOLUTION") — `false` is returned, not an exception allowed to
            // propagate, specifically so a request that happens to run this
            // migration does not take down the ENTIRE request (REST route,
            // admin page, cron run) over a transient "the taxonomy is not
            // registered yet" condition; the failure is still loud (an
            // EventLog event — see below), just not fatal to the caller.
            // `update_option()` below runs ONLY when the backfill reports
            // success — exactly the same "stored version must stay old so a
            // retry on the next request runs it again" discipline this
            // comment already described, now actually enforced instead of
            // merely stated: before this fix, `backfillEsArco()` always
            // "succeeded" (it silently wrote `es_arco = 0` for every plaza
            // whenever position resolution failed), so this line always ran
            // — recording a half-done upgrade as complete. That silent
            // success is the exact production incident this release fixes
            // — see PosicionResolver's own class docblock for the full
            // chain.
            if ( self::backfillEsArco( $eventLog ) ) {
                update_option( self::DB_VERSION_OPTION, $current );
            }
        }

        self::checkStorageEngine( $eventLog );
        self::checkEsArcoInvariant( $eventLog );
    }

    /**
     * ONE-TIME BACKFILL (0.1.13): `cambios_plaza.es_arco` did not exist
     * before this release, and a new column defaults to `0` — which, read
     * literally, would mean "no team has a goalkeeper's plaza", the exact
     * broken state this column exists to prevent (see InitialSchema's own
     * docblock on `es_arco`). Unlike `cambios_solicitud.saliente_player_id`
     * (0.1.11), `cambios_plaza` is NOT empty in production (330 plazas
     * across 30 teams at the time this was written), so a plain `dbDelta()`
     * column add is not sufficient on its own — every existing row needs its
     * `es_arco` DERIVED, once, from its titular's `sp_position`.
     *
     * *** IDEMPOTENT, SAFE TO RE-RUN ***
     * This recomputes `es_arco` for EVERY plaza row from the SAME rule
     * `PlazaRepository::doOpenPlaza()` now applies to a brand-new plaza
     * (`PosicionResolver::esPosicionDelArqueroTitular()`, term 3 only) —
     * running it twice (e.g. a retried activation, or a future manual
     * re-trigger) writes the exact same value back and changes nothing. It
     * is wired as a one-time task (guarded by the version check in run()
     * above) purely because there is no reason to pay this cost on every
     * activation once it has already run — not because running it again
     * would be unsafe.
     *
     * *** EVERY PLAZA, NOT JUST THE OPEN ONES ***
     * `closed_at` is not filtered on here: `es_arco` is a fact about the
     * PLAZA (which player is its permanent titular), independent of whether
     * the plaza is still open — see InitialSchema's own docblock, "the plaza
     * has `closed_at` for a plaza that stops existing entirely". Nothing in
     * this slice ever sets `closed_at`, so this distinction is moot in
     * practice today, but deriving the fact correctly costs nothing extra.
     *
     * *** ONE BATCHED POSITION LOOKUP, NEVER ONE PER PLAZA ***
     * Every distinct `titular_player_id` across every plaza is resolved in
     * ONE `PosicionResolver::resolverParaIds()` call — the same batching
     * discipline `Dictamen\DictamenContextAssembler` and
     * `Plazas\CandidatosResolver::buscarPaginado()` already apply — before
     * writing anything. The actual writes are still one `$wpdb->update()`
     * per plaza (this runs once, ever, for a few hundred rows; the N+1 here
     * is not worth the extra complexity of a bulk `CASE WHEN` statement).
     *
     * *** MUST NEVER WRITE `es_arco = 0` ON A FAILED RESOLUTION (0.1.14) ***
     * This is the exact production incident this release fixes: before this
     * change, `PosicionResolver::resolverParaIds()` silently returned
     * `SIN_POSICION` for every id whenever `wp_get_object_terms()` failed
     * (e.g. the `sp_position` taxonomy not registered yet — see
     * `Plugin::boot()`'s own docblock for why that used to be possible), and
     * this method dutifully wrote `es_arco = 0` for all 330 live plazas —
     * "nobody is a goalkeeper", the exact broken state this column exists to
     * prevent. `resolverParaIds()` now throws instead of degrading (see its
     * own docblock) — this method catches ONLY that failure, logs it, and
     * returns `false` WITHOUT writing a single row, so `run()`'s caller can
     * refuse to bump `cambios_db_version` (see that method's own docblock)
     * and retry this same backfill on the next request instead of recording
     * a half-done upgrade as complete.
     *
     * *** WHY THIS CATCHES RATHER THAN LETTING THE EXCEPTION PROPAGATE ***
     * `run()` executes on every request once the version gate is open (via
     * `runIfOutdated()`, see `Plugin::boot()`) — an uncaught exception here
     * would turn a transient "taxonomy not registered yet" condition into a
     * fatal error for the ENTIRE request (a REST call, an admin page, a cron
     * run), every single time, until the underlying cause is fixed. Returning
     * `false` keeps the failure loud (the EventLog event below, which
     * `WpEventLog` also persists into `entre_redes_cambios_ultimo_error` —
     * see that class's own docblock, since this event's name contains
     * `fallid`) without taking down whatever triggered this request.
     *
     * @return bool `true` when the backfill ran to completion (including the
     *         trivial "no plazas at all" case) — `run()` bumps
     *         `cambios_db_version` only when this returns `true`. `false`
     *         when position resolution failed and NOTHING was written.
     */
    private static function backfillEsArco( EventLog $eventLog ): bool {
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( "SELECT id, titular_player_id FROM {$p}cambios_plaza", ARRAY_A );

        if ( ! is_array( $rows ) || [] === $rows ) {
            return true;
        }

        $titularIds = array_values( array_unique( array_map(
            static fn ( array $row ): int => (int) $row['titular_player_id'],
            $rows
        ) ) );

        $posicionResolver = new PosicionResolver( $eventLog );

        try {
            $posiciones = $posicionResolver->resolverParaIds( $titularIds );
        } catch ( \RuntimeException $e ) {
            // $eventLog already received a `posicion.resolucion_fallida` event
            // from resolverParaIds() itself — this ADDITIONAL event names the
            // consequence specifically (the backfill itself did not run),
            // which `posicion.resolucion_fallida` alone does not say.
            $eventLog->record( 'migracion.es_arco_backfill_fallida', [
                'motivo'  => 'PosicionResolver::resolverParaIds() fallo — ver posicion.resolucion_fallida para el detalle',
                'mensaje' => $e->getMessage(),
            ] );

            return false;
        }

        $actualizadas = 0;
        foreach ( $rows as $row ) {
            $plazaId         = (int) $row['id'];
            $titularPlayerId = (int) $row['titular_player_id'];
            $esArco          = PosicionResolver::esPosicionDelArqueroTitular( $posiciones[ $titularPlayerId ] ) ? 1 : 0;

            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $p . 'cambios_plaza',
                [ 'es_arco' => $esArco ],
                [ 'id' => $plazaId ]
            );
            ++$actualizadas;
        }

        $eventLog->record( 'plaza.es_arco_backfill', [
            'plazas_actualizadas' => $actualizadas,
        ] );

        return true;
    }

    /**
     * Loud verification of the invariant `es_arco` exists to back: exactly
     * one `es_arco = 1` plaza per `(season_id, team_id)` among the OPEN
     * plazas (`closed_at IS NULL`) — zero is broken (the team has no goal),
     * two is broken (more than one plaza claims to be it). See InitialSchema's
     * own docblock and this feature's task brief for the full reasoning.
     *
     * Same discipline as `checkStorageEngine()` above: a violation must
     * never be discovered by accident months later, so this records an
     * `EventLog` event AND persists a verdict an admin_notice can render,
     * instead of merely returning a value nothing reads. Also same
     * LIMITATION as `checkStorageEngine()`: this only runs from `run()`,
     * i.e. on plugin activation or an actual version upgrade — never on
     * every request — so a violation introduced BETWEEN two activations
     * (e.g. a manual DB edit, or a bug in a future slice) stays silent until
     * the next one. Widening this to run on every request, or wiring it into
     * `Plazas\Alta\TitularesListImporter`'s own CLI tool, is a reasonable
     * follow-up this slice deliberately leaves out — `planificar()`'s own
     * "no arquero among titulares" check is the PREVENTIVE half of this
     * story; this method is the DETECTIVE half, for drift after the fact.
     *
     * TOLERANT ON PURPOSE, same reasoning as `checkStorageEngine()`: an empty
     * `cambios_plaza` table (a fresh install) produces zero groups, which is
     * not a violation of anything.
     *
     * *** PERSISTED, NOT JUST RECORDED (0.1.14) *** A LIVE incident exposed a
     * gap in the ORIGINAL design here: this method registered its
     * `admin_notice` via a plain `add_action( 'admin_notices', $closure )`
     * call, from INSIDE this method, which itself only ever runs from inside
     * `run()` — and `run()` executes AT MOST ONCE per version bump
     * (`runIfOutdated()`'s own version gate immediately returns on every
     * later request once `cambios_db_version` is current — see that
     * method's own docblock). Whatever ONE request happened to trigger that
     * single execution is not necessarily an admin page render — a REST
     * call, a cron run, or a front-end page view all boot this plugin
     * (`Plugin::boot()` runs on `plugins_loaded` for every request type) just
     * as validly, and `do_action( 'admin_notices' )` simply never fires for
     * any of them. The closure was registered and then never invoked, and
     * there is no second chance: the version is now current, so this method
     * never runs again until the NEXT release. Confirmed in production: the
     * 0.1.13 incident left all 30 teams at `es_arco = 0` with NO admin
     * notice ever rendered, because the request that ran the (broken)
     * backfill was not an admin page load. A detective control that can fire
     * at most once, in one arbitrary request, is not a control.
     *
     * The fix: this method now PERSISTS its verdict into
     * `self::OPTION_ARCO_INVARIANTE` (cleared via `delete_option()` the
     * moment the invariant holds again — see below) instead of registering
     * an ephemeral per-request closure, and a SEPARATE method,
     * `renderEsArcoInvariantNotice()`, renders whatever is currently
     * persisted. `Plugin::boot()` wires THAT method unconditionally, on
     * every admin request — never gated on whether THIS request is the one
     * that happened to run the migration — so an operator sees the notice
     * the NEXT time they open wp-admin, however many requests later that
     * is. This is still a STORED verdict, refreshed only when
     * `checkEsArcoInvariant()` itself runs (migration time, or a future
     * repair) — deliberately NOT a live `SUM(es_arco)` query against
     * `cambios_plaza` on every admin page load, which would defeat the
     * "TOLERANT ON PURPOSE" cost-consciousness this whole class already
     * applies.
     */
    private static function checkEsArcoInvariant( EventLog $eventLog ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results(
            "SELECT season_id, team_id, SUM(es_arco) AS arco_count
               FROM {$p}cambios_plaza
              WHERE closed_at IS NULL
              GROUP BY season_id, team_id",
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return;
        }

        $violaciones = [];
        foreach ( $rows as $row ) {
            $arcoCount = (int) $row['arco_count'];

            if ( 1 !== $arcoCount ) {
                $violaciones[] = [
                    'season_id'     => (int) $row['season_id'],
                    'team_id'       => (int) $row['team_id'],
                    'es_arco_count' => $arcoCount,
                ];
            }
        }

        if ( empty( $violaciones ) ) {
            // The invariant holds — or holds AGAIN, after a repair — so any
            // STALE persisted verdict from a previous run must be cleared;
            // otherwise renderEsArcoInvariantNotice() would keep showing a
            // notice for a problem that no longer exists.
            delete_option( self::OPTION_ARCO_INVARIANTE );

            return;
        }

        // `arco.invariante_fallida` — contains `fallid` ON PURPOSE (renamed
        // from the pre-0.1.14 `arco.invariante_violada`; see this class's
        // CHANGELOG entry) so this ALSO lands in
        // `entre_redes_cambios_ultimo_error` via `WpEventLog`'s own
        // `str_contains( $evento, 'fallid' )` matching rule (see that
        // class's own docblock) — the one durable, no-admin-UI-required
        // place an operator on this shared host (no PHP error log anywhere
        // under `public_html`) can read a failure from. Before this rename,
        // this exact event was INVISIBLE to that mechanism — the live
        // incident this release fixes was found only by querying
        // `cambios_plaza` directly in phpMyAdmin, never from that option.
        $eventLog->record( 'arco.invariante_fallida', [ 'equipos' => $violaciones ] );

        update_option( self::OPTION_ARCO_INVARIANTE, $violaciones, false );
    }

    /**
     * Renders the MOST RECENTLY PERSISTED `checkEsArcoInvariant()` verdict as
     * an `admin_notice` — see that method's own docblock, "PERSISTED, NOT
     * JUST RECORDED (0.1.14)", for why this is a SEPARATE method rather than
     * a closure registered from inside `run()`. `Plugin::boot()` wires this
     * UNCONDITIONALLY on every admin request (`is_admin()`), so an operator
     * sees the notice the next time they open wp-admin — any later request,
     * not only the one arbitrary request that happened to run the migration.
     *
     * A no-op when nothing is persisted (the common case, and the state once
     * a violation has been repaired — `checkEsArcoInvariant()` calls
     * `delete_option()` the next time it runs and finds the invariant
     * holds).
     */
    public static function renderEsArcoInvariantNotice(): void {
        $violaciones = get_option( self::OPTION_ARCO_INVARIANTE, [] );

        if ( ! is_array( $violaciones ) || [] === $violaciones ) {
            return;
        }

        $detalle = implode( ', ', array_map(
            static fn ( array $v ): string => "team_id {$v['team_id']} (temporada {$v['season_id']}): {$v['es_arco_count']} plaza(s) de arquero",
            $violaciones
        ) );

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(
                'entre-redes-cambios: los siguientes equipos no tienen exactamente una plaza de arquero (es_arco=1): '
                . $detalle . '. Cada equipo debe tener EXACTAMENTE una — revisar el titular de las plazas en cambios_plaza.'
            )
        );
    }

    /**
     * Queries `information_schema.TABLES` for the real, effective engine of
     * every cambios_ table and reports any that is not InnoDB.
     *
     * TOLERANT ON PURPOSE: the PHPUnit suite runs against the SQLite test
     * shim (see tests/wp-shim.php), which has no `information_schema` at
     * all — `$wpdb->get_var()` there catches the resulting PDOException and
     * returns null (see the shim's own contract). A null or empty result is
     * therefore treated as "could not check", never as "not InnoDB" — this
     * diagnostic must never fail activation, or a test environment, over a
     * query it could not run.
     */
    private static function checkStorageEngine( EventLog $eventLog ): void {
        global $wpdb;

        $p          = $wpdb->prefix;
        $nonInnoDb  = [];

        foreach ( self::TABLES as $table ) {
            $fullTable = $p . $table;

            $engine = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT ENGINE FROM information_schema.TABLES '
                    . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                    $fullTable
                )
            );

            if ( null === $engine || '' === $engine ) {
                continue;
            }

            if ( 'InnoDB' !== $engine ) {
                $nonInnoDb[ $fullTable ] = $engine;
            }
        }

        if ( empty( $nonInnoDb ) ) {
            return;
        }

        $eventLog->record( 'motor.no_innodb', [ 'tablas' => $nonInnoDb ] );

        add_action( 'admin_notices', static function () use ( $nonInnoDb ): void {
            $detalle = implode( ', ', array_map(
                static fn( string $table, string $engine ): string => "{$table} ({$engine})",
                array_keys( $nonInnoDb ),
                array_values( $nonInnoDb )
            ) );

            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html(
                    'entre-redes-cambios: las siguientes tablas no usan el motor InnoDB: ' . $detalle . '. '
                    . 'Las transacciones de este plugin (START TRANSACTION / COMMIT / ROLLBACK) pueden '
                    . 'ejecutarse como no-ops silenciosos con este motor, rompiendo la garantia de '
                    . '"a lo sumo una ocupacion vigente por plaza" (y su equivalente para capitanes). '
                    . 'Contactar al hosting para migrar estas tablas a InnoDB.'
                )
            );
        } );
    }
}

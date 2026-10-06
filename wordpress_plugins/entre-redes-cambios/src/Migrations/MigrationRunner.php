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
 */
class MigrationRunner {

    private const DB_VERSION_OPTION = 'cambios_db_version';

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
            // step here: if this ever threw, the stored version must stay
            // old so a retry on the next request runs it again.
            self::backfillEsArco( $eventLog );
            update_option( self::DB_VERSION_OPTION, $current );
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
     */
    private static function backfillEsArco( EventLog $eventLog ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( "SELECT id, titular_player_id FROM {$p}cambios_plaza", ARRAY_A );

        if ( ! is_array( $rows ) || [] === $rows ) {
            return;
        }

        $titularIds = array_values( array_unique( array_map(
            static fn ( array $row ): int => (int) $row['titular_player_id'],
            $rows
        ) ) );

        $posicionResolver = new PosicionResolver();
        $posiciones       = $posicionResolver->resolverParaIds( $titularIds );

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
     * `EventLog` event AND raises an `admin_notice`, instead of merely
     * returning a value nothing reads. Also same LIMITATION as
     * `checkStorageEngine()`: this only runs from `run()`, i.e. on plugin
     * activation or an actual version upgrade — never on every request — so
     * a violation introduced BETWEEN two activations (e.g. a manual DB edit,
     * or a bug in a future slice) stays silent until the next one. Widening
     * this to run on every request, or wiring it into
     * `Plazas\Alta\TitularesListImporter`'s own CLI tool, is a reasonable
     * follow-up this slice deliberately leaves out — `planificar()`'s own
     * "no arquero among titulares" check is the PREVENTIVE half of this
     * story; this method is the DETECTIVE half, for drift after the fact.
     *
     * TOLERANT ON PURPOSE, same reasoning as `checkStorageEngine()`: an empty
     * `cambios_plaza` table (a fresh install) produces zero groups, which is
     * not a violation of anything.
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
            return;
        }

        $eventLog->record( 'arco.invariante_violada', [ 'equipos' => $violaciones ] );

        add_action( 'admin_notices', static function () use ( $violaciones ): void {
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
        } );
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

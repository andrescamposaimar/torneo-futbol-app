<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Migrations;

use EntreRedes\Cambios\Observability\EventLog;

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
            // Reserved for one-time upgrade tasks. None exist yet in slice 0.
            update_option( self::DB_VERSION_OPTION, $current );
        }

        self::checkStorageEngine( $eventLog );
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

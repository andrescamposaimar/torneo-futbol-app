<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Migrations;

/**
 * Version-aware migration runner.
 *
 * Compares the stored `cambios_db_version` WP option against the current
 * plugin version constant. dbDelta-backed InitialSchema::up() is always safe
 * to re-run (idempotent), so it runs on every activation regardless of the
 * version gate. The version gate exists only to fence off one-time tasks
 * (none in slice 0 — reserved for future migrations that need to run exactly
 * once per upgrade, e.g. a one-off data backfill).
 */
class MigrationRunner {

    private const DB_VERSION_OPTION = 'cambios_db_version';

    public static function run(): void {
        $installed = get_option( self::DB_VERSION_OPTION, '0' );
        $current   = ENTRE_REDES_CAMBIOS_VERSION;

        // Always run dbDelta on activation — safe to re-run, no-op if the
        // schema already matches. On upgrades this picks up new columns.
        InitialSchema::up();

        if ( version_compare( (string) $installed, $current, '<' ) ) {
            // Reserved for one-time upgrade tasks. None exist yet in slice 0.
            update_option( self::DB_VERSION_OPTION, $current );
        }
    }
}

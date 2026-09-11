<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Migrations;

/**
 * Version-aware migration runner.
 *
 * Compares the stored `campeones_db_version` WP option against the current
 * ENTRE_REDES_CAMPEONES_VERSION constant. InitialSchema::up() runs
 * unconditionally on every activation — dbDelta is idempotent, and this
 * picks up new columns/indexes on upgrade without a version check.
 *
 * Unlike entre-redes-prode's MigrationRunner, there is no cron scheduling
 * here and no one-time secret generation: this plugin has no cron layer at
 * all (design §1), so a future version bump would gate only schema-adjacent
 * work, of which there is currently none.
 */
class MigrationRunner {

    private const DB_VERSION_OPTION = 'campeones_db_version';

    public static function run(): void {
        $installed = get_option( self::DB_VERSION_OPTION, '0' );
        $current   = ENTRE_REDES_CAMPEONES_VERSION;

        InitialSchema::up();

        if ( version_compare( (string) $installed, $current, '<' ) ) {
            update_option( self::DB_VERSION_OPTION, $current );
        }
    }
}

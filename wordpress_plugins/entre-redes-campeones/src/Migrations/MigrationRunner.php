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
        global $wpdb;

        $installed = get_option( self::DB_VERSION_OPTION, '0' );
        $current   = ENTRE_REDES_CAMPEONES_VERSION;

        $results = InitialSchema::up();

        if ( self::migrationFailed( $wpdb, $results ) ) {
            error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                'entre-redes-campeones: schema migration failed, db_version left at %s (not advanced to %s). DB error: %s. dbDelta results: %s',
                $installed,
                $current,
                (string) $wpdb->last_error,
                implode( '; ', $results )
            ) );
            return;
        }

        if ( version_compare( (string) $installed, $current, '<' ) ) {
            update_option( self::DB_VERSION_OPTION, $current );
        }
    }

    /**
     * dbDelta() never throws — a partial failure surfaces only as
     * $wpdb->last_error or as an 'Error: ...'-prefixed entry in its returned
     * result messages. Both must be checked: relying on either alone would
     * miss failures the other one catches.
     *
     * @param string[] $results
     */
    private static function migrationFailed( \wpdb $wpdb, array $results ): bool {
        if ( ! empty( $wpdb->last_error ) ) {
            return true;
        }

        foreach ( $results as $result ) {
            if ( str_starts_with( (string) $result, 'Error:' ) ) {
                return true;
            }
        }

        return false;
    }
}

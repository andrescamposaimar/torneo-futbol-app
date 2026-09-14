<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Support;

/**
 * A real wpdb (backed by its own fresh in-memory SQLite database, via the
 * normal parent constructor) whose query() reports failure for any CREATE
 * TABLE statement — used to prove that MigrationRunner::run() detects a
 * failed InitialSchema::up() instead of blindly advancing the stored DB
 * version (defect C).
 *
 * dbDelta() never throws; a partial failure only ever surfaces as
 * $wpdb->last_error or as an 'Error: ...' entry in its returned messages.
 * This double reproduces that failure mode without touching real MySQL.
 */
class FailingQueryWpdb extends \wpdb {

    public function query( string $sql ): int|false {
        if ( preg_match( '/CREATE\s+TABLE/i', $sql ) ) {
            $this->last_error = 'Simulated CREATE TABLE failure for test';
            return false;
        }

        return parent::query( $sql );
    }
}

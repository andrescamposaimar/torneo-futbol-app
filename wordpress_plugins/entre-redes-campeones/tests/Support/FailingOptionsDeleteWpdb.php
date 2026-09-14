<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Support;

/**
 * A real wpdb (backed by its own fresh in-memory SQLite database, via the
 * normal parent constructor) whose query() reports failure for
 * CacheInvalidator::flush()'s raw per-player transient LIKE-delete against
 * wp_options — used to prove flush() checks that query's return value and
 * logs on failure (item 2), mirroring FailingQueryWpdb's precedent.
 */
class FailingOptionsDeleteWpdb extends \wpdb {

    public function query( string $sql ): int|false {
        if ( str_contains( $sql, 'DELETE FROM' ) && str_contains( $sql, 'options' ) && str_contains( $sql, '_transient_' ) ) {
            $this->last_error = 'Simulated per-player transient flush failure for test';
            return false;
        }

        return parent::query( $sql );
    }
}

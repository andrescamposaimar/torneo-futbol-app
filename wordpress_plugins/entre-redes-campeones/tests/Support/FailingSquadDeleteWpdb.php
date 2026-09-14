<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Support;

/**
 * A real wpdb (backed by its own fresh in-memory SQLite database, via the
 * normal parent constructor) whose query() fails specifically for
 * SquadRepository::deleteByTitle()'s raw `DELETE FROM ...campeones_plantel`
 * statement — used to force TitleDeletionService's FIRST write (the squad
 * rows, before the title row is touched) to fail. The existing rollback
 * test (FailingTitleDeleteWpdb) only forces the SECOND write to fail; the
 * first failing is the cheaper, more important branch to prove, since
 * nothing has been deleted yet when it does (item 9).
 */
class FailingSquadDeleteWpdb extends \wpdb {

    public function query( string $sql ): int|false {
        if ( preg_match( '/DELETE\s+FROM\s+\S*campeones_plantel\b/i', $sql ) ) {
            $this->last_error = 'Simulated squad delete failure for test';
            return false;
        }

        return parent::query( $sql );
    }
}

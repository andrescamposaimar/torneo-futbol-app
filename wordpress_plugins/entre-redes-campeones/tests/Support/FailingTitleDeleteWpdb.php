<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Support;

/**
 * A real wpdb (backed by its own fresh in-memory SQLite database, via the
 * normal parent constructor) whose delete() fails specifically for
 * campeones_titulo — used to force TitleDeletionService's second write
 * (the title row, after the squad rows are already gone) to fail, proving
 * the whole operation rolls back rather than leaving an orphaned squad.
 */
class FailingTitleDeleteWpdb extends \wpdb {

    public function delete( string $table, array $where, mixed $where_format = null ): int|false {
        if ( str_ends_with( $table, 'campeones_titulo' ) ) {
            $this->last_error = 'Simulated delete failure for test';
            return false;
        }

        return parent::delete( $table, $where, $where_format );
    }
}

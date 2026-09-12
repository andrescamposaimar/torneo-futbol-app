<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Support;

/**
 * A real wpdb (backed by its own fresh in-memory SQLite database, via the
 * normal parent constructor) whose insert() reports failure for a chosen
 * table (every table by default) — used to prove that repository writes
 * check wpdb::insert()'s return value instead of discarding it (defect B).
 *
 * Callers must create the schema on THIS instance (e.g. by temporarily
 * swapping the global $wpdb before calling InitialSchema::up()) since it
 * does not share storage with the real global $wpdb.
 */
class FailingInsertWpdb extends \wpdb {

    /**
     * @param string|null $failOnTable Table name to fail inserts against, or
     *                                  null (default) to fail every insert.
     */
    public function __construct( private readonly ?string $failOnTable = null ) {
        parent::__construct();
    }

    public function insert( string $table, array $data, mixed $format = null ): int|false {
        if ( null !== $this->failOnTable && $table !== $this->failOnTable ) {
            return parent::insert( $table, $data, $format );
        }

        $this->last_error = 'Simulated insert failure for test';
        return false;
    }
}

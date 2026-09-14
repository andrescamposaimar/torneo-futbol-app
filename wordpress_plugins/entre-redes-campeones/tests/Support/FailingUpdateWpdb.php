<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Support;

/**
 * A real wpdb (backed by its own fresh in-memory SQLite database, via the
 * normal parent constructor) whose update() reports failure for a chosen
 * table (every table by default) — mirrors FailingInsertWpdb, used to prove
 * a repository's update() checks wpdb::update()'s return value instead of
 * discarding it (item 9: TitleRepository::update() had no test forcing
 * this).
 */
class FailingUpdateWpdb extends \wpdb {

    /**
     * @param string|null $failOnTable Table name to fail updates against, or
     *                                  null (default) to fail every update.
     */
    public function __construct( private readonly ?string $failOnTable = null ) {
        parent::__construct();
    }

    public function update( string $table, array $data, array $where ): int|false {
        if ( null !== $this->failOnTable && $table !== $this->failOnTable ) {
            return parent::update( $table, $data, $where );
        }

        $this->last_error = 'Simulated update failure for test';
        return false;
    }
}

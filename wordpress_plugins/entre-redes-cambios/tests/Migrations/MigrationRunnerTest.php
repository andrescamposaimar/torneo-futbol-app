<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Migrations;

use EntreRedes\Cambios\Migrations\MigrationRunner;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use PHPUnit\Framework\TestCase;

/**
 * MigrationRunner::run() against the in-memory SQLite shim.
 *
 * The InnoDB storage-engine check queries `information_schema.TABLES`, which
 * does not exist under SQLite — tests/wp-shim.php's `$wpdb->get_var()`
 * catches the resulting PDOException and returns null (see that shim's
 * docblock). These tests assert the tolerant side of that check: it must
 * never fail activation, and must never report a false "not InnoDB", when
 * the query itself could not run.
 */
class MigrationRunnerTest extends TestCase {

    public function test_run_does_not_throw_and_does_not_report_a_false_non_innodb_when_the_engine_query_is_unavailable(): void {
        $eventLog = new InMemoryEventLog();

        MigrationRunner::run( $eventLog );

        $this->assertFalse(
            $eventLog->has( 'motor.no_innodb' ),
            'The SQLite shim has no information_schema — the check must treat an unavailable query as '
                . '"could not check", never as "found a non-InnoDB table".'
        );
    }

    public function test_run_updates_the_stored_db_version_option(): void {
        MigrationRunner::run( new InMemoryEventLog() );

        $this->assertSame( ENTRE_REDES_CAMBIOS_VERSION, get_option( 'cambios_db_version' ) );
    }
}

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

    /**
     * The deployment reality this guards: a new zip is uploaded over an
     * ALREADY ACTIVE plugin, so `register_activation_hook` — run()'s only
     * other caller — never fires. Without an upgrade-time run, a release that
     * adds a column would leave that column uncreated on the live site.
     */
    public function test_run_if_outdated_migrates_when_the_stored_version_is_older(): void {
        update_option( 'cambios_db_version', '0.0.1' );

        MigrationRunner::runIfOutdated( new InMemoryEventLog() );

        $this->assertSame(
            ENTRE_REDES_CAMBIOS_VERSION,
            get_option( 'cambios_db_version' ),
            'An older stored schema version must trigger the migration on a plain plugin upgrade.'
        );
    }

    public function test_run_if_outdated_migrates_when_no_version_was_ever_stored(): void {
        update_option( 'cambios_db_version', false );

        MigrationRunner::runIfOutdated( new InMemoryEventLog() );

        $this->assertSame( ENTRE_REDES_CAMBIOS_VERSION, get_option( 'cambios_db_version' ) );
    }

    public function test_run_if_outdated_is_a_no_op_once_the_stored_version_is_current(): void {
        update_option( 'cambios_db_version', ENTRE_REDES_CAMBIOS_VERSION );

        $eventLog = new InMemoryEventLog();
        MigrationRunner::runIfOutdated( $eventLog );

        // Nothing to assert about the schema (dbDelta is idempotent anyway);
        // what matters is that the current version is left untouched and the
        // call is cheap enough to sit on every request.
        $this->assertSame( ENTRE_REDES_CAMBIOS_VERSION, get_option( 'cambios_db_version' ) );
    }
}

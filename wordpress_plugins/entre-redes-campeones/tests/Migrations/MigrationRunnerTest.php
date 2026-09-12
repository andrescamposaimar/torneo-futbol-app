<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Migrations;

use EntreRedes\Campeones\Migrations\MigrationRunner;
use EntreRedes\Campeones\Tests\Support\FailingQueryWpdb;
use PHPUnit\Framework\TestCase;

/**
 * MigrationRunner::run() must only advance campeones_db_version when
 * InitialSchema::up() actually succeeded. dbDelta() never throws — a partial
 * failure surfaces only via $wpdb->last_error or an 'Error: ...' entry in its
 * returned messages — so run() must consult both before bumping the option.
 */
class MigrationRunnerTest extends TestCase {

    protected function setUp(): void {
        unset( $GLOBALS['_campeones_test_options']['campeones_db_version'] );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['_campeones_test_options']['campeones_db_version'] );
    }

    public function test_run_advances_the_db_version_on_success(): void {
        $this->assertSame( '0', get_option( 'campeones_db_version', '0' ) );

        MigrationRunner::run();

        $this->assertSame(
            ENTRE_REDES_CAMPEONES_VERSION,
            get_option( 'campeones_db_version', '0' ),
            'A successful migration must advance the stored DB version.'
        );
    }

    public function test_run_does_not_advance_the_db_version_when_the_migration_fails(): void {
        global $wpdb;
        $original = $wpdb;
        $failing  = new FailingQueryWpdb();

        try {
            $wpdb = $failing;

            MigrationRunner::run();

            $this->assertSame(
                '0',
                get_option( 'campeones_db_version', '0' ),
                'A failed migration must never advance the stored DB version.'
            );
        } finally {
            $wpdb = $original;
        }
    }
}

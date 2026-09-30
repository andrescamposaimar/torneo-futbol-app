<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Migrations;

use EntreRedes\Credencial\Migrations\MigrationRunner;
use EntreRedes\Credencial\Observability\InMemoryEventLog;
use PHPUnit\Framework\TestCase;

/**
 * MigrationRunner::run() against the in-memory SQLite shim. Adapted from
 * entre-redes-cambios/tests/Migrations/MigrationRunnerTest.php, plus new
 * coverage for checkRuntimeLimits() (design D9b — tolerant, never fails
 * activation, records `runtime.limits_low` and an admin_notice only when a
 * limit is actually below the plugin's own minimums).
 */
class MigrationRunnerTest extends TestCase {

    public function test_run_updates_the_stored_db_version_option(): void {
        MigrationRunner::run( new InMemoryEventLog() );

        $this->assertSame( ENTRE_REDES_CREDENCIAL_VERSION, get_option( 'credencial_db_version' ) );
    }

    public function test_runtime_limits_within_minimums_records_nothing(): void {
        $eventLog = new InMemoryEventLog();

        MigrationRunner::checkRuntimeLimits(
            $eventLog,
            static fn ( string $key ): string => match ( $key ) {
                'memory_limit'         => '256M',
                'upload_max_filesize'  => '8M',
                'post_max_size'        => '8M',
                default                => '',
            }
        );

        $this->assertFalse( $eventLog->has( 'runtime.limits_low' ) );
    }

    public function test_unlimited_memory_limit_is_never_reported_as_low(): void {
        $eventLog = new InMemoryEventLog();

        MigrationRunner::checkRuntimeLimits(
            $eventLog,
            static fn ( string $key ): string => match ( $key ) {
                'memory_limit'         => '-1',
                'upload_max_filesize'  => '8M',
                'post_max_size'        => '8M',
                default                => '',
            }
        );

        $this->assertFalse( $eventLog->has( 'runtime.limits_low' ) );
    }

    public function test_low_memory_limit_records_runtime_limits_low_with_the_offending_setting(): void {
        $eventLog = new InMemoryEventLog();

        MigrationRunner::checkRuntimeLimits(
            $eventLog,
            static fn ( string $key ): string => match ( $key ) {
                'memory_limit'         => '64M',
                'upload_max_filesize'  => '8M',
                'post_max_size'        => '8M',
                default                => '',
            }
        );

        $this->assertTrue( $eventLog->has( 'runtime.limits_low' ) );
        $problems = $eventLog->last()['contexto']['problems'];
        $this->assertNotEmpty( array_filter( $problems, static fn ( $p ) => str_contains( $p, 'memory_limit' ) ) );
    }

    public function test_low_upload_max_filesize_records_runtime_limits_low(): void {
        $eventLog = new InMemoryEventLog();

        MigrationRunner::checkRuntimeLimits(
            $eventLog,
            static fn ( string $key ): string => match ( $key ) {
                'memory_limit'         => '256M',
                'upload_max_filesize'  => '2M',
                'post_max_size'        => '8M',
                default                => '',
            }
        );

        $this->assertTrue( $eventLog->has( 'runtime.limits_low' ) );
        $problems = $eventLog->last()['contexto']['problems'];
        $this->assertNotEmpty( array_filter( $problems, static fn ( $p ) => str_contains( $p, 'upload_max_filesize' ) ) );
    }

    public function test_low_post_max_size_records_runtime_limits_low(): void {
        $eventLog = new InMemoryEventLog();

        MigrationRunner::checkRuntimeLimits(
            $eventLog,
            static fn ( string $key ): string => match ( $key ) {
                'memory_limit'         => '256M',
                'upload_max_filesize'  => '8M',
                'post_max_size'        => '2M',
                default                => '',
            }
        );

        $this->assertTrue( $eventLog->has( 'runtime.limits_low' ) );
        $problems = $eventLog->last()['contexto']['problems'];
        $this->assertNotEmpty( array_filter( $problems, static fn ( $p ) => str_contains( $p, 'post_max_size' ) ) );
    }

    public function test_run_generates_a_code_secret_when_none_exists(): void {
        delete_option( 'credencial_code_secret' );

        MigrationRunner::run( new InMemoryEventLog() );

        $secret = get_option( 'credencial_code_secret' );
        $this->assertIsString( $secret );
        $this->assertNotSame( '', $secret );
    }

    public function test_run_never_regenerates_an_existing_code_secret(): void {
        MigrationRunner::run( new InMemoryEventLog() );
        $first = get_option( 'credencial_code_secret' );

        MigrationRunner::run( new InMemoryEventLog() );
        $second = get_option( 'credencial_code_secret' );

        $this->assertSame( $first, $second );
    }

    // -------------------------------------------------------------------------
    // Extra (slice 2b): missing PHP extensions the upload pipeline needs
    // (PhotoValidator's finfo/getimagesizefromstring, GdPhotoReencoder's GD
    // calls) — same tolerant, non-blocking pattern as the ini limits above.
    // -------------------------------------------------------------------------

    public function test_all_required_extensions_present_records_nothing(): void {
        $eventLog = new InMemoryEventLog();

        MigrationRunner::checkRuntimeLimits(
            $eventLog,
            static fn ( string $key ): string => match ( $key ) {
                'memory_limit'        => '256M',
                'upload_max_filesize' => '8M',
                'post_max_size'       => '8M',
                default               => '',
            },
            static fn ( string $extension ): bool => true
        );

        $this->assertFalse( $eventLog->has( 'runtime.limits_low' ) );
    }

    public function test_a_missing_gd_extension_is_reported(): void {
        $eventLog = new InMemoryEventLog();

        MigrationRunner::checkRuntimeLimits(
            $eventLog,
            static fn ( string $key ): string => match ( $key ) {
                'memory_limit'        => '256M',
                'upload_max_filesize' => '8M',
                'post_max_size'       => '8M',
                default               => '',
            },
            static fn ( string $extension ): bool => 'gd' !== $extension
        );

        $this->assertTrue( $eventLog->has( 'runtime.limits_low' ) );
        $problems = $eventLog->last()['contexto']['problems'];
        $this->assertNotEmpty( array_filter( $problems, static fn ( $p ) => str_contains( $p, 'gd' ) ) );
    }

    public function test_missing_exif_and_fileinfo_are_both_reported(): void {
        $eventLog = new InMemoryEventLog();

        MigrationRunner::checkRuntimeLimits(
            $eventLog,
            static fn ( string $key ): string => match ( $key ) {
                'memory_limit'        => '256M',
                'upload_max_filesize' => '8M',
                'post_max_size'       => '8M',
                default               => '',
            },
            static fn ( string $extension ): bool => ! in_array( $extension, [ 'exif', 'fileinfo' ], true )
        );

        $problems = $eventLog->last()['contexto']['problems'];
        $this->assertNotEmpty( array_filter( $problems, static fn ( $p ) => str_contains( $p, 'exif' ) ) );
        $this->assertNotEmpty( array_filter( $problems, static fn ( $p ) => str_contains( $p, 'fileinfo' ) ) );
    }

    public function test_a_missing_extension_never_throws_and_defaults_to_the_real_extension_loaded(): void {
        // No $extensionLoadedFn injected — must fall back to the real
        // extension_loaded(), and never throw regardless of this
        // environment's actual extensions.
        $eventLog = new InMemoryEventLog();

        MigrationRunner::checkRuntimeLimits( $eventLog );

        $this->addToAssertionCount( 1 ); // Reaching here without a fatal is the assertion.
    }

    public function test_run_never_throws_even_when_runtime_limits_are_low(): void {
        // run() reads the REAL php.ini via the default ini_get-backed reader —
        // this only pins that run() completes and updates the version option
        // regardless of whatever this environment's actual limits are.
        MigrationRunner::run( new InMemoryEventLog() );

        $this->assertSame( ENTRE_REDES_CREDENCIAL_VERSION, get_option( 'credencial_db_version' ) );
    }
}

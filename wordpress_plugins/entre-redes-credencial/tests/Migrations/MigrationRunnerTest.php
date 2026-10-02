<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Migrations;

use EntreRedes\Credencial\Migrations\InitialSchema;
use EntreRedes\Credencial\Migrations\MigrationRunner;
use EntreRedes\Credencial\Observability\InMemoryEventLog;
use EntreRedes\Credencial\Tests\Support\RecordingWpdb;
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

    // -------------------------------------------------------------------------
    // Migration 0.1.0 -> 0.2.0 (design rev 9, decision
    // `credencial/foto-desde-featured-image`): the retired `_credencial_sha256`
    // meta is deleted exactly once, gated on the PERSISTED version being below
    // the reserved 0.2.0 slot — never on ENTRE_REDES_CREDENCIAL_VERSION itself,
    // so this stays correct even after a future 0.3.0 bump.
    // -------------------------------------------------------------------------

    public function test_run_from_0_1_0_deletes_the_legacy_sha256_meta_and_is_idempotent(): void {
        update_option( 'credencial_db_version', '0.1.0' );
        update_post_meta( 7, '_credencial_sha256', 'deadbeef' );
        update_post_meta( 42, '_credencial_sha256', 'cafef00d' );

        MigrationRunner::run( new InMemoryEventLog() );

        $this->assertSame( ENTRE_REDES_CREDENCIAL_VERSION, get_option( 'credencial_db_version' ) );
        $this->assertSame( '', get_post_meta( 7, '_credencial_sha256', true ) );
        $this->assertSame( '', get_post_meta( 42, '_credencial_sha256', true ) );

        // Second run (idempotent): nothing left to delete, no error, version stays put.
        MigrationRunner::run( new InMemoryEventLog() );
        $this->assertSame( ENTRE_REDES_CREDENCIAL_VERSION, get_option( 'credencial_db_version' ) );
    }

    public function test_run_already_at_0_2_0_never_touches_the_legacy_meta_function_again(): void {
        update_option( 'credencial_db_version', '0.2.0' );
        update_post_meta( 7, '_credencial_sha256', 'should-survive' );

        MigrationRunner::run( new InMemoryEventLog() );

        // The gate is < 0.2.0; already-at-0.2.0 must not re-run the one-time
        // cleanup (harmless if it did, since the key is already gone in
        // production, but the gate's OWN correctness is what this pins).
        $this->assertSame( 'should-survive', get_post_meta( 7, '_credencial_sha256', true ) );
    }

    // -------------------------------------------------------------------------
    // InitialSchema::dropLegacyPhotoSha256Column() — information_schema-gated,
    // exercised via a RecordingWpdb since the SQLite shim has no
    // information_schema at all (see InitialSchema's own docblock).
    // -------------------------------------------------------------------------

    public function test_drop_legacy_column_issues_exactly_one_drop_when_the_probe_reports_the_column_exists(): void {
        $recording = new RecordingWpdb( [ 'photo_sha256' => 1 ] );
        $original  = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $recording;

        try {
            InitialSchema::up();
        } finally {
            $GLOBALS['wpdb'] = $original;
        }

        $drops = $recording->queriesMatching( '/ALTER TABLE .*DROP COLUMN photo_sha256/i' );
        $this->assertCount( 1, $drops );
    }

    public function test_drop_legacy_column_issues_no_drop_when_the_probe_reports_zero(): void {
        $recording = new RecordingWpdb( [ 'photo_sha256' => 0 ] );
        $original  = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $recording;

        try {
            InitialSchema::up();
        } finally {
            $GLOBALS['wpdb'] = $original;
        }

        $drops = $recording->queriesMatching( '/ALTER TABLE .*DROP COLUMN photo_sha256/i' );
        $this->assertCount( 0, $drops );
    }

    public function test_drop_legacy_column_issues_no_drop_when_the_probe_returns_null(): void {
        $recording = new RecordingWpdb( [ 'photo_sha256' => null ] );
        $original  = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $recording;

        try {
            InitialSchema::up();
        } finally {
            $GLOBALS['wpdb'] = $original;
        }

        $drops = $recording->queriesMatching( '/ALTER TABLE .*DROP COLUMN photo_sha256/i' );
        $this->assertCount( 0, $drops );
    }

    public function test_drop_legacy_column_alter_failure_never_throws(): void {
        $recording = new RecordingWpdb( [ 'photo_sha256' => 1 ], forceAlterFailure: true );
        $original  = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $recording;

        try {
            InitialSchema::up();
            $this->addToAssertionCount( 1 ); // Reaching here without a fatal is the assertion.
        } finally {
            $GLOBALS['wpdb'] = $original;
        }
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Credencial;

use EntreRedes\Credencial\Credencial\Exception\IssuanceResolutionFailedException;
use EntreRedes\Credencial\Credencial\IssuanceRepository;
use EntreRedes\Credencial\Migrations\InitialSchema;
use EntreRedes\Credencial\Observability\InMemoryEventLog;
use EntreRedes\Credencial\Tests\Support\FaultInjectingWpdb;
use PHPUnit\Framework\TestCase;

/**
 * IssuanceRepository::resolve() — design D4. Two groups of tests:
 *
 *   - Against the SHARED global $wpdb SQLite shim (same convention as
 *     entre-redes-cambios's PlazaRepositoryTest): id stability, rotation
 *     triggers, and the "0-row rotation re-selects" concurrency property —
 *     all reachable with a healthy database.
 *   - Against a throwaway FaultInjectingWpdb: the two failure branches a
 *     healthy SQLite database can never produce on its own (a write that
 *     fails outright; a row that vanishes between insert and select).
 */
class IssuanceRepositoryTest extends TestCase {

    private const NOW = 1_800_000_000; // arbitrary fixed instant (2027-01-15ish UTC)

    private IssuanceRepository $repo;
    private InMemoryEventLog $eventLog;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_issuance" );

        $this->eventLog = new InMemoryEventLog();
        $this->repo     = new IssuanceRepository( $wpdb, $this->eventLog );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_issuance" );
    }

    public function test_creates_a_new_row_when_none_exists(): void {
        $row = $this->repo->resolve( 7, 100, 'sha-a', self::NOW );

        $this->assertSame( 7, (int) $row['player_id'] );
        $this->assertSame( 100, (int) $row['user_id'] );
        $this->assertSame( 'sha-a', $row['photo_sha256'] );
        $this->assertNotEmpty( $row['credential_id'] );
    }

    public function test_id_is_stable_across_repeated_resolutions_with_no_changes(): void {
        $first  = $this->repo->resolve( 7, 100, 'sha-a', self::NOW );
        $second = $this->repo->resolve( 7, 100, 'sha-a', self::NOW + 30 );

        $this->assertSame( $first['credential_id'], $second['credential_id'] );
    }

    public function test_rotates_when_the_bound_user_changes_and_persists_the_new_live_values(): void {
        $first  = $this->repo->resolve( 7, 100, 'sha-a', self::NOW );
        $second = $this->repo->resolve( 7, 200, 'sha-a', self::NOW + 30 );

        $this->assertNotSame( $first['credential_id'], $second['credential_id'] );
        $this->assertSame( 200, (int) $second['user_id'] );

        // The next GET, with the SAME live values, must not rotate again —
        // design D4: "the persisted user and sha stop the next GET from
        // rotating again."
        $third = $this->repo->resolve( 7, 200, 'sha-a', self::NOW + 60 );
        $this->assertSame( $second['credential_id'], $third['credential_id'] );
    }

    public function test_rotates_when_the_approved_photo_sha_changes_and_persists_the_new_live_values(): void {
        $first  = $this->repo->resolve( 7, 100, 'sha-a', self::NOW );
        $second = $this->repo->resolve( 7, 100, 'sha-b', self::NOW + 30 );

        $this->assertNotSame( $first['credential_id'], $second['credential_id'] );
        $this->assertSame( 'sha-b', $second['photo_sha256'] );

        $third = $this->repo->resolve( 7, 100, 'sha-b', self::NOW + 60 );
        $this->assertSame( $second['credential_id'], $third['credential_id'] );
    }

    public function test_does_not_rotate_when_no_approved_photo_sha_exists_yet(): void {
        $first  = $this->repo->resolve( 7, 100, null, self::NOW );
        $second = $this->repo->resolve( 7, 100, null, self::NOW + 30 );

        $this->assertSame( $first['credential_id'], $second['credential_id'] );
    }

    public function test_zero_row_rotation_reselects_the_concurrently_persisted_id(): void {
        // This race (a concurrent writer committing its own rotation in the
        // exact window between THIS repository's SELECT and its own UPDATE)
        // cannot be reproduced against the plain shared $wpdb shim in a
        // single-threaded test — there is no window to land a write into.
        // FaultInjectingWpdb creates that window on purpose: see
        // runSqlBeforeNextQueryMatching()'s own docblock.
        $wpdb = new FaultInjectingWpdb();
        $wpdb->getPdo()->exec(
            'CREATE TABLE wp_credencial_issuance (
                player_id INTEGER PRIMARY KEY,
                credential_id TEXT,
                user_id INTEGER,
                photo_sha256 TEXT,
                minted_at TEXT,
                updated_at TEXT
            )'
        );
        $repo = new IssuanceRepository( $wpdb, new InMemoryEventLog() );

        $first = $repo->resolve( 7, 100, 'sha-a', self::NOW );

        // The concurrent winner's UPDATE lands right before OUR rotation
        // UPDATE runs — so our own `WHERE player_id = ? AND credential_id =
        // ?old` matches 0 rows. Design D4 requires the re-SELECT that always
        // follows to return the WINNER's row, never throw, never fabricate
        // our own answer.
        $wpdb->runSqlBeforeNextQueryMatching(
            '/UPDATE\s+wp_credencial_issuance\s+SET/i',
            "UPDATE wp_credencial_issuance SET credential_id = 'concurrent-winner-id', user_id = 999, photo_sha256 = 'sha-winner' WHERE player_id = 7"
        );

        $second = $repo->resolve( 7, 200, 'sha-c', self::NOW + 30 );

        $this->assertSame( 'concurrent-winner-id', $second['credential_id'] );
        $this->assertNotSame( $first['credential_id'], $second['credential_id'] );
    }

    public function test_ensure_row_write_failure_throws_and_records_an_event(): void {
        $wpdb = new FaultInjectingWpdb();
        $wpdb->getPdo()->exec(
            'CREATE TABLE wp_credencial_issuance (
                player_id INTEGER PRIMARY KEY,
                credential_id TEXT,
                user_id INTEGER,
                photo_sha256 TEXT,
                minted_at TEXT,
                updated_at TEXT
            )'
        );
        $wpdb->failNextQueryMatching( '/INSERT INTO wp_credencial_issuance/i', 'Deadlock found; try restarting transaction' );

        $eventLog = new InMemoryEventLog();
        $repo     = new IssuanceRepository( $wpdb, $eventLog );

        $this->expectException( IssuanceResolutionFailedException::class );

        try {
            $repo->resolve( 7, 100, 'sha-a', self::NOW );
        } finally {
            $this->assertTrue( $eventLog->has( 'issuance.resolve_failed' ) );
        }
    }

    public function test_no_row_after_insert_throws_and_records_an_event(): void {
        $wpdb = new FaultInjectingWpdb();
        $wpdb->getPdo()->exec(
            'CREATE TABLE wp_credencial_issuance (
                player_id INTEGER PRIMARY KEY,
                credential_id TEXT,
                user_id INTEGER,
                photo_sha256 TEXT,
                minted_at TEXT,
                updated_at TEXT
            )'
        );
        $wpdb->forceNextGetRowNullMatching( '/SELECT \* FROM wp_credencial_issuance/i' );

        $eventLog = new InMemoryEventLog();
        $repo     = new IssuanceRepository( $wpdb, $eventLog );

        $this->expectException( IssuanceResolutionFailedException::class );

        try {
            $repo->resolve( 7, 100, 'sha-a', self::NOW );
        } finally {
            $this->assertTrue( $eventLog->has( 'issuance.resolve_failed' ) );
        }
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Capitania;

use EntreRedes\Cambios\Capitania\CapitanRepository;
use EntreRedes\Cambios\Capitania\Exception\CapitanPersistenceException;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for CapitanRepository against the in-memory SQLite shim.
 *
 * NOTE — SQLite shim gap (see InitialSchema's class docblock): the dbDelta
 * shim drops every KEY/INDEX line, and the "at most one vigent captain per
 * team" rule was never a UNIQUE KEY to begin with (see
 * sqlCambiosCapitan()'s docblock for why). These tests assert that PROPERTY
 * directly — never two vigent rows for the same (season_id, team_id) —
 * rather than relying on any DB constraint.
 */
class CapitanRepositoryTest extends TestCase {

    private CapitanRepository $repo;
    private InMemoryEventLog $eventLog;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_capitan" );

        $this->eventLog = new InMemoryEventLog();
        $this->repo     = new CapitanRepository( $wpdb, $this->eventLog );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_capitan" );
    }

    private function countVigentesFor( int $seasonId, int $teamId ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_capitan
                  WHERE season_id = %d AND team_id = %d AND revocado_at IS NULL",
                $seasonId,
                $teamId
            )
        );
    }

    private function countRowsFor( int $seasonId, int $teamId ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_capitan
                  WHERE season_id = %d AND team_id = %d",
                $seasonId,
                $teamId
            )
        );
    }

    /**
     * Builds a \wpdb subclass that shares the SAME underlying PDO connection
     * as the real (shim) $wpdb — via reflection into the parent's private
     * $pdo property, rather than calling parent::__construct(), which would
     * instead open a second, empty, disconnected in-memory database — but
     * whose insert() or update() unconditionally returns false, exactly the
     * way a real wpdb reports a failed write: no exception, just false and
     * $wpdb->last_error (see wp-shim.php's wpdb::insert()/update()).
     */
    private function wpdbThatFailsOn( \wpdb $real, string $method ): \wpdb {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $real );

        return match ( $method ) {
            'insert' => new class( $pdo, $real->prefix ) extends \wpdb {
                public function __construct( \PDO $pdo, string $prefix ) {
                    $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                    $ref->setValue( $this, $pdo );
                    $this->prefix = $prefix;
                }

                public function insert( string $table, array $data, mixed $format = null ): int|false {
                    $this->last_error = 'simulated insert failure for test';
                    return false;
                }
            },
            'update' => new class( $pdo, $real->prefix ) extends \wpdb {
                public function __construct( \PDO $pdo, string $prefix ) {
                    $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                    $ref->setValue( $this, $pdo );
                    $this->prefix = $prefix;
                }

                public function update( string $table, array $data, array $where ): int|false {
                    $this->last_error = 'simulated update failure for test';
                    return false;
                }
            },
            default => throw new \InvalidArgumentException( "Unsupported method: {$method}" ),
        };
    }

    // -------------------------------------------------------------------------
    // designateCapitan — creates
    // -------------------------------------------------------------------------

    public function test_designate_captain_creates_a_vigent_row(): void {
        $id = $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-26 10:00:00' );

        $this->assertGreaterThan( 0, $id );
        $this->assertTrue( $this->repo->isCapitanVigente( 359, 100, 777 ) );
        $this->assertSame( 1, $this->countVigentesFor( 359, 100 ) );
    }

    // -------------------------------------------------------------------------
    // designateCapitan — changing captain revokes the previous one
    // -------------------------------------------------------------------------

    public function test_designate_captain_a_different_player_revokes_the_previous_captain(): void {
        $firstId  = $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-26 10:00:00' );
        $secondId = $this->repo->designateCapitan( 359, 100, 888, 7, '2026-09-27 10:00:00' );

        $this->assertNotSame( $firstId, $secondId );
        $this->assertFalse( $this->repo->isCapitanVigente( 359, 100, 777 ) );
        $this->assertTrue( $this->repo->isCapitanVigente( 359, 100, 888 ) );

        // THE property test: exactly one vigent row, never zero, never two.
        $this->assertSame( 1, $this->countVigentesFor( 359, 100 ) );
        $this->assertSame( 2, $this->countRowsFor( 359, 100 ), 'The revoked row must remain as history.' );
    }

    // -------------------------------------------------------------------------
    // designateCapitan — idempotent on the SAME player
    // -------------------------------------------------------------------------

    public function test_designate_captain_the_same_incumbent_player_is_idempotent(): void {
        $firstId  = $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-26 10:00:00' );
        $secondId = $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-27 10:00:00' );

        $this->assertSame( $firstId, $secondId, 'Re-designating the incumbent must not create a new row.' );
        $this->assertSame( 1, $this->countRowsFor( 359, 100 ), 'No revocation and no new row for the incumbent.' );
        $this->assertTrue( $this->repo->isCapitanVigente( 359, 100, 777 ) );
    }

    // -------------------------------------------------------------------------
    // designateCapitan — rollback on a failed wpdb write (BLOCKER fix)
    // -------------------------------------------------------------------------

    public function test_designate_captain_rolls_back_when_the_insert_fails(): void {
        global $wpdb;

        $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-26 10:00:00' );

        $failingWpdb = $this->wpdbThatFailsOn( $wpdb, 'insert' );
        $failingRepo = new CapitanRepository( $failingWpdb, new InMemoryEventLog() );

        try {
            $failingRepo->designateCapitan( 359, 100, 888, 7, '2026-09-27 10:00:00' );
            $this->fail( 'Expected CapitanPersistenceException.' );
        } catch ( CapitanPersistenceException $e ) {
            // expected — assert below, through the REAL repo, that the
            // transaction actually rolled back.
        }

        $this->assertTrue(
            $this->repo->isCapitanVigente( 359, 100, 777 ),
            'The previous captain must remain vigent when the insert of the new one failed.'
        );
        $this->assertSame(
            1,
            $this->countRowsFor( 359, 100 ),
            'No new row must have been committed when the insert failed.'
        );
    }

    public function test_designate_captain_rolls_back_when_the_revoke_update_fails(): void {
        global $wpdb;

        $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-26 10:00:00' );

        $failingWpdb = $this->wpdbThatFailsOn( $wpdb, 'update' );
        $failingRepo = new CapitanRepository( $failingWpdb, new InMemoryEventLog() );

        try {
            $failingRepo->designateCapitan( 359, 100, 888, 7, '2026-09-27 10:00:00' );
            $this->fail( 'Expected CapitanPersistenceException.' );
        } catch ( CapitanPersistenceException $e ) {
            // expected
        }

        $this->assertTrue(
            $this->repo->isCapitanVigente( 359, 100, 777 ),
            'The previous captain must remain vigent when revoking it failed.'
        );
        $this->assertSame(
            1,
            $this->countRowsFor( 359, 100 ),
            'The insert for the new captain must not have been committed either.'
        );
    }

    // -------------------------------------------------------------------------
    // revokeCaptain
    // -------------------------------------------------------------------------

    public function test_revoke_captain_clears_the_vigent_captain(): void {
        $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-26 10:00:00' );

        $result = $this->repo->revokeCapitan( 359, 100, '2026-09-27 10:00:00' );

        $this->assertTrue( $result );
        $this->assertNull( $this->repo->findCapitanVigente( 359, 100 ) );
        $this->assertFalse( $this->repo->isCapitanVigente( 359, 100, 777 ) );
    }

    public function test_revoke_captain_returns_false_when_there_is_nothing_to_revoke(): void {
        $this->assertFalse( $this->repo->revokeCapitan( 359, 100, '2026-09-27 10:00:00' ) );
    }

    // -------------------------------------------------------------------------
    // findCapitanVigente / isCapitanVigente
    // -------------------------------------------------------------------------

    public function test_find_capitan_vigente_returns_null_when_no_captain_was_ever_designated(): void {
        $this->assertNull( $this->repo->findCapitanVigente( 359, 100 ) );
    }

    public function test_find_capitan_vigente_returns_null_after_a_revoke(): void {
        $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-26 10:00:00' );
        $this->repo->revokeCapitan( 359, 100, '2026-09-27 10:00:00' );

        $this->assertNull( $this->repo->findCapitanVigente( 359, 100 ) );
    }

    public function test_is_capitan_vigente_is_false_for_a_different_team(): void {
        $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-26 10:00:00' );

        $this->assertFalse( $this->repo->isCapitanVigente( 359, 200, 777 ) );
    }

    // -------------------------------------------------------------------------
    // listEquiposByCapitan
    // -------------------------------------------------------------------------

    public function test_list_equipos_by_capitan_returns_the_team_this_player_captains(): void {
        $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-26 10:00:00' );

        $this->assertSame( [ 100 ], $this->repo->listEquiposByCapitan( 359, 777 ) );
    }

    public function test_list_equipos_by_capitan_is_empty_when_the_player_captains_nothing(): void {
        $this->assertSame( [], $this->repo->listEquiposByCapitan( 359, 777 ) );
    }

    public function test_list_equipos_by_capitan_excludes_a_revoked_team(): void {
        $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-26 10:00:00' );
        $this->repo->revokeCapitan( 359, 100, '2026-09-27 10:00:00' );

        $this->assertSame( [], $this->repo->listEquiposByCapitan( 359, 777 ) );
    }

    /**
     * THE fix this method needed: before it, a failed read degraded via
     * `$rows ?: []` into "captains nothing" — indistinguishable from a real
     * captain whose read simply failed. This proves it now throws instead,
     * so `Rest\CapitanController`'s `/cambios/mis-equipos` endpoint can
     * never mistake a broken query for "no sos capitán de ningún equipo".
     */
    public function test_list_equipos_by_capitan_throws_when_the_query_fails(): void {
        global $wpdb;

        $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-26 10:00:00' );

        $failingWpdb = new class( ( new \ReflectionProperty( \wpdb::class, 'pdo' ) )->getValue( $wpdb ), $wpdb->prefix ) extends \wpdb {
            public function __construct( \PDO $pdo, string $prefix ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix = $prefix;
            }

            public function get_results( string $sql, string $output = OBJECT ): array {
                if ( str_contains( $sql, 'cambios_capitan' ) && str_contains( $sql, 'player_id' ) ) {
                    $this->last_error = 'simulated get_results failure for test';
                    return [];
                }

                return parent::get_results( $sql, $output );
            }
        };

        $failingEventLog = new InMemoryEventLog();
        $failingRepo     = new CapitanRepository( $failingWpdb, $failingEventLog );

        $this->expectException( \RuntimeException::class );

        try {
            $failingRepo->listEquiposByCapitan( 359, 777 );
        } finally {
            $this->assertTrue( $failingEventLog->has( 'lectura.fallida' ) );
        }
    }

    // -------------------------------------------------------------------------
    // THE property: never two vigent rows for the same (season, team), across
    // a longer sequence of designations.
    // -------------------------------------------------------------------------

    public function test_never_two_vigent_rows_for_the_same_pair_across_several_designations(): void {
        $this->repo->designateCapitan( 359, 100, 1, null, '2026-09-01 10:00:00' );
        $this->repo->designateCapitan( 359, 100, 2, null, '2026-09-08 10:00:00' );
        $this->repo->designateCapitan( 359, 100, 2, null, '2026-09-09 10:00:00' ); // idempotent no-op
        $this->repo->designateCapitan( 359, 100, 3, null, '2026-09-15 10:00:00' );

        $this->assertSame( 1, $this->countVigentesFor( 359, 100 ) );
        $this->assertTrue( $this->repo->isCapitanVigente( 359, 100, 3 ) );

        global $wpdb;
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_capitan WHERE season_id = %d AND team_id = %d",
                359,
                100
            )
        );
        $this->assertSame( 3, $total, 'Every designation must be preserved as history.' );
    }

    public function test_designations_are_scoped_per_season(): void {
        $this->repo->designateCapitan( 359, 100, 777, null, '2026-09-01 10:00:00' );
        $this->repo->designateCapitan( 360, 100, 888, null, '2026-09-01 10:00:00' );

        $this->assertTrue( $this->repo->isCapitanVigente( 359, 100, 777 ) );
        $this->assertTrue( $this->repo->isCapitanVigente( 360, 100, 888 ) );
        $this->assertFalse( $this->repo->isCapitanVigente( 359, 100, 888 ) );
    }

    // -------------------------------------------------------------------------
    // EventLog — audit events on every successful write
    // -------------------------------------------------------------------------

    public function test_designate_captain_records_an_audit_event(): void {
        $id = $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-26 10:00:00' );

        $this->assertTrue( $this->eventLog->has( 'capitan.designado' ) );
        $event = $this->eventLog->last();
        $this->assertSame( 'capitan.designado', $event['evento'] );
        $this->assertSame( $id, $event['contexto']['capitan_id'] );
        $this->assertSame( 359, $event['contexto']['season_id'] );
        $this->assertSame( 100, $event['contexto']['team_id'] );
        $this->assertSame( 777, $event['contexto']['player_id'] );
        $this->assertNull( $event['contexto']['reemplazo_a'] );
    }

    public function test_designate_captain_records_who_it_replaced(): void {
        $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-26 10:00:00' );
        $this->repo->designateCapitan( 359, 100, 888, 7, '2026-09-27 10:00:00' );

        $event = $this->eventLog->last();
        $this->assertSame( 'capitan.designado', $event['evento'] );
        $this->assertSame( 888, $event['contexto']['player_id'] );
        $this->assertSame( 777, $event['contexto']['reemplazo_a'] );
    }

    public function test_designate_captain_records_no_event_on_the_idempotent_no_op(): void {
        global $wpdb;

        $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-26 10:00:00' );
        $this->eventLog = new InMemoryEventLog();
        $this->repo     = new CapitanRepository( $wpdb, $this->eventLog );

        $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-27 10:00:00' );

        $this->assertFalse( $this->eventLog->has( 'capitan.designado' ), 'No write happened on the incumbent no-op.' );
    }

    public function test_revoke_captain_records_an_audit_event(): void {
        $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-26 10:00:00' );

        $this->repo->revokeCapitan( 359, 100, '2026-09-27 10:00:00' );

        $this->assertTrue( $this->eventLog->has( 'capitan.revocado' ) );
        $event = $this->eventLog->last();
        $this->assertSame( 359, $event['contexto']['season_id'] );
        $this->assertSame( 100, $event['contexto']['team_id'] );
        $this->assertSame( 777, $event['contexto']['player_id'] );
    }

    public function test_revoke_captain_records_no_event_when_there_is_nothing_to_revoke(): void {
        $this->repo->revokeCapitan( 359, 100, '2026-09-27 10:00:00' );

        $this->assertFalse( $this->eventLog->has( 'capitan.revocado' ) );
    }

    // -------------------------------------------------------------------------
    // EventLog — failure events recorded BEFORE the exception propagates
    // -------------------------------------------------------------------------

    public function test_designate_captain_records_the_failure_event_before_throwing_when_the_insert_fails(): void {
        global $wpdb;

        $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-26 10:00:00' );

        $failingWpdb     = $this->wpdbThatFailsOn( $wpdb, 'insert' );
        $failingEventLog = new InMemoryEventLog();
        $failingRepo     = new CapitanRepository( $failingWpdb, $failingEventLog );

        try {
            $failingRepo->designateCapitan( 359, 100, 888, 7, '2026-09-27 10:00:00' );
            $this->fail( 'Expected CapitanPersistenceException.' );
        } catch ( CapitanPersistenceException $e ) {
            // expected — the event must already exist even though this propagated.
        }

        $this->assertTrue( $failingEventLog->has( 'escritura.fallida' ) );
        $last = $failingEventLog->last();
        $this->assertSame( 'designateCapitan', $last['contexto']['operacion'] );
        $this->assertNotNull( $last['contexto']['last_error'] ?? null );
    }

    public function test_designate_captain_records_the_failure_event_before_throwing_when_the_revoke_fails(): void {
        global $wpdb;

        $this->repo->designateCapitan( 359, 100, 777, 7, '2026-09-26 10:00:00' );

        $failingWpdb     = $this->wpdbThatFailsOn( $wpdb, 'update' );
        $failingEventLog = new InMemoryEventLog();
        $failingRepo     = new CapitanRepository( $failingWpdb, $failingEventLog );

        try {
            $failingRepo->designateCapitan( 359, 100, 888, 7, '2026-09-27 10:00:00' );
            $this->fail( 'Expected CapitanPersistenceException.' );
        } catch ( CapitanPersistenceException $e ) {
            // expected
        }

        $this->assertTrue( $failingEventLog->has( 'escritura.fallida' ) );
    }
}

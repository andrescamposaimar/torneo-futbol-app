<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Calendario\Cron;

use EntreRedes\Cambios\Calendario\Cron\SeedCalendarioCron;
use EntreRedes\Cambios\Calendario\PartidosApiClient;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the production caller `Calendario\SeedTemporadaService` never
 * had. Exercises `execute()` directly against the SQLite shim, injecting a
 * `PartidosApiClient` built on a stub `$httpGetFn` — exactly
 * `PartidosApiClientTest`'s own pattern — never the static `run()`
 * entrypoint, which needs a real WP-Cron/REST runtime (see that method's own
 * docblock, same convention as entre-redes-prode's
 * `Cron\BackfillMatchMetaCron`).
 */
class SeedCalendarioCronTest extends TestCase {

    private const LIGAS = [
        [ 'id' => 373, 'name' => '2026 - Apertura Zona A', 'seasons' => [ 359 ] ],
        [ 'id' => 374, 'name' => '2026 - Apertura Zona B', 'seasons' => [ 359 ] ],
        [ 'id' => 375, 'name' => '2026 - Apertura Zona C', 'seasons' => [ 359 ] ],
    ];

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_fecha_partido" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );

        $GLOBALS['_prode_test_cron_schedule'] = [];
        $GLOBALS['_prode_test_transients']    = [];
    }

    protected function tearDown(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_fecha_partido" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );

        $GLOBALS['_prode_test_cron_schedule'] = [];
        $GLOBALS['_prode_test_transients']    = [];
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * Builds a PartidosApiClient whose stub $httpGetFn returns the given raw
     * `/partidos` items (already-played) and `/partidos-programados` items
     * (future), plus the fixed self::LIGAS index for `/ligas` — mirrors
     * PartidosApiClientTest's own client() helper.
     *
     * @param array<int, array<string, mixed>> $partidosItems
     * @param array<int, array<string, mixed>> $programadosItems
     */
    private function apiClient( array $partidosItems, array $programadosItems = [] ): PartidosApiClient {
        $httpGetFn = static function ( string $url ) use ( $partidosItems, $programadosItems ): array {
            $parts = parse_url( $url );
            $path  = $parts['path'] ?? '';

            if ( str_ends_with( $path, '/partidos-programados' ) ) {
                return [ 'items' => $programadosItems, 'total_pages' => 1 ];
            }
            if ( str_ends_with( $path, '/partidos' ) ) {
                return [ 'items' => $partidosItems, 'total_pages' => 1 ];
            }
            if ( str_ends_with( $path, '/ligas' ) ) {
                return self::LIGAS;
            }

            return [];
        };

        return new PartidosApiClient( $httpGetFn, 'https://example.test/v1' );
    }

    /**
     * A client whose every call throws immediately — simulates a network
     * timeout or a malformed payload at the very first REST call
     * (fetchLigasIndex()), before anything downstream could possibly write.
     */
    private function throwingApiClient( string $message = 'HTTP 500' ): PartidosApiClient {
        return new PartidosApiClient(
            static function ( string $url ) use ( $message ): array {
                throw new \RuntimeException( $message );
            },
            'https://example.test/v1'
        );
    }

    /**
     * One matchday of 15 partidos (5 per zona) across the 3 fixture ligas.
     *
     * @param array<int, int> $ligaIds
     * @return array<int, array{id:int, fecha:string, hora:string, liga:string}>
     */
    private function buildMatchdayItems( string $playDate, array $ligaIds, int $matchIdStart ): array {
        $ligaNameById = array_column( self::LIGAS, 'name', 'id' );

        $items   = [];
        $matchId = $matchIdStart;

        foreach ( $ligaIds as $ligaId ) {
            for ( $i = 0; $i < 5; $i++ ) {
                $items[] = [
                    'id'    => $matchId++,
                    'fecha' => $playDate,
                    'hora'  => '1' . $i . ':00',
                    'liga'  => $ligaNameById[ $ligaId ],
                ];
            }
        }

        return $items;
    }

    // -------------------------------------------------------------------------
    // execute(): the happy path
    // -------------------------------------------------------------------------

    public function test_seeds_when_the_table_is_empty(): void {
        global $wpdb;

        $items    = $this->buildMatchdayItems( '2026-05-30', [ 373, 374, 375 ], 1000 );
        $eventLog = new InMemoryEventLog();
        $settings = new Settings( $wpdb, $eventLog );

        $result = ( new SeedCalendarioCron() )->execute( $wpdb, $eventLog, $settings, $this->apiClient( $items ) );

        $this->assertNotNull( $result );
        $this->assertCount( 1, $result );
        $this->assertSame( 'creada', $result[0]['status'] );
        $this->assertSame( 'Apertura', $result[0]['torneo_label'] );

        $p = $wpdb->prefix;
        $this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cambios_fecha" ) );
        $this->assertSame( 15, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cambios_fecha_partido" ) );

        $this->assertTrue( $eventLog->has( 'calendario.seed_exitoso' ) );
        $this->assertFalse( $eventLog->has( 'calendario.seed_fallido' ) );
        $this->assertFalse( $eventLog->has( 'calendario.seed_bloqueado' ) );
    }

    // -------------------------------------------------------------------------
    // execute(): idempotency — the same guarantee the dry run demonstrates,
    // now pinned by a test.
    // -------------------------------------------------------------------------

    public function test_a_second_run_with_the_same_fixture_changes_nothing(): void {
        global $wpdb;

        $items    = $this->buildMatchdayItems( '2026-05-30', [ 373, 374, 375 ], 2000 );
        $settings = new Settings( $wpdb, new InMemoryEventLog() );

        ( new SeedCalendarioCron() )->execute( $wpdb, new InMemoryEventLog(), $settings, $this->apiClient( $items ) );

        $p      = $wpdb->prefix;
        $before = $wpdb->get_results( "SELECT id, orden, numero_en_torneo FROM {$p}cambios_fecha ORDER BY id", ARRAY_A );
        $this->assertNotEmpty( $before );

        $eventLog2 = new InMemoryEventLog();
        $result2   = ( new SeedCalendarioCron() )->execute( $wpdb, $eventLog2, $settings, $this->apiClient( $items ) );

        $after = $wpdb->get_results( "SELECT id, orden, numero_en_torneo FROM {$p}cambios_fecha ORDER BY id", ARRAY_A );

        $this->assertSame( $before, $after, 'A second identical run must never change an existing fecha_id or its orden.' );
        $this->assertSame( 'sin_cambios', $result2[0]['status'] );
        $this->assertTrue( $eventLog2->has( 'calendario.seed_exitoso' ) );
    }

    // -------------------------------------------------------------------------
    // execute(): a failing fetch must never half-write the calendar
    // -------------------------------------------------------------------------

    public function test_a_failing_fetch_leaves_the_table_exactly_as_it_was_and_records_the_failure(): void {
        global $wpdb;

        $settings = new Settings( $wpdb, new InMemoryEventLog() );

        // Seed once successfully so there is a REAL calendar to leave
        // untouched — not merely an empty table, which a bug could satisfy
        // by accident.
        $items = $this->buildMatchdayItems( '2026-05-30', [ 373, 374, 375 ], 3000 );
        ( new SeedCalendarioCron() )->execute( $wpdb, new InMemoryEventLog(), $settings, $this->apiClient( $items ) );

        $p               = $wpdb->prefix;
        $fechasBefore    = $wpdb->get_results( "SELECT * FROM {$p}cambios_fecha ORDER BY id", ARRAY_A );
        $partidosBefore  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cambios_fecha_partido" );
        $this->assertNotEmpty( $fechasBefore );

        $eventLog = new InMemoryEventLog();
        $result   = ( new SeedCalendarioCron() )->execute( $wpdb, $eventLog, $settings, $this->throwingApiClient( 'HTTP 500 al leer /ligas' ) );

        $this->assertNull( $result );

        $fechasAfter   = $wpdb->get_results( "SELECT * FROM {$p}cambios_fecha ORDER BY id", ARRAY_A );
        $partidosAfter = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cambios_fecha_partido" );

        $this->assertSame( $fechasBefore, $fechasAfter, 'A failed fetch must never touch cambios_fecha at all.' );
        $this->assertSame( $partidosBefore, $partidosAfter );

        $this->assertTrue( $eventLog->has( 'calendario.seed_fallido' ) );
        $last = $eventLog->last();
        $this->assertSame( 'calendario.seed_fallido', $last['evento'] );
        $this->assertStringContainsString( 'HTTP 500 al leer /ligas', $last['contexto']['error'] );
    }

    // -------------------------------------------------------------------------
    // execute(): the overlap lock
    // -------------------------------------------------------------------------

    public function test_the_lock_prevents_a_second_overlapping_run(): void {
        global $wpdb;

        $settings = new Settings( $wpdb, new InMemoryEventLog() );

        // Simulates a run already in progress — exactly what a second
        // WP-Cron firing, or an operator's manual --apply overlapping the
        // cron, would find.
        set_transient( SeedCalendarioCron::LOCK_KEY, time(), 600 );

        $items    = $this->buildMatchdayItems( '2026-05-30', [ 373, 374, 375 ], 4000 );
        $apiClient = $this->apiClient( $items );

        $eventLog = new InMemoryEventLog();
        $result   = ( new SeedCalendarioCron() )->execute( $wpdb, $eventLog, $settings, $apiClient );

        $this->assertNull( $result );
        $this->assertTrue( $eventLog->has( 'calendario.seed_bloqueado' ) );

        // Never even fetched: PartidosApiClient::stats() only moves away
        // from its zero-value construction defaults once fetchPartidos() /
        // fetchProgramados() actually run.
        $stats = $apiClient->stats();
        $this->assertSame( 0, $stats['publish'] );
        $this->assertSame( 0, $stats['future'] );

        $p = $wpdb->prefix;
        $this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cambios_fecha" ) );

        // Once released, a normal run proceeds exactly as if nothing had
        // happened.
        delete_transient( SeedCalendarioCron::LOCK_KEY );

        $eventLog2 = new InMemoryEventLog();
        $result2   = ( new SeedCalendarioCron() )->execute( $wpdb, $eventLog2, $settings, $this->apiClient( $items ) );

        $this->assertNotNull( $result2 );
        $this->assertTrue( $eventLog2->has( 'calendario.seed_exitoso' ) );
    }

    // -------------------------------------------------------------------------
    // schedule() / unschedule()
    // -------------------------------------------------------------------------

    public function test_schedule_registers_a_daily_event_idempotently_and_unschedule_clears_it(): void {
        $this->assertFalse( wp_next_scheduled( SeedCalendarioCron::HOOK ), 'Precondition: nothing scheduled yet.' );

        SeedCalendarioCron::schedule();
        $first = wp_next_scheduled( SeedCalendarioCron::HOOK );
        $this->assertIsInt( $first );

        // Idempotent: a second call while an event is already registered
        // must not reschedule it (the wp_next_scheduled() guard inside
        // schedule() itself) — this is what lets Plugin::boot()'s safety net
        // call it on every request without ever drifting the fire time.
        SeedCalendarioCron::schedule();
        $this->assertSame( $first, wp_next_scheduled( SeedCalendarioCron::HOOK ) );

        SeedCalendarioCron::unschedule();
        $this->assertFalse( wp_next_scheduled( SeedCalendarioCron::HOOK ) );
    }
}

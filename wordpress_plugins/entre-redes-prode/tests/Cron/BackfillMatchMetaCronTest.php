<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Cron;

use EntreRedes\Prode\Cron\BackfillMatchMetaCron;
use EntreRedes\Prode\Fecha\BackfillMatchMetaService;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Tests the injectable execute() seam of BackfillMatchMetaCron (ADR-G0-7).
 *
 * The static run() entrypoint is not tested directly (it needs a real WP cron
 * runtime). execute() accepts the service so the delegation + observability hook
 * are fully testable against the SQLite shim.
 */
class BackfillMatchMetaCronTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );

        $GLOBALS['_prode_test_actions']['prode_backfill_match_meta_ran'] = 0;
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_settings" );
        InitialSchema::up();
    }

    public function test_execute_delegates_to_service_and_fires_hook(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'prode_fecha_matches', [
            'fecha_id'      => 1,
            'match_id'      => 22608,
            'match_kickoff' => '2026-06-06 13:45:00',
        ] );

        $service = new BackfillMatchMetaService(
            $wpdb,
            static fn( string $date ): array => [
                [ 'id' => 22608, 'equipo_local' => 'Home', 'equipo_visitante' => 'Away' ],
            ]
        );

        $updated = ( new BackfillMatchMetaCron() )->execute( $service );

        $this->assertSame( 1, $updated );
        $this->assertSame( 1, $GLOBALS['_prode_test_actions']['prode_backfill_match_meta_ran'] );
    }
}

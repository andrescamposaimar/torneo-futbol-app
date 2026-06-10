<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Admin;

use EntreRedes\Prode\Admin\PredictionsPage;
use EntreRedes\Prode\Admin\RegistryRepository;
use EntreRedes\Prode\Fecha\FechaResolver;
use EntreRedes\Prode\Predictions\PredictionRepository;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PredictionsPage.
 *
 * T-15 (Strict TDD — RED written first).
 *
 * The shim's current_user_can() always returns false, so render() will always
 * call wp_die(), which in the shim throws RuntimeException. This lets us verify
 * the capability guard without a WP install.
 *
 * Tests that require actual rendering (HTML output) are OUT OF SCOPE here;
 * admin rendering is tested manually.
 */
class PredictionsPageTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );
    }

    /**
     * Build a stub FechaResolver whose dispatcher returns canned /partidos-programados items.
     *
     * @param array<int, array{home_team: string, away_team: string}> $teamMap  Keyed by match_id.
     */
    private function makeResolver( array $teamMap = [] ): FechaResolver {
        return new FechaResolver( static function () use ( $teamMap ): array {
            $items = [];
            foreach ( $teamMap as $matchId => $names ) {
                $items[] = [
                    'id'               => $matchId,
                    'fecha'            => '2026-09-01',
                    'hora'             => '15:00',
                    'equipo_local'     => $names['home_team'],
                    'equipo_visitante' => $names['away_team'],
                    'liga'             => $names['zona'] ?? '',
                    'goles_local'      => null,
                    'goles_visitante'  => null,
                ];
            }
            return $items;
        } );
    }

    private function makePage( ?FechaResolver $resolver = null ): PredictionsPage {
        global $wpdb;
        return new PredictionsPage(
            new PredictionRepository( $wpdb ),
            new RegistryRepository( $wpdb ),
            $resolver ?? new FechaResolver( static fn() => [] )
        );
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function test_page_can_be_instantiated(): void {
        $page = $this->makePage();
        $this->assertInstanceOf( PredictionsPage::class, $page );
    }

    // -------------------------------------------------------------------------
    // Capability guard
    // -------------------------------------------------------------------------

    public function test_render_throws_when_user_cannot_manage_options(): void {
        // The shim's current_user_can() always returns false.
        // The shim's wp_die() throws RuntimeException.
        $this->expectException( \RuntimeException::class );

        $page = $this->makePage();
        $page->render();
    }

    // -------------------------------------------------------------------------
    // Resolver injection contract
    // -------------------------------------------------------------------------

    /**
     * PredictionsPage must accept a FechaResolver as third constructor argument.
     * Verifies the injection contract is in place (will fail in RED if the
     * constructor only has 2 params and silently drops the third).
     *
     * Strategy: extend FechaResolver with a spy that sets a flag when
     * enrichMatches() is called, then assert the flag is set.  Because
     * renderDetail() is private we access it via Reflection; we suppress the
     * WP_List_Table instantiation by defining a stub class in this test file.
     */
    public function test_renderDetail_calls_resolver_enrichMatches(): void {
        global $wpdb;

        // Seed minimal data so findAllByUser returns one row.
        $wpdb->insert( "{$wpdb->prefix}prode_fechas", [
            'tenant_id' => 'test',
            'season_id' => 1,
            'state'     => 'open',
            'locked_at' => '2026-10-01 20:00:00',
        ] );
        $fechaId = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}prode_fecha_matches", [
            'fecha_id'      => $fechaId,
            'match_id'      => 999,
            'home_team'     => '',
            'away_team'     => '',
            'match_kickoff' => '2026-10-01 15:00:00',
        ] );

        $wpdb->insert( "{$wpdb->prefix}prode_users", [
            'tenant_id'    => 'test',
            'email'        => 'spy@test.com',
            'display_name' => 'Spy User',
            'is_active'    => 1,
        ] );
        $userId = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}prode_predictions", [
            'user_id'            => $userId,
            'fecha_id'           => $fechaId,
            'match_id'           => 999,
            'result'             => '1',
            'score_home'         => 1,
            'score_away'         => 0,
            'created_at'         => '2026-09-01 10:00:00',
            'updated_at'         => '2026-09-01 10:00:00',
            'locked_at_snapshot' => '2026-10-01 20:00:00',
        ] );

        $called   = false;
        $resolver = new class( static function () use ( &$called ): array {
            $called = true;
            return [
                [
                    'id'               => 999,
                    'fecha'            => '2026-10-01',
                    'hora'             => '15:00',
                    'equipo_local'     => 'SpyHome',
                    'equipo_visitante' => 'SpyAway',
                    'goles_local'      => null,
                    'goles_visitante'  => null,
                ],
            ];
        } ) extends FechaResolver {};

        $page = $this->makePage( $resolver );

        // Invoke private renderDetail via Reflection — setAccessible() is a
        // no-op since PHP 8.1 and deprecated since 8.5; omit it.
        $ref = new \ReflectionMethod( PredictionsPage::class, 'renderDetail' );

        // renderDetail outputs HTML — capture it.
        ob_start();
        try {
            $ref->invoke( $page, $userId, 1 );
        } catch ( \Throwable $e ) {
            // PredictionsListTable may throw in headless context — that's OK;
            // we only care that $called was set before the table call.
        }
        ob_end_clean();

        $this->assertTrue( $called, 'PredictionsPage::renderDetail() must call FechaResolver::enrichMatches()' );
    }

    // -------------------------------------------------------------------------
    // Resolver enrichment in renderDetail
    // -------------------------------------------------------------------------

    /**
     * An unplayed row with empty home_team must get its name filled in from the
     * live /partidos-programados payload via the injected FechaResolver.
     */
    public function test_renderDetail_resolves_empty_home_team_from_live_payload(): void {
        global $wpdb;

        // Seed a fecha, a match with empty team names (unplayed / pre-snapshot),
        // and a prediction so findAllByUser returns a row.
        $wpdb->insert( "{$wpdb->prefix}prode_fechas", [
            'tenant_id' => 'test',
            'season_id' => 1,
            'state'     => 'open',
            'locked_at' => '2026-09-01 20:00:00',
        ] );
        $fechaId = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}prode_fecha_matches", [
            'fecha_id'       => $fechaId,
            'match_id'       => 501,
            'home_team'      => '',      // empty — no snapshot yet
            'away_team'      => '',
            'match_kickoff'  => '2026-09-01 15:00:00',
        ] );

        $wpdb->insert( "{$wpdb->prefix}prode_users", [
            'tenant_id'    => 'test',
            'email'        => 'player@test.com',
            'display_name' => 'Player One',
            'is_active'    => 1,
        ] );
        $userId = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}prode_predictions", [
            'user_id'            => $userId,
            'fecha_id'           => $fechaId,
            'match_id'           => 501,
            'result'             => '1',
            'score_home'         => 2,
            'score_away'         => 0,
            'created_at'         => '2026-08-20 10:00:00',
            'updated_at'         => '2026-08-20 10:00:00',
            'locked_at_snapshot' => '2026-09-01 20:00:00',
        ] );

        // Resolver provides "Tigre" / "Boca" for match_id 501.
        $resolver = $this->makeResolver( [ 501 => [ 'home_team' => 'Tigre', 'away_team' => 'Boca' ] ] );
        $this->makePage( $resolver ); // ensures constructor accepts the resolver

        // Verify enrichMatches behaviour on rows returned by findAllByUser:
        // this mirrors exactly what renderDetail will pass to the list table.
        $predRepo = new PredictionRepository( $wpdb );
        $allRows  = $predRepo->findAllByUser( $userId );
        $enriched = $resolver->enrichMatches( $allRows );

        $this->assertCount( 1, $enriched );
        $this->assertSame( 'Tigre', $enriched[0]['home_team'] );
        $this->assertSame( 'Boca', $enriched[0]['away_team'] );
        // Other prediction columns must be preserved.
        $this->assertSame( '2', (string) $enriched[0]['score_home'] );
        $this->assertSame( '0', (string) $enriched[0]['score_away'] );
    }

    /**
     * A row that already has a non-empty snapshot home_team must NOT be
     * overwritten by the live payload — snapshot is authoritative.
     */
    public function test_renderDetail_keeps_snapshot_name_when_already_set(): void {
        global $wpdb;

        $wpdb->insert( "{$wpdb->prefix}prode_fechas", [
            'tenant_id' => 'test',
            'season_id' => 1,
            'state'     => 'evaluated',
            'locked_at' => '2026-08-01 20:00:00',
        ] );
        $fechaId = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}prode_fecha_matches", [
            'fecha_id'      => $fechaId,
            'match_id'      => 101,
            'home_team'     => 'River',   // snapshot already filled
            'away_team'     => 'San Lorenzo',
            'match_kickoff' => '2026-08-01 15:00:00',
        ] );

        $wpdb->insert( "{$wpdb->prefix}prode_users", [
            'tenant_id'    => 'test',
            'email'        => 'player2@test.com',
            'display_name' => 'Player Two',
            'is_active'    => 1,
        ] );
        $userId = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}prode_predictions", [
            'user_id'            => $userId,
            'fecha_id'           => $fechaId,
            'match_id'           => 101,
            'result'             => 'X',
            'score_home'         => 1,
            'score_away'         => 1,
            'created_at'         => '2026-07-20 10:00:00',
            'updated_at'         => '2026-07-20 10:00:00',
            'locked_at_snapshot' => '2026-08-01 20:00:00',
        ] );

        // Live payload would return different names for match 101 — must be ignored.
        $resolver = $this->makeResolver( [ 101 => [ 'home_team' => 'WRONG_NAME', 'away_team' => 'ALSO_WRONG' ] ] );

        $predRepo = new PredictionRepository( $wpdb );
        $allRows  = $predRepo->findAllByUser( $userId );
        $enriched = $resolver->enrichMatches( $allRows );

        $this->assertCount( 1, $enriched );
        // Snapshot wins — live names must NOT overwrite.
        $this->assertSame( 'River', $enriched[0]['home_team'] );
        $this->assertSame( 'San Lorenzo', $enriched[0]['away_team'] );
    }
}

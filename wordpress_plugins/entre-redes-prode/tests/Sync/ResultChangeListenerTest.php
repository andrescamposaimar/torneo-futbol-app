<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Sync;

use EntreRedes\Prode\Migrations\InitialSchema;
use EntreRedes\Prode\Sync\ResultChangeListener;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ResultChangeListener::onSavePost() (ADR-G7-1).
 *
 * Hook BINDING (save_post priority 20, never save_post_sp_event) is asserted
 * separately in tests/Admin/PluginAdminWiringTest.php against Plugin::boot() —
 * that is the regression guard for the whole feature. This file exercises the
 * listener's own guard chain and scheduling decision in isolation, calling
 * onSavePost() directly (never through do_action('save_post', ...)).
 *
 * IMPORTANT — test order: test_autosave_produces_no_scheduled_event() defines
 * the DOING_AUTOSAVE constant. PHP constants cannot be undefined once set, so
 * that test is placed LAST in this file (PHPUnit runs methods in declaration
 * order by default, per phpunit.xml — no random order configured) so it never
 * leaks into an earlier assertion in this class. This mirrors the existing
 * guarded-define pattern in SettingsValidatorTest (PRODE_GOOGLE_CLIENT_ID).
 *
 * Spec coverage: the guard list and change-detection rules in the
 * feat/prode-auto-reevaluate-on-result-change task spec.
 */
class ResultChangeListenerTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );

        // wp_options is a WP core table, not owned by this plugin's schema —
        // create a minimal local fixture for the transient-purge assertion.
        $wpdb->query( 'CREATE TABLE IF NOT EXISTS wp_options (option_name TEXT, option_value TEXT)' );
        $wpdb->query( 'DELETE FROM wp_options' );

        $GLOBALS['_prode_test_scheduled_events'] = [];
        $GLOBALS['wp_test_posts']                = [];
        $GLOBALS['wp_test_postmeta']              = [];
        $GLOBALS['wp_test_post_revisions']        = [];
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( 'DELETE FROM wp_options' );
    }

    // -------------------------------------------------------------------------
    // Seeding helpers
    // -------------------------------------------------------------------------

    private function seedFecha( string $state ): int {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_fechas',
            [
                'tenant_id'  => 'test_tenant',
                'season_id'  => 359,
                'locked_at'  => '2026-05-30 10:00:00',
                'state'      => $state,
                'created_at' => '2026-05-28 00:00:00',
            ]
        );
        return (int) $wpdb->insert_id;
    }

    private function seedMatch( int $fechaId, int $matchId, ?int $realHome, ?int $realAway, bool $isFinal ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_fecha_matches',
            [
                'fecha_id'        => $fechaId,
                'match_id'        => $matchId,
                'match_kickoff'   => '2026-05-30 10:00:00',
                'real_score_home' => $realHome,
                'real_score_away' => $realAway,
                'is_final'        => $isFinal ? 1 : 0,
            ]
        );
    }

    private function seedPost( int $postId, string $postType, string $postStatus ): void {
        $GLOBALS['wp_test_posts'][ $postId ] = [
            'post_type'   => $postType,
            'post_status' => $postStatus,
        ];
    }

    private function seedMeta( int $postId, string $key, string $value ): void {
        $GLOBALS['wp_test_postmeta'][ $postId ][ $key ] = [ $value ];
    }

    // -------------------------------------------------------------------------
    // Guard chain
    // -------------------------------------------------------------------------

    public function test_defensive_signature_with_missing_post_does_not_fatal(): void {
        // No post seeded at all — get_post() fallback returns null, listener
        // bails. Also proves the single-argument call (no $post) never fatals.
        ResultChangeListener::onSavePost( 999999 );

        $this->assertCount( 0, $GLOBALS['_prode_test_scheduled_events'] );
    }

    public function test_non_sp_event_post_type_produces_no_scheduled_event(): void {
        $fechaId = $this->seedFecha( 'evaluated' );
        $this->seedMatch( $fechaId, 555, 2, 1, true );
        $this->seedPost( 555, 'post', 'publish' );
        $this->seedMeta( 555, 'sp_score_1', '9' );
        $this->seedMeta( 555, 'sp_score_2', '9' );

        ResultChangeListener::onSavePost( 555 );

        $this->assertCount( 0, $GLOBALS['_prode_test_scheduled_events'] );
    }

    public function test_revision_produces_no_scheduled_event(): void {
        $fechaId = $this->seedFecha( 'evaluated' );
        $this->seedMatch( $fechaId, 555, 2, 1, true );
        $this->seedPost( 555, 'sp_event', 'publish' );
        $this->seedMeta( 555, 'sp_score_1', '9' );
        $this->seedMeta( 555, 'sp_score_2', '9' );
        $GLOBALS['wp_test_post_revisions'][555] = true;

        ResultChangeListener::onSavePost( 555 );

        $this->assertCount( 0, $GLOBALS['_prode_test_scheduled_events'] );
    }

    public function test_non_publish_status_produces_no_scheduled_event(): void {
        $fechaId = $this->seedFecha( 'evaluated' );
        $this->seedMatch( $fechaId, 555, 2, 1, true );
        $this->seedPost( 555, 'sp_event', 'draft' );
        $this->seedMeta( 555, 'sp_score_1', '9' );
        $this->seedMeta( 555, 'sp_score_2', '9' );

        ResultChangeListener::onSavePost( 555 );

        $this->assertCount( 0, $GLOBALS['_prode_test_scheduled_events'] );
    }

    public function test_match_not_tracked_by_any_fecha_produces_no_scheduled_event(): void {
        // sp_event exists but no prode_fecha_matches row references it.
        $this->seedPost( 555, 'sp_event', 'publish' );
        $this->seedMeta( 555, 'sp_score_1', '9' );
        $this->seedMeta( 555, 'sp_score_2', '9' );

        ResultChangeListener::onSavePost( 555 );

        $this->assertCount( 0, $GLOBALS['_prode_test_scheduled_events'] );
    }

    public function test_fecha_not_yet_evaluated_produces_no_scheduled_event(): void {
        // Normal EvaluatorCron sweep already covers this fecha — nothing to repair.
        $fechaId = $this->seedFecha( 'open' );
        $this->seedMatch( $fechaId, 555, null, null, false );
        $this->seedPost( 555, 'sp_event', 'publish' );
        $this->seedMeta( 555, 'sp_score_1', '2' );
        $this->seedMeta( 555, 'sp_score_2', '1' );

        ResultChangeListener::onSavePost( 555 );

        $this->assertCount( 0, $GLOBALS['_prode_test_scheduled_events'] );
    }

    // -------------------------------------------------------------------------
    // Change detection
    // -------------------------------------------------------------------------

    public function test_unchanged_score_produces_no_scheduled_event(): void {
        $fechaId = $this->seedFecha( 'evaluated' );
        $this->seedMatch( $fechaId, 555, 2, 1, true );
        $this->seedPost( 555, 'sp_event', 'publish' );
        $this->seedMeta( 555, 'sp_score_1', '2' );
        $this->seedMeta( 555, 'sp_score_2', '1' );

        ResultChangeListener::onSavePost( 555 );

        $this->assertCount( 0, $GLOBALS['_prode_test_scheduled_events'] );
    }

    public function test_empty_score_meta_still_schedules(): void {
        // Empty sp_score_1/sp_score_2 must NOT be treated as "unchanged" — the
        // result may be stored elsewhere (main_results fallback), so we must not
        // silently skip a fecha that could actually need repair.
        $fechaId = $this->seedFecha( 'evaluated' );
        $this->seedMatch( $fechaId, 555, 2, 1, true );
        $this->seedPost( 555, 'sp_event', 'publish' );
        // No sp_score_1 / sp_score_2 meta seeded — get_post_meta() returns ''.

        ResultChangeListener::onSavePost( 555 );

        $this->assertCount( 1, $GLOBALS['_prode_test_scheduled_events'] );
        $this->assertSame( 'prode_reevaluate_fecha', $GLOBALS['_prode_test_scheduled_events'][0]['hook'] );
        $this->assertSame( [ $fechaId ], $GLOBALS['_prode_test_scheduled_events'][0]['args'] );
    }

    public function test_changed_score_schedules_reevaluation_exactly_once_and_purges_partidos_transients(): void {
        global $wpdb;

        $fechaId = $this->seedFecha( 'evaluated' );
        $this->seedMatch( $fechaId, 555, 2, 2, true ); // scored 2-2 originally
        $this->seedPost( 555, 'sp_event', 'publish' );
        $this->seedMeta( 555, 'sp_score_1', '2' );
        $this->seedMeta( 555, 'sp_score_2', '1' ); // operator corrected to 2-1

        // Seed transient rows: two matching the /partidos family, one unrelated.
        $wpdb->insert( 'wp_options', [ 'option_name' => '_transient_entre_redes_partidos_abc', 'option_value' => 'x' ] );
        $wpdb->insert( 'wp_options', [ 'option_name' => '_transient_timeout_entre_redes_partidos_abc', 'option_value' => '1' ] );
        $wpdb->insert( 'wp_options', [ 'option_name' => '_transient_unrelated_thing', 'option_value' => 'keep' ] );

        ResultChangeListener::onSavePost( 555 );

        $this->assertCount( 1, $GLOBALS['_prode_test_scheduled_events'] );
        $this->assertSame( 'prode_reevaluate_fecha', $GLOBALS['_prode_test_scheduled_events'][0]['hook'] );
        $this->assertSame( [ $fechaId ], $GLOBALS['_prode_test_scheduled_events'][0]['args'] );

        $remaining = $wpdb->get_results( 'SELECT option_name FROM wp_options' );
        $names     = array_column( $remaining, 'option_name' );

        $this->assertNotContains( '_transient_entre_redes_partidos_abc', $names );
        $this->assertNotContains( '_transient_timeout_entre_redes_partidos_abc', $names );
        $this->assertContains( '_transient_unrelated_thing', $names, 'Purge must not touch unrelated transients.' );
    }

    // -------------------------------------------------------------------------
    // Autosave guard — MUST stay the last test in this file (see class docblock).
    // -------------------------------------------------------------------------

    public function test_autosave_produces_no_scheduled_event(): void {
        if ( ! defined( 'DOING_AUTOSAVE' ) ) {
            define( 'DOING_AUTOSAVE', true );
        }

        $fechaId = $this->seedFecha( 'evaluated' );
        $this->seedMatch( $fechaId, 555, 2, 2, true );
        $this->seedPost( 555, 'sp_event', 'publish' );
        $this->seedMeta( 555, 'sp_score_1', '2' );
        $this->seedMeta( 555, 'sp_score_2', '1' ); // would otherwise schedule

        ResultChangeListener::onSavePost( 555 );

        $this->assertCount( 0, $GLOBALS['_prode_test_scheduled_events'] );
    }
}

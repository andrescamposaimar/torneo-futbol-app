<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Rest;

use EntreRedes\Prode\Rest\MatchShaper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MatchShaper::shape() — real-score exposure gated on is_final.
 *
 * Correctness-critical: the is_final gate MUST prevent real scores from leaking
 * when is_final is falsy. These are explicit LEAK tests.
 */
class MatchShaperTest extends TestCase {

    // -------------------------------------------------------------------------
    // Existing contract — regression
    // -------------------------------------------------------------------------

    public function test_shape_returns_base_fields(): void {
        $row = [
            'match_id'     => 5,
            'home_team'    => 'Home FC',
            'away_team'    => 'Away United',
            'match_kickoff' => '2026-06-01 14:00',
            'zona'          => 'Zona A',
            'home_escudo'   => 'http://example.com/h.png',
            'away_escudo'   => 'http://example.com/a.png',
        ];

        $result = MatchShaper::shape( $row );

        $this->assertSame( 5, $result['match_id'] );
        $this->assertSame( 'Home FC', $result['home_team'] );
        $this->assertSame( 'Away United', $result['away_team'] );
        $this->assertSame( '2026-06-01 14:00', $result['kickoff'] );
        $this->assertSame( 'Zona A', $result['zona'] );
        $this->assertSame( 'http://example.com/h.png', $result['home_escudo'] );
        $this->assertSame( 'http://example.com/a.png', $result['away_escudo'] );
        $this->assertNull( $result['populares'] );
    }

    // -------------------------------------------------------------------------
    // T-05 — real_score_home / real_score_away / is_final
    // -------------------------------------------------------------------------

    public function test_shape_exposes_real_scores_when_is_final_true(): void {
        // is_final=1 + scores set → output must contain non-null int values.
        $row = [
            'match_id'        => 10,
            'home_team'       => 'A',
            'away_team'       => 'B',
            'match_kickoff'   => '2026-06-01 14:00',
            'zona'            => '',
            'is_final'        => 1,
            'real_score_home' => 2,
            'real_score_away' => 0,
        ];

        $result = MatchShaper::shape( $row );

        $this->assertSame( 2, $result['real_score_home'] );
        $this->assertSame( 0, $result['real_score_away'] );
        $this->assertTrue( $result['is_final'] );
    }

    public function test_shape_nulls_real_scores_when_is_final_false_GATE(): void {
        // LEAK TEST: is_final=0 but scores are non-null → output MUST be null.
        $row = [
            'match_id'        => 10,
            'home_team'       => 'A',
            'away_team'       => 'B',
            'match_kickoff'   => '2026-06-01 14:00',
            'zona'            => '',
            'is_final'        => 0,
            'real_score_home' => 3,
            'real_score_away' => 1,
        ];

        $result = MatchShaper::shape( $row );

        $this->assertNull( $result['real_score_home'], 'LEAK: real_score_home must be null when is_final=0' );
        $this->assertNull( $result['real_score_away'], 'LEAK: real_score_away must be null when is_final=0' );
        $this->assertFalse( $result['is_final'] );
    }

    public function test_shape_nulls_real_scores_when_is_final_absent_GATE(): void {
        // LEAK TEST: is_final key absent from row → output is_final=false, real scores null.
        $row = [
            'match_id'      => 10,
            'home_team'     => 'A',
            'away_team'     => 'B',
            'match_kickoff' => '2026-06-01 14:00',
            'zona'          => '',
            // no is_final, no real_score_*
        ];

        $result = MatchShaper::shape( $row );

        $this->assertNull( $result['real_score_home'], 'LEAK: real_score_home must be null when is_final absent' );
        $this->assertNull( $result['real_score_away'], 'LEAK: real_score_away must be null when is_final absent' );
        $this->assertFalse( $result['is_final'] );
    }

    public function test_shape_is_final_false_default_when_column_absent(): void {
        // Backward compat: old rows without is_final column → is_final=false.
        $row = [
            'match_id'      => 7,
            'home_team'     => 'X',
            'away_team'     => 'Y',
            'match_kickoff' => '2026-06-01 14:00',
            'zona'          => '',
        ];

        $result = MatchShaper::shape( $row );

        $this->assertArrayHasKey( 'is_final', $result );
        $this->assertFalse( $result['is_final'] );
        $this->assertArrayHasKey( 'real_score_home', $result );
        $this->assertNull( $result['real_score_home'] );
        $this->assertArrayHasKey( 'real_score_away', $result );
        $this->assertNull( $result['real_score_away'] );
    }

    public function test_shape_real_scores_are_ints_when_is_final_true(): void {
        // Type gate: even if DB returns string values, output must be int.
        $row = [
            'match_id'        => 11,
            'home_team'       => 'A',
            'away_team'       => 'B',
            'match_kickoff'   => '2026-06-01 14:00',
            'zona'            => '',
            'is_final'        => '1',    // string from DB
            'real_score_home' => '3',   // string from DB
            'real_score_away' => '2',   // string from DB
        ];

        $result = MatchShaper::shape( $row );

        $this->assertIsInt( $result['real_score_home'] );
        $this->assertIsInt( $result['real_score_away'] );
        $this->assertSame( 3, $result['real_score_home'] );
        $this->assertSame( 2, $result['real_score_away'] );
        $this->assertTrue( $result['is_final'] );
    }
}

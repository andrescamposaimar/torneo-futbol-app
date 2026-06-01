<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Scoring;

use EntreRedes\Prode\Scoring\ScoreCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ScoreCalculator — pure scoring logic.
 *
 * No DB, no WP runtime. Each case is a direct call to evaluate().
 * Mirrors LockComputerTest (pure domain class, no infrastructure).
 *
 * Spec coverage: SC-1..SC-10, R1.1..R1.6, R8.3.
 */
class ScoreCalculatorTest extends TestCase {

    private ScoreCalculator $calc;

    protected function setUp(): void {
        $this->calc = new ScoreCalculator();
    }

    // -------------------------------------------------------------------------
    // exact_score branch — 3 points (SC-1, SC-2, SC-3)
    // -------------------------------------------------------------------------

    /** SC-1: 0-0 exact draw → 3 / exact_score */
    public function test_sc1_exact_draw_zero_zero(): void {
        $result = $this->calc->evaluate( 0, 0, 0, 0 );
        $this->assertSame( 3, $result['points'] );
        $this->assertSame( 'exact_score', $result['method'] );
    }

    /** SC-2: 2-1 exact home win → 3 / exact_score */
    public function test_sc2_exact_home_win(): void {
        $result = $this->calc->evaluate( 2, 1, 2, 1 );
        $this->assertSame( 3, $result['points'] );
        $this->assertSame( 'exact_score', $result['method'] );
    }

    /** SC-3: 0-2 exact away win → 3 / exact_score */
    public function test_sc3_exact_away_win(): void {
        $result = $this->calc->evaluate( 0, 2, 0, 2 );
        $this->assertSame( 3, $result['points'] );
        $this->assertSame( 'exact_score', $result['method'] );
    }

    // -------------------------------------------------------------------------
    // result_only / correct 1X2 — 1 point (SC-4, SC-5, SC-6)
    // -------------------------------------------------------------------------

    /** SC-4: predicted X (1-1), actual 0-0 → correct X, wrong score → 1 / result_only */
    public function test_sc4_correct_draw_wrong_score(): void {
        $result = $this->calc->evaluate( 1, 1, 0, 0 );
        $this->assertSame( 1, $result['points'] );
        $this->assertSame( 'result_only', $result['method'] );
    }

    /** SC-5: predicted 1 (3-0), actual 2-1 → correct home win, wrong score → 1 / result_only */
    public function test_sc5_correct_home_win_wrong_score(): void {
        $result = $this->calc->evaluate( 3, 0, 2, 1 );
        $this->assertSame( 1, $result['points'] );
        $this->assertSame( 'result_only', $result['method'] );
    }

    /** SC-6: predicted 2 (0-3), actual 0-1 → correct away win, wrong score → 1 / result_only */
    public function test_sc6_correct_away_win_wrong_score(): void {
        $result = $this->calc->evaluate( 0, 3, 0, 1 );
        $this->assertSame( 1, $result['points'] );
        $this->assertSame( 'result_only', $result['method'] );
    }

    // -------------------------------------------------------------------------
    // result_only / wrong 1X2 — 0 points (SC-7, SC-8, SC-9, SC-10)
    // -------------------------------------------------------------------------

    /** SC-7: predicted 1 (2-0), actual X (0-0) → wrong → 0 / result_only */
    public function test_sc7_predicted_home_actual_draw(): void {
        $result = $this->calc->evaluate( 2, 0, 0, 0 );
        $this->assertSame( 0, $result['points'] );
        $this->assertSame( 'result_only', $result['method'] );
    }

    /** SC-8: predicted X (1-1), actual 1 (2-0) → wrong → 0 / result_only */
    public function test_sc8_predicted_draw_actual_home(): void {
        $result = $this->calc->evaluate( 1, 1, 2, 0 );
        $this->assertSame( 0, $result['points'] );
        $this->assertSame( 'result_only', $result['method'] );
    }

    /** SC-9: predicted 2 (0-2), actual 1 (3-0) → wrong → 0 / result_only */
    public function test_sc9_predicted_away_actual_home(): void {
        $result = $this->calc->evaluate( 0, 2, 3, 0 );
        $this->assertSame( 0, $result['points'] );
        $this->assertSame( 'result_only', $result['method'] );
    }

    /** SC-10: predicted 1 (1-0), actual 2 (0-1) → opposite result → 0 / result_only */
    public function test_sc10_predicted_home_actual_away(): void {
        $result = $this->calc->evaluate( 1, 0, 0, 1 );
        $this->assertSame( 0, $result['points'] );
        $this->assertSame( 'result_only', $result['method'] );
    }

    // -------------------------------------------------------------------------
    // Return shape contract (R8.3)
    // -------------------------------------------------------------------------

    /** Result array has exactly 'points' (int) and 'method' (string) keys. */
    public function test_result_shape_has_points_int_and_method_string(): void {
        $result = $this->calc->evaluate( 1, 0, 1, 0 );
        $this->assertArrayHasKey( 'points', $result );
        $this->assertArrayHasKey( 'method', $result );
        $this->assertIsInt( $result['points'] );
        $this->assertIsString( $result['method'] );
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Scoring;

/**
 * Pure scoring logic — no I/O, no side effects, no WP runtime required (R1.6).
 *
 * Evaluates a single user prediction against actual match scores and returns
 * the points awarded and the evaluation method used.
 *
 * Algorithm:
 *   1. Exact match (predHome == realHome && predAway == realAway) → 3 / exact_score
 *   2. Same 1X2 result (derived independently for both sides)       → 1 / result_only
 *   3. Different 1X2 result                                          → 0 / result_only
 *
 * 1X2 derivation mirrors PredictionRepository::deriveResult (ADR-G2-1):
 *   home > away → '1', home == away → 'X', home < away → '2'.
 *
 * The no_prediction and no_match_score cases are NOT handled here — those are
 * decided upstream in FechaEvaluator (ADR-G3-2). This class only ever sees
 * a real prediction vs a real final score.
 */
class ScoreCalculator {

    /**
     * Compute the points and evaluation_method for a single prediction.
     *
     * @param int $predHome  Predicted home score.
     * @param int $predAway  Predicted away score.
     * @param int $realHome  Actual home score.
     * @param int $realAway  Actual away score.
     * @return array{points: int, method: string}
     *
     * @internal No side effects. Safe to call in any context.
     */
    public function evaluate( int $predHome, int $predAway, int $realHome, int $realAway ): array {
        // Branch 1: exact score match → 3 points.
        if ( $predHome === $realHome && $predAway === $realAway ) {
            return [ 'points' => 3, 'method' => 'exact_score' ];
        }

        // Branch 2: same 1X2 result (correct tendency, wrong score) → 1 point.
        // Branch 3: different 1X2 result → 0 points.
        $predResult = $this->deriveResult( $predHome, $predAway );
        $realResult = $this->deriveResult( $realHome, $realAway );

        $points = ( $predResult === $realResult ) ? 1 : 0;

        return [ 'points' => $points, 'method' => 'result_only' ];
    }

    /**
     * Derive the 1X2 result string from home and away scores.
     *
     * home > away → '1', home == away → 'X', home < away → '2'.
     *
     * Mirrors PredictionRepository::deriveResult exactly (ADR-G2-1).
     */
    private function deriveResult( int $home, int $away ): string {
        if ( $home > $away ) {
            return '1';
        }
        if ( $home === $away ) {
            return 'X';
        }
        return '2';
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Scoring;

/**
 * Pure ranking engine — no I/O, no side effects, no WP runtime required.
 *
 * Receives an array of aggregated standings rows and returns the same rows
 * sorted and annotated with a `rank` field using standard-competition ranking
 * ("1,2,2,4" — tied groups share a rank; next group's rank skips by group size).
 *
 * Tiebreak order (ascending priority — all three applied):
 *   1. total_points DESC  (primary)
 *   2. exact_count DESC   (secondary)
 *   3. user_id ASC        (tertiary — deterministic display order within a tied group)
 *
 * The user_id ASC tiebreaker keeps the sort deterministic but does NOT split
 * the shared rank: all rows with identical (total_points, exact_count) receive
 * the same rank number.
 *
 * Mirrors ScoreCalculator (pure-helper style — no constructor dependencies).
 */
class RankingComputer {

    /**
     * Assign standard-competition ranks to an array of standings rows.
     *
     * Input row shape:  { user_id: int, total_points: int, exact_count: int, ...any }
     * Output row shape: same rows with `rank: int` added, sorted by tiebreak order.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function assignRanks( array $rows ): array {
        if ( empty( $rows ) ) {
            return [];
        }

        // Sort by tiebreak: total_points DESC, exact_count DESC, user_id ASC.
        usort( $rows, static function ( array $a, array $b ): int {
            $ptsDiff = (int) $b['total_points'] - (int) $a['total_points'];
            if ( $ptsDiff !== 0 ) {
                return $ptsDiff;
            }

            $ecDiff = (int) $b['exact_count'] - (int) $a['exact_count'];
            if ( $ecDiff !== 0 ) {
                return $ecDiff;
            }

            return (int) $a['user_id'] - (int) $b['user_id'];
        } );

        // Walk the sorted list and assign standard-competition ranks.
        // A rank group starts at the 1-based index of its first member.
        $groupStart  = 0; // 0-based index of the first row in the current group.
        $groupPoints = (int) $rows[0]['total_points'];
        $groupEc     = (int) $rows[0]['exact_count'];

        foreach ( $rows as $i => &$row ) {
            $pts = (int) $row['total_points'];
            $ec  = (int) $row['exact_count'];

            if ( $pts !== $groupPoints || $ec !== $groupEc ) {
                // New group: update group start + key values.
                $groupStart  = $i;
                $groupPoints = $pts;
                $groupEc     = $ec;
            }

            $row['rank'] = $groupStart + 1; // 1-based rank = group start index + 1.
        }
        unset( $row );

        return $rows;
    }
}

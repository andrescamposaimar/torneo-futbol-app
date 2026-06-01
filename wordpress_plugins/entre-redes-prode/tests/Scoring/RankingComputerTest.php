<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Scoring;

use EntreRedes\Prode\Scoring\RankingComputer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RankingComputer::assignRanks().
 *
 * Pure logic — no DB, no setUp/tearDown schema.
 *
 * Spec coverage: RC-01..08.
 */
class RankingComputerTest extends TestCase {

    private RankingComputer $computer;

    protected function setUp(): void {
        $this->computer = new RankingComputer();
    }

    // -------------------------------------------------------------------------
    // RC-08 — Empty input
    // -------------------------------------------------------------------------

    public function test_empty_input_returns_empty_array(): void {
        $result = $this->computer->assignRanks( [] );
        $this->assertSame( [], $result );
    }

    // -------------------------------------------------------------------------
    // RC-07 — Single user
    // -------------------------------------------------------------------------

    public function test_single_user_gets_rank_1(): void {
        $rows   = [ [ 'user_id' => 1, 'total_points' => 0, 'exact_count' => 0 ] ];
        $result = $this->computer->assignRanks( $rows );

        $this->assertCount( 1, $result );
        $this->assertSame( 1, $result[0]['rank'] );
        $this->assertIsInt( $result[0]['rank'] );
    }

    // -------------------------------------------------------------------------
    // RC-01 — Basic 4-user ladder (no ties)
    // -------------------------------------------------------------------------

    public function test_four_user_no_tie_ladder(): void {
        $rows = [
            [ 'user_id' => 1, 'total_points' => 10, 'exact_count' => 2 ],
            [ 'user_id' => 2, 'total_points' => 8, 'exact_count' => 1 ],
            [ 'user_id' => 3, 'total_points' => 5, 'exact_count' => 0 ],
            [ 'user_id' => 4, 'total_points' => 3, 'exact_count' => 0 ],
        ];

        $result = $this->computer->assignRanks( $rows );

        $byUser = $this->indexByUserId( $result );
        $this->assertSame( 1, $byUser[1]['rank'] );
        $this->assertSame( 2, $byUser[2]['rank'] );
        $this->assertSame( 3, $byUser[3]['rank'] );
        $this->assertSame( 4, $byUser[4]['rank'] );
    }

    // -------------------------------------------------------------------------
    // RC-02 — Points tie broken by exact_count
    // -------------------------------------------------------------------------

    public function test_points_tie_broken_by_exact_count(): void {
        $rows = [
            [ 'user_id' => 1, 'total_points' => 8, 'exact_count' => 2 ],
            [ 'user_id' => 2, 'total_points' => 8, 'exact_count' => 1 ],
            [ 'user_id' => 3, 'total_points' => 5, 'exact_count' => 0 ],
        ];

        $result = $this->computer->assignRanks( $rows );

        $byUser = $this->indexByUserId( $result );
        $this->assertSame( 1, $byUser[1]['rank'] );
        $this->assertSame( 2, $byUser[2]['rank'] );
        $this->assertSame( 3, $byUser[3]['rank'] );
    }

    // -------------------------------------------------------------------------
    // RC-03 — Full tie on (points, exact_count) → shared rank + skip (1,1,3)
    // -------------------------------------------------------------------------

    public function test_full_tie_two_way_shared_rank_with_skip(): void {
        $rows = [
            [ 'user_id' => 1, 'total_points' => 8, 'exact_count' => 1 ],
            [ 'user_id' => 2, 'total_points' => 8, 'exact_count' => 1 ],
            [ 'user_id' => 3, 'total_points' => 5, 'exact_count' => 0 ],
        ];

        $result = $this->computer->assignRanks( $rows );

        $byUser = $this->indexByUserId( $result );
        $this->assertSame( 1, $byUser[1]['rank'] );
        $this->assertSame( 1, $byUser[2]['rank'] );
        $this->assertSame( 3, $byUser[3]['rank'] ); // skips rank 2
    }

    // -------------------------------------------------------------------------
    // RC-04 — Standard-competition skip math [10,8,8,5] → 1,2,2,4
    // -------------------------------------------------------------------------

    public function test_standard_competition_skip_math(): void {
        $rows = [
            [ 'user_id' => 1, 'total_points' => 10, 'exact_count' => 0 ],
            [ 'user_id' => 2, 'total_points' => 8, 'exact_count' => 0 ],
            [ 'user_id' => 3, 'total_points' => 8, 'exact_count' => 0 ],
            [ 'user_id' => 4, 'total_points' => 5, 'exact_count' => 0 ],
        ];

        $result = $this->computer->assignRanks( $rows );

        $byUser = $this->indexByUserId( $result );
        $this->assertSame( 1, $byUser[1]['rank'] );
        $this->assertSame( 2, $byUser[2]['rank'] );
        $this->assertSame( 2, $byUser[3]['rank'] );
        $this->assertSame( 4, $byUser[4]['rank'] ); // skips rank 3
    }

    // -------------------------------------------------------------------------
    // RC-05 — 3-way tie → 1,1,1,4
    // -------------------------------------------------------------------------

    public function test_three_way_tie(): void {
        $rows = [
            [ 'user_id' => 1, 'total_points' => 6, 'exact_count' => 2 ],
            [ 'user_id' => 2, 'total_points' => 6, 'exact_count' => 2 ],
            [ 'user_id' => 3, 'total_points' => 6, 'exact_count' => 2 ],
            [ 'user_id' => 4, 'total_points' => 3, 'exact_count' => 0 ],
        ];

        $result = $this->computer->assignRanks( $rows );

        $byUser = $this->indexByUserId( $result );
        $this->assertSame( 1, $byUser[1]['rank'] );
        $this->assertSame( 1, $byUser[2]['rank'] );
        $this->assertSame( 1, $byUser[3]['rank'] );
        $this->assertSame( 4, $byUser[4]['rank'] ); // skips ranks 2 and 3
    }

    // -------------------------------------------------------------------------
    // RC-06 — user_id ASC determinism within shared rank
    // -------------------------------------------------------------------------

    public function test_user_id_asc_determinism_within_shared_rank(): void {
        // u5 passed before u3 — output must be ordered u3 first, then u5.
        $rows = [
            [ 'user_id' => 5, 'total_points' => 6, 'exact_count' => 2 ],
            [ 'user_id' => 3, 'total_points' => 6, 'exact_count' => 2 ],
        ];

        $result = $this->computer->assignRanks( $rows );

        $this->assertCount( 2, $result );
        $this->assertSame( 3, $result[0]['user_id'] );   // u3 first
        $this->assertSame( 5, $result[1]['user_id'] );   // u5 second
        $this->assertSame( 1, $result[0]['rank'] );       // both rank 1
        $this->assertSame( 1, $result[1]['rank'] );
    }

    // -------------------------------------------------------------------------
    // int type assertion on rank field
    // -------------------------------------------------------------------------

    public function test_rank_field_is_int_type(): void {
        $rows   = [
            [ 'user_id' => 1, 'total_points' => 5, 'exact_count' => 1 ],
            [ 'user_id' => 2, 'total_points' => 3, 'exact_count' => 0 ],
        ];
        $result = $this->computer->assignRanks( $rows );

        foreach ( $result as $row ) {
            $this->assertIsInt( $row['rank'], 'rank must be int' );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>  keyed by user_id
     */
    private function indexByUserId( array $rows ): array {
        $indexed = [];
        foreach ( $rows as $row ) {
            $indexed[ (int) $row['user_id'] ] = $row;
        }
        return $indexed;
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Scoring;

use EntreRedes\Prode\Scoring\RankingRepository;
use EntreRedes\Prode\Scoring\WpRosterResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests team resolution against the postmeta shapes this install actually has.
 *
 * `sp_current_team` is documented as single-value but is not: SportsPress imports
 * leave a '0' "unassigned" sentinel row, and because it is written first it wins
 * the `get_post_meta( ..., true )` read. In August 2026, 139 players carried one
 * — every case shaped (0, real_club_id) — and the Prode leaderboard showed them
 * as "Sin Equipo" while the main API resolved their club correctly. The database
 * had already been cleaned once and the sentinel returned, so the resolver has to
 * tolerate it rather than depend on the data being tidy.
 */
final class WpRosterResolverTest extends TestCase {

    private WpRosterResolver $resolver;

    protected function setUp(): void {
        global $wp_test_postmeta, $wp_test_post_titles;
        $wp_test_postmeta   = [];
        $wp_test_post_titles = [
            14349 => 'LISTA DE ESPERA 2026',
            21584 => 'ALEMANIA',
            15803 => 'URUGUAY',
        ];

        // The repository is only used for the user_id → player_id batch lookup,
        // which resolveTeam does not touch; skip its wpdb constructor.
        $repo = ( new \ReflectionClass( RankingRepository::class ) )->newInstanceWithoutConstructor();

        $this->resolver = new WpRosterResolver( $repo );
    }

    private function resolveTeam( int $playerId ): ?string {
        $method = ( new \ReflectionClass( $this->resolver ) )->getMethod( 'resolveTeam' );

        return $method->invoke( $this->resolver, $playerId );
    }

    /** @param list<string> $rows */
    private function seedMeta( int $playerId, array $rows ): void {
        global $wp_test_postmeta;
        $wp_test_postmeta[ $playerId ] = [ 'sp_current_team' => $rows ];
    }

    public function test_sentinel_zero_before_the_real_club_still_resolves(): void {
        // The exact production shape: (0, 14349) for 139 players.
        $this->seedMeta( 2192, [ '0', '14349' ] );

        $this->assertSame( 'LISTA DE ESPERA 2026', $this->resolveTeam( 2192 ) );
    }

    public function test_real_club_alone_resolves(): void {
        $this->seedMeta( 2192, [ '15803' ] );

        $this->assertSame( 'URUGUAY', $this->resolveTeam( 2192 ) );
    }

    public function test_only_the_sentinel_means_no_team(): void {
        // A player genuinely without a club must still read as unassigned.
        $this->seedMeta( 2192, [ '0' ] );

        $this->assertNull( $this->resolveTeam( 2192 ) );
    }

    public function test_no_rows_means_no_team(): void {
        $this->assertNull( $this->resolveTeam( 9999 ) );
    }

    public function test_first_real_club_wins_when_several_are_present(): void {
        // Player 2504 in production: (0, 21584, 14349). Ordering is by meta_id,
        // so the first positive row is the same club a sentinel-free read would
        // have returned — behaviour stays predictable, and this player needs a
        // human to decide which club is current.
        $this->seedMeta( 2504, [ '0', '21584', '14349' ] );

        $this->assertSame( 'ALEMANIA', $this->resolveTeam( 2504 ) );
    }

    public function test_club_id_pointing_at_a_deleted_post_falls_through(): void {
        // A club id whose post is gone resolves to an empty title; skip it rather
        // than reporting an empty team name.
        $this->seedMeta( 2192, [ '0', '999999', '15803' ] );

        $this->assertSame( 'URUGUAY', $this->resolveTeam( 2192 ) );
    }
}

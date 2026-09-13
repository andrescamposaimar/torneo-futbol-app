<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Linking;

use EntreRedes\Campeones\Linking\LinkResolver;
use EntreRedes\Campeones\Linking\PlayerDirectoryQueryException;
use EntreRedes\Campeones\Linking\WpPlayerDirectory;
use EntreRedes\Campeones\Tests\Support\FakeDirectoryWpdb;
use PHPUnit\Framework\TestCase;

/**
 * WpPlayerDirectory has no prior coverage — it is the only class that
 * touches the real directory (its own class docblock explains why it is
 * not SQLite-shim-testable for real sp_player storage). FakeDirectoryWpdb
 * scripts its three get_results() calls directly instead.
 *
 * @covers \EntreRedes\Campeones\Linking\WpPlayerDirectory
 */
final class WpPlayerDirectoryTest extends TestCase {

    public function test_a_failed_players_query_throws_instead_of_returning_an_empty_directory(): void {
        $wpdb      = ( new FakeDirectoryWpdb() )->failingPlayersQuery();
        $directory = new WpPlayerDirectory( $wpdb );

        $this->expectException( PlayerDirectoryQueryException::class );
        $directory->findBySurname( 'GARCIA' );
    }

    public function test_a_failed_seasons_query_throws_instead_of_silently_dropping_seasons(): void {
        $wpdb = ( new FakeDirectoryWpdb() )
            ->withPlayerRows( [ [ 'ID' => 5078, 'post_title' => 'Basso, Alejandro' ] ] )
            ->failingSeasonsQuery();
        $directory = new WpPlayerDirectory( $wpdb );

        $this->expectException( PlayerDirectoryQueryException::class );
        $directory->findBySurname( 'BASSO' );
    }

    public function test_a_failed_teams_query_throws_instead_of_silently_mapping_to_no_team(): void {
        $wpdb = ( new FakeDirectoryWpdb() )
            ->withPlayerRows( [ [ 'ID' => 5078, 'post_title' => 'Basso, Alejandro' ] ] )
            ->failingTeamsQuery();
        $directory = new WpPlayerDirectory( $wpdb );

        $this->expectException( PlayerDirectoryQueryException::class );
        $directory->findBySurname( 'BASSO' );
    }

    public function test_a_failed_seasons_query_propagates_through_link_resolver_instead_of_silently_resolving_to_sin_candidato_for_a_2016_plus_year(): void {
        // If fetchSeasonsByPlayerId() silently returned [] on failure (the
        // pre-fix behaviour), every RegisteredPlayer would get
        // seasonNames = [], LinkResolver's season filter would then zero
        // the single BASSO/2016 candidate for this anio >= 2016 year, and
        // resolve() would return SIN_CANDIDATO — indistinguishable from a
        // genuine zero-candidate name. It must throw instead.
        $wpdb = ( new FakeDirectoryWpdb() )
            ->withPlayerRows( [ [ 'ID' => 5078, 'post_title' => 'Basso, Alejandro' ] ] )
            ->failingSeasonsQuery();

        $resolver = new LinkResolver( new WpPlayerDirectory( $wpdb ) );

        $this->expectException( PlayerDirectoryQueryException::class );
        $resolver->resolve( 'BASSO, A.', 2016 );
    }

    public function test_bucketing_groups_players_by_surname_key(): void {
        $directory = new WpPlayerDirectory( $this->wpdbForBucketingScenario() );

        $basso = $directory->findBySurname( 'BASSO' );
        $this->assertCount( 1, $basso );
        $this->assertSame( 100, $basso[0]->id );

        $garcia = $directory->findBySurname( 'GARCIA' );
        $this->assertSame( [ 200, 300 ], array_map( static fn ( $p ) => $p->id, $garcia ) );
    }

    public function test_season_query_is_batched_and_seasons_attach_to_the_right_player(): void {
        $directory = new WpPlayerDirectory( $this->wpdbForBucketingScenario() );

        $basso = $directory->findBySurname( 'BASSO' )[0];
        $this->assertSame( [ '2016', '2017' ], $basso->seasonNames );

        [ $garciaM, $garciaA ] = $directory->findBySurname( 'GARCIA' );
        $this->assertSame( [ '2018' ], $garciaM->seasonNames );
        $this->assertSame( [], $garciaA->seasonNames, 'A player with no season rows must get an empty array, not null.' );
    }

    public function test_sp_current_team_sentinel_and_absence_both_map_to_null(): void {
        $directory = new WpPlayerDirectory( $this->wpdbForBucketingScenario() );

        $basso = $directory->findBySurname( 'BASSO' )[0];
        $this->assertSame( 'River', $basso->currentTeamName );

        [ $garciaM, $garciaA ] = $directory->findBySurname( 'GARCIA' );
        $this->assertNull( $garciaM->currentTeamName, "The sp_current_team = '0' sentinel (surfaced as a NULL team_name by the LEFT JOIN) must map to null, not a broken team." );
        $this->assertNull( $garciaA->currentTeamName, 'A player with no sp_current_team row at all must also map to null.' );
    }

    public function test_a_title_yielding_a_null_key_is_kept_out_of_every_surname_bucket(): void {
        // 'De' is a single-token title that is itself a particle — NameParser
        // returns null for it (design §3, ADR-C1). It must never surface
        // under any surname bucket, including one keyed by its own raw text.
        $directory = new WpPlayerDirectory( $this->wpdbForBucketingScenario() );

        $this->assertSame( [], $directory->findBySurname( 'DE' ) );
        $this->assertSame( [], $directory->findBySurname( '' ) );
    }

    private function wpdbForBucketingScenario(): FakeDirectoryWpdb {
        return ( new FakeDirectoryWpdb() )
            ->withPlayerRows( [
                [ 'ID' => 100, 'post_title' => 'Basso, Alejandro' ],
                [ 'ID' => 200, 'post_title' => 'Garcia, Miguel' ],
                [ 'ID' => 300, 'post_title' => 'Garcia, Ana' ],
                [ 'ID' => 400, 'post_title' => 'De' ],
            ] )
            ->withSeasonRows( [
                [ 'player_id' => 100, 'season_name' => '2016' ],
                [ 'player_id' => 100, 'season_name' => '2017' ],
                [ 'player_id' => 200, 'season_name' => '2018' ],
            ] )
            ->withTeamRows( [
                [ 'player_id' => 100, 'team_name' => 'River' ],
                // sp_current_team = '0' sentinel: the LEFT JOIN yields a row
                // with a NULL team_name, exactly as it would for a missing
                // or unpublished team (runbook-prode-sin-equipo).
                [ 'player_id' => 200, 'team_name' => null ],
            ] );
    }
}

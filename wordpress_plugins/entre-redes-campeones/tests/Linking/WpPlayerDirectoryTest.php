<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Linking;

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
}

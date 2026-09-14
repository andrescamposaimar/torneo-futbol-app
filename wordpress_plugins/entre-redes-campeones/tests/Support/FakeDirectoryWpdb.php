<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Support;

/**
 * A real wpdb (backed by its own fresh in-memory SQLite database, via the
 * normal parent constructor) that routes get_results() by recognisable
 * fragments of the SQL WpPlayerDirectory issues, instead of touching real
 * sp_player/sp_season/sp_current_team storage — the SQLite shim has none of
 * those (see WpPlayerDirectory's class docblock).
 *
 * Each of the three queries WpPlayerDirectory issues (the player roster, the
 * season join, the current-team join) can be independently scripted to
 * return canned rows or to simulate a database failure — mirroring
 * FailingInsertWpdb / FailingQueryWpdb's precedent of setting last_error
 * directly rather than hitting real SQL.
 */
class FakeDirectoryWpdb extends \wpdb {

    public string $posts              = 'wp_posts';
    public string $postmeta           = 'wp_postmeta';
    public string $term_relationships = 'wp_term_relationships';
    public string $term_taxonomy      = 'wp_term_taxonomy';
    public string $terms              = 'wp_terms';

    /** @var array<int, array<string, mixed>> */
    private array $playerRows = [];

    /** @var array<int, array<string, mixed>> */
    private array $seasonRows = [];

    /** @var array<int, array<string, mixed>> */
    private array $teamRows = [];

    private bool $failPlayersQuery = false;
    private bool $failSeasonsQuery = false;
    private bool $failTeamsQuery   = false;

    /**
     * Counts every sp_player roster query actually executed — used to prove
     * WpPlayerDirectory::findByIds() reuses the index built by an earlier
     * call instead of re-querying (no N+1 across repeated lookups on the
     * same request).
     */
    public int $playerQueryCallCount = 0;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function withPlayerRows( array $rows ): self {
        $this->playerRows = $rows;
        return $this;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function withSeasonRows( array $rows ): self {
        $this->seasonRows = $rows;
        return $this;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function withTeamRows( array $rows ): self {
        $this->teamRows = $rows;
        return $this;
    }

    public function failingPlayersQuery(): self {
        $this->failPlayersQuery = true;
        return $this;
    }

    public function failingSeasonsQuery(): self {
        $this->failSeasonsQuery = true;
        return $this;
    }

    public function failingTeamsQuery(): self {
        $this->failTeamsQuery = true;
        return $this;
    }

    public function get_results( string $sql, string $output = OBJECT ): array {
        $this->last_error = null;

        if ( str_contains( $sql, "post_type = 'sp_player'" ) ) {
            ++$this->playerQueryCallCount;
            if ( $this->failPlayersQuery ) {
                $this->last_error = 'Simulated sp_player query failure for test';
                return [];
            }
            return $this->playerRows;
        }

        if ( str_contains( $sql, 'sp_season' ) ) {
            if ( $this->failSeasonsQuery ) {
                $this->last_error = 'Simulated sp_season query failure for test';
                return [];
            }
            return $this->seasonRows;
        }

        if ( str_contains( $sql, 'sp_current_team' ) ) {
            if ( $this->failTeamsQuery ) {
                $this->last_error = 'Simulated sp_current_team query failure for test';
                return [];
            }
            return $this->teamRows;
        }

        return parent::get_results( $sql, $output );
    }

    /**
     * Minimal prepare(): WpPlayerDirectory only uses %d placeholders for id
     * lists here, and the fake routes purely on SQL fragments untouched by
     * placeholder substitution, so passing the query straight through (sans
     * real escaping) is enough for this double.
     */
    public function prepare( string $query, ...$args ): string {
        if ( count( $args ) === 1 && is_array( $args[0] ) ) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback(
            '/%[sdi]/',
            static function ( array $match ) use ( &$i, $args ) {
                $val = $args[ $i++ ] ?? '';
                return '%d' === $match[0] || '%i' === $match[0] ? (string) (int) $val : (string) $val;
            },
            $query
        );
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Linking;

/**
 * A registered sp_player, shaped for matching and search (design §4).
 *
 * `key` is the single PlayerKey NameParser::keyFor() derived from
 * `displayName` — computed once when the directory index is built
 * (WpPlayerDirectory) or when a fixture is loaded (FakePlayerDirectory),
 * never recomputed per lookup. Null means the title was degenerate and is
 * unreachable by the matcher, though still present for search (design §4).
 */
final class RegisteredPlayer {

    /**
     * @param string[] $seasonNames
     */
    public function __construct(
        public readonly int $id,
        public readonly string $displayName,
        public readonly array $seasonNames = [],
        public readonly ?string $currentTeamName = null,
        public readonly ?PlayerKey $key = null
    ) {
    }
}

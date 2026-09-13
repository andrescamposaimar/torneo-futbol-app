<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Linking;

/**
 * Thrown when a wpdb read against the sp_player directory (the player
 * roster, the season join, or the current-team join) fails at the database
 * level while building WpPlayerDirectory's index.
 *
 * Distinct on purpose from "the directory legitimately has zero rows" or
 * "this candidate legitimately has no seasons": collapsing a broken query
 * into an empty result silently turns every bulk-import name into
 * `sin_candidato`, which is indistinguishable from a directory that
 * genuinely contains no matches. A caller must never confuse the two.
 */
class PlayerDirectoryQueryException extends \RuntimeException {
}

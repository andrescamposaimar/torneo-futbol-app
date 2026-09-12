<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Linking;

/**
 * Pairwise match between a squad entry's key and one candidate registered
 * player's key (design §3). This rule is unchanged across every revision
 * of the matching engine — it was never the problem. What changed is that
 * it is now called once per candidate, with exactly one key on each side;
 * there is no matchesAny() and no key sets to iterate.
 */
final class CandidateMatcher {

    public static function matches( PlayerKey $entry, PlayerKey $candidate ): bool {
        if ( $entry->surname !== $candidate->surname ) {
            return false;
        }

        return '' === $entry->initial
            || '' === $candidate->initial
            || $entry->initial === $candidate->initial;
    }
}

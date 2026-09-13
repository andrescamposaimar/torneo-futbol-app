<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Linking;

/**
 * The outcome of RevalidationService::revalidateYear() (item 2).
 *
 * A bare "how many succeeded" integer cannot tell a caller "1 of 1
 * succeeded" apart from "1 of 25 succeeded" — both used to come back as a
 * bare `1`, which the Titles list page then rendered as a green success
 * notice even when most of the year failed to re-resolve. $total is the
 * number of rows RevalidationService actually attempted (i.e.
 * SquadRepository::findResolvableByTitle()'s result for the year — a
 * `manual` row is excluded before it is ever attempted, per LINK-10), not
 * every squad row the title has.
 *
 * $directoryErrorRowIds (item 6) are the ids of rows whose resolution threw
 * PlayerDirectoryQueryException — a distinct failure mode from a plain
 * applyResolution() write failure, kept visible instead of silently folded
 * into "did not succeed" with no way to tell which rows broke and why.
 */
final class RevalidationResult {

    /**
     * @param int[] $directoryErrorRowIds
     */
    public function __construct(
        public readonly int $succeeded,
        public readonly int $total,
        public readonly array $directoryErrorRowIds = []
    ) {
    }
}

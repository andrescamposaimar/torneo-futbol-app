<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Rest;

/**
 * Seam over "does this registered player have a photo" (design §7, ADR-C2
 * round-7 revision). Narrow and read-time, unlike PlayerDirectoryInterface
 * (design §4), which is an admin-side, import-time name index over the
 * whole directory — hanging the photo lookup off that interface would
 * couple two unrelated concerns and put the matcher's cost on the reader's
 * path (see the design's rejected-alternatives note).
 */
interface PlayerPhotoProviderInterface {

    /**
     * Batched lookup — one call for every distinct linked player id across
     * the whole response, never one call per squad entry.
     *
     * @param int[] $playerIds
     * @return array<int, string|null> id => absolute 'medium'-size URL, or
     *                                 null when the player has no photo.
     */
    public function findPhotoUrls( array $playerIds ): array;
}

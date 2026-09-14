<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Rest;

/**
 * The only WordPress-coupled file in the Rest domain (design §7, ADR-C2
 * round-7 revision) — the `WpPlayerDirectory` precedent. Its `postmeta`
 * query, `_prime_post_caches()` and `wp_get_attachment_image_url()` calls
 * need real attachments and a real object cache; the SQLite test shim has
 * neither, so this class is NOT unit-tested directly (design §9 states this
 * explicitly). `PlayerPhotoProviderInterface` is exercised exhaustively with
 * a fake (FakePlayerPhotoProvider) in HistoryControllerTest; the live
 * behaviour is confirmed once by hand against staging — see
 * STAGING-VERIFICATION-SLICE7.md.
 */
final class WpPlayerPhotoProvider implements PlayerPhotoProviderInterface {

    public function __construct( private readonly \wpdb $wpdb ) {
    }

    public function findPhotoUrls( array $playerIds ): array {
        $playerIds = array_values( array_unique( array_filter(
            $playerIds,
            static fn ( int $id ): bool => $id > 0
        ) ) );

        if ( [] === $playerIds ) {
            return [];
        }

        $wpdb         = $this->wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $playerIds ), '%d' ) );

        // One query for every distinct linked id on the whole response —
        // never one query per squad entry (design §7's explicit rejection
        // of a per-entry get_the_post_thumbnail_url() call: ~220 entries
        // across 17 years would be ~440 uncached meta lookups on the one
        // request that then populates a 30-day cache).
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                  WHERE meta_key = '_thumbnail_id' AND post_id IN ({$placeholders})",
                $playerIds
            ),
            ARRAY_A
        );

        $attachmentIdByPlayerId = [];
        foreach ( $rows as $row ) {
            $attachmentIdByPlayerId[ (int) $row['post_id'] ] = (int) $row['meta_value'];
        }

        if ( [] === $attachmentIdByPlayerId ) {
            return [];
        }

        // Primes the post cache for every attachment in one pass so the
        // per-entry wp_get_attachment_image_url() calls below issue no
        // further queries — the same batching shape as WpPlayerDirectory's
        // season/current-team lookups (design §4).
        if ( function_exists( '_prime_post_caches' ) ) {
            _prime_post_caches( array_values( $attachmentIdByPlayerId ), false, false );
        }

        $urlByPlayerId = [];
        foreach ( $attachmentIdByPlayerId as $playerId => $attachmentId ) {
            $url = wp_get_attachment_image_url( $attachmentId, 'medium' );
            // get_the_post_thumbnail_url()/wp_get_attachment_image_url()
            // return false (never null) when there is no image — coerce to
            // null here so every caller of this seam only ever sees a
            // string or null, never a boolean.
            $urlByPlayerId[ $playerId ] = ( is_string( $url ) && '' !== $url ) ? $url : null;
        }

        return $urlByPlayerId;
    }
}

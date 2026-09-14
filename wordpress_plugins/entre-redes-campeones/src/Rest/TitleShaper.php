<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Rest;

use EntreRedes\Campeones\Titles\SquadEntry;
use EntreRedes\Campeones\Titles\TitleRecord;

/**
 * Pure row -> array mapping for the champions REST responses (design §7),
 * the `MatchShaper` precedent from entre-redes-prode: no `$wpdb`, no
 * WordPress function, no HTTP request — response shape is unit-testable on
 * its own.
 *
 * VERSION-SKEW OBLIGATION: the response shapes below are cached for up to
 * 30 days as opaque values (HistoryController::CACHE_KEY /
 * PlayerTitlesController::CACHE_PREFIX, both suffixed `_v2`/`_v1`, and
 * mirrored in CacheInvalidator's own key constants). Whoever changes this
 * class's output shape — adds, removes, or renames a field, changes a
 * field's type — MUST bump the version suffix on the affected key in ALL
 * THREE files, or a live transient written under the old shape will keep
 * serving stale-shaped data to the app for up to 30 days after deploy. This
 * has already happened once (the history key is on `_v2`).
 */
final class TitleShaper {

    /**
     * API-1 / API-3 — one full-history entry. `$photoUrlsById` is the
     * batched lookup result from PlayerPhotoProviderInterface::findPhotoUrls()
     * (round-7, ADR-C2), keyed by jugador_id.
     *
     * @param SquadEntry[]                    $squad
     * @param array<int, string|null|false>   $photoUrlsById
     */
    public static function shapeHistoryEntry( TitleRecord $title, array $squad, array $photoUrlsById = [] ): array {
        return [
            'anio'          => $title->anio,
            'zona'          => $title->zona,
            'posicion'      => $title->posicion,
            'equipo_nombre' => $title->equipoNombre,
            'plantel'       => array_map(
                static fn ( SquadEntry $entry ): array => self::shapeSquadEntry( $entry, $photoUrlsById ),
                $squad
            ),
        ];
    }

    /**
     * @param array<int, string|null|false> $photoUrlsById
     */
    private static function shapeSquadEntry( SquadEntry $entry, array $photoUrlsById ): array {
        // An unlinked entry's jugador_id is null — it must never be used as
        // a map key (array_key_exists(null, ...) would coerce to the empty
        // string and could collide with a real key on some PHP versions).
        $fotoUrl = null;
        if ( null !== $entry->jugadorId && array_key_exists( $entry->jugadorId, $photoUrlsById ) ) {
            $raw = $photoUrlsById[ $entry->jugadorId ];
            // get_the_post_thumbnail_url() returns false (never null) for a
            // post with no thumbnail — coerce any non-string or empty-string
            // value to null so the wire format never carries a boolean in a
            // nullable-string slot.
            $fotoUrl = ( is_string( $raw ) && '' !== $raw ) ? $raw : null;
        }

        return [
            'nombre'     => $entry->jugadorNombre,
            'es_capitan' => $entry->esCapitan,
            'jugador_id' => $entry->jugadorId,
            // Item 7 (product decision): estado_vinculo (auto / ambiguo /
            // sin_candidato / manual) is internal diagnostic state
            // describing how confident the matching pipeline was.
            // Publishing it tells any anonymous caller about the data
            // quality of the record, and no consumer needs it — the app
            // derives everything it needs from whether jugador_id is
            // present, which already travels. Deliberately absent.
            'foto_url'   => $fotoUrl,
        ];
    }

    /**
     * API-2 — one entry of the per-player titles response. Deliberately
     * carries no photo field (design §7's explicit decision: the panel this
     * feeds is text-only and the profile already shows that player's own
     * photo at the top of the screen).
     *
     * @param array<string, mixed> $row {anio, equipo_nombre, es_capitan}
     */
    public static function shapePlayerTitulo( array $row ): array {
        return [
            'anio'          => (int) $row['anio'],
            'equipo_nombre' => (string) $row['equipo_nombre'],
            'es_capitan'    => (bool) $row['es_capitan'],
        ];
    }
}

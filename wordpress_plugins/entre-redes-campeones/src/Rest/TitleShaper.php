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
            'nombre'         => $entry->jugadorNombre,
            'es_capitan'     => $entry->esCapitan,
            'jugador_id'     => $entry->jugadorId,
            'estado_vinculo' => $entry->estadoVinculo,
            'foto_url'       => $fotoUrl,
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

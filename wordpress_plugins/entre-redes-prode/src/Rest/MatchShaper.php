<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Rest;

/**
 * Shared match-shaping helper used by FechaController and FechaListController.
 *
 * Encapsulates the public contract for a single match object in API responses:
 *   { match_id, home_team, away_team, kickoff, zona, home_escudo, away_escudo }
 *
 * Extracting this to a shared class ensures:
 *   1. Both fecha-activa and GET /prode/fecha/{id} responses are IDENTICAL.
 *   2. A single code change keeps both in sync (DRY).
 *   3. Regression tests on FechaController prove the contract is stable.
 */
final class MatchShaper {

    /**
     * Shape a single enriched match row into the public API contract array.
     *
     * @param array<string, mixed> $m  Row from FechaResolver::enrichMatches() output.
     * @return array{match_id: int, home_team: string, away_team: string, kickoff: string, zona: string, home_escudo: string|null, away_escudo: string|null}
     */
    public static function shape( array $m ): array {
        return [
            'match_id'    => (int) ( $m['match_id'] ?? 0 ),
            'home_team'   => (string) ( $m['home_team'] ?? '' ),
            'away_team'   => (string) ( $m['away_team'] ?? '' ),
            'kickoff'     => (string) ( $m['match_kickoff'] ?? '' ),
            'zona'        => (string) ( $m['zona'] ?? '' ),
            'home_escudo' => isset( $m['home_escudo'] ) ? (string) $m['home_escudo'] : null,
            'away_escudo' => isset( $m['away_escudo'] ) ? (string) $m['away_escudo'] : null,
        ];
    }

    /**
     * Shape an array of enriched match rows.
     *
     * @param array<int, array<string, mixed>> $enrichedMatches
     * @return array<int, array{match_id: int, home_team: string, away_team: string, kickoff: string, zona: string, home_escudo: string|null, away_escudo: string|null}>
     */
    public static function shapeAll( array $enrichedMatches ): array {
        return array_map( [ self::class, 'shape' ], $enrichedMatches );
    }
}

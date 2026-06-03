<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Rest;

/**
 * Shared match-shaping helper used by FechaController and FechaListController.
 *
 * Encapsulates the public contract for a single match object in API responses:
 *   { match_id, home_team, away_team, kickoff, zona, home_escudo, away_escudo, populares }
 *
 * Extracting this to a shared class ensures:
 *   1. Both fecha-activa and GET /prode/fecha/{id} responses are IDENTICAL.
 *   2. A single code change keeps both in sync (DRY).
 *   3. Regression tests on FechaController prove the contract is stable.
 *
 * G6-c: populares percentages are embedded per match.
 *   - null when the fecha state is 'open' (gate enforced by callers).
 *   - null when no predictions exist for that match.
 *   - array{'1': float, 'X': float, '2': float} when state is locked/evaluated
 *     and predictions exist.
 */
final class MatchShaper {

    /**
     * Shape a single enriched match row into the public API contract array.
     *
     * @param array<string, mixed>                              $m               Row from FechaResolver::enrichMatches() output.
     * @param array<int, array{'1': float, 'X': float, '2': float}>|null $popularesByMatch Populares map keyed by match_id, or null (open/no data).
     * @return array{match_id: int, home_team: string, away_team: string, kickoff: string, zona: string, home_escudo: string|null, away_escudo: string|null, populares: array{'1': float, 'X': float, '2': float}|null}
     */
    public static function shape( array $m, ?array $popularesByMatch = null ): array {
        $matchId   = (int) ( $m['match_id'] ?? 0 );
        $populares = null;

        if ( null !== $popularesByMatch && isset( $popularesByMatch[ $matchId ] ) ) {
            $populares = $popularesByMatch[ $matchId ];
        }

        return [
            'match_id'    => $matchId,
            'home_team'   => (string) ( $m['home_team'] ?? '' ),
            'away_team'   => (string) ( $m['away_team'] ?? '' ),
            'kickoff'     => (string) ( $m['match_kickoff'] ?? '' ),
            'zona'        => (string) ( $m['zona'] ?? '' ),
            'home_escudo' => isset( $m['home_escudo'] ) ? (string) $m['home_escudo'] : null,
            'away_escudo' => isset( $m['away_escudo'] ) ? (string) $m['away_escudo'] : null,
            'populares'   => $populares,
        ];
    }

    /**
     * Shape an array of enriched match rows.
     *
     * @param array<int, array<string, mixed>>                              $enrichedMatches
     * @param array<int, array{'1': float, 'X': float, '2': float}>|null  $popularesByMatch Populares map keyed by match_id, or null (open/no data).
     * @return array<int, array{match_id: int, home_team: string, away_team: string, kickoff: string, zona: string, home_escudo: string|null, away_escudo: string|null, populares: array{'1': float, 'X': float, '2': float}|null}>
     */
    public static function shapeAll( array $enrichedMatches, ?array $popularesByMatch = null ): array {
        return array_map(
            static fn( array $m ): array => self::shape( $m, $popularesByMatch ),
            $enrichedMatches
        );
    }
}

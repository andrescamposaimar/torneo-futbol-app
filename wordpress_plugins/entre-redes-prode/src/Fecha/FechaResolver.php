<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Fecha;

/**
 * Resolves the next upcoming play-date by dispatching an internal REST request
 * to /entre-redes/v1/partidos-programados.
 *
 * The dispatcher is constructor-injected (ADR-G0-4) so tests can inject a stub
 * closure returning canned payloads without a real WP REST runtime.
 * The default closure wraps rest_do_request and is used in production.
 *
 * Team names (home_team / away_team) are mapped from the live payload for
 * in-memory use only — they are NOT persisted to the DB (ADR-G0-2 / ADR-P008).
 */
class FechaResolver {

    /** @var callable|null */
    private mixed $dispatcher;

    /**
     * @param callable|null $dispatcher  Closure that returns an array of match items.
     *                                   Default: wraps rest_do_request to /partidos-programados.
     */
    public function __construct( ?callable $dispatcher = null ) {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Resolve the next upcoming play-date and return all matches for that date.
     *
     * @param int $windowDays  Number of calendar days to include from the play-date.
     *                         Default: 1 (single matchday only).
     * @return array{play_date: string, matches: array, earliest_kickoff: string}|null
     *         null when no upcoming fixtures exist.
     */
    public function resolveNext( int $windowDays = 1 ): ?array {
        $items = $this->dispatch();

        if ( empty( $items ) ) {
            return null;
        }

        // Filter out already-played items (both goles non-null).
        $upcoming = array_filter( $items, static function ( array $item ): bool {
            return ! ( isset( $item['goles_local'] ) && null !== $item['goles_local']
                    && isset( $item['goles_visitante'] ) && null !== $item['goles_visitante'] );
        } );

        if ( empty( $upcoming ) ) {
            return null;
        }

        // Normalize items into match shape.
        $matches = [];
        foreach ( $upcoming as $item ) {
            if ( empty( $item['id'] ) || empty( $item['fecha'] ) ) {
                // Malformed item — skip silently.
                continue;
            }
            $hora    = ( isset( $item['hora'] ) && '' !== $item['hora'] ) ? $item['hora'] : '00:00';
            $matches[] = [
                'match_id'   => (int) $item['id'],
                'kickoff'    => $item['fecha'] . ' ' . $hora,
                'home_team'  => $item['equipo_local'] ?? '',
                'away_team'  => $item['equipo_visitante'] ?? '',
                '_fecha'     => $item['fecha'], // internal grouping key
            ];
        }

        if ( empty( $matches ) ) {
            return null;
        }

        // Pick the earliest fecha (play-date).
        $dates    = array_column( $matches, '_fecha' );
        $playDate = min( $dates );

        // Calculate the end of the window (inclusive).
        $windowEnd = ( new \DateTime( $playDate ) )
            ->modify( '+' . ( $windowDays - 1 ) . ' days' )
            ->format( 'Y-m-d' );

        // Keep only matches within [play_date, play_date + windowDays - 1].
        $grouped = array_values( array_filter( $matches, static function ( array $m ) use ( $playDate, $windowEnd ): bool {
            return $m['_fecha'] >= $playDate && $m['_fecha'] <= $windowEnd;
        } ) );

        // Remove internal grouping key.
        foreach ( $grouped as &$m ) {
            unset( $m['_fecha'] );
        }
        unset( $m );

        // Determine earliest kickoff among the grouped matches.
        $kickoffs       = array_column( $grouped, 'kickoff' );
        $earliestKickoff = min( $kickoffs );

        return [
            'play_date'        => $playDate,
            'matches'          => $grouped,
            'earliest_kickoff' => $earliestKickoff,
        ];
    }

    /**
     * Enrich persisted match rows with live team names from the dispatcher.
     *
     * The persisted rows contain match_id and match_kickoff but no team names
     * (ADR-G0-2). This method fetches the live payload, builds a map by
     * match_id, and merges the team names. Falls back to empty strings when
     * the live endpoint no longer lists a match.
     *
     * @param array<int, array{match_id: int, match_kickoff: string}> $persistedMatches
     * @return array<int, array{match_id: int, match_kickoff: string, home_team: string, away_team: string}>
     */
    public function enrichMatches( array $persistedMatches ): array {
        $items = $this->dispatch();

        // Build map: match_id => [home_team, away_team]
        $teamMap = [];
        foreach ( $items as $item ) {
            if ( empty( $item['id'] ) ) {
                continue;
            }
            $teamMap[ (int) $item['id'] ] = [
                'home_team' => $item['equipo_local'] ?? '',
                'away_team' => $item['equipo_visitante'] ?? '',
            ];
        }

        // Merge into persisted rows.
        $enriched = [];
        foreach ( $persistedMatches as $row ) {
            $matchId           = (int) ( $row['match_id'] ?? 0 );
            $names             = $teamMap[ $matchId ] ?? [ 'home_team' => '', 'away_team' => '' ];
            $row['home_team']  = $names['home_team'];
            $row['away_team']  = $names['away_team'];
            $enriched[]        = $row;
        }

        return $enriched;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Invoke the dispatcher and return the items array.
     * The default dispatcher calls rest_do_request; tests inject a stub.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dispatch(): array {
        if ( null !== $this->dispatcher ) {
            return $this->unwrapItems( (array) ( $this->dispatcher )() );
        }

        // Production default: internal WP REST dispatch.
        $request  = new \WP_REST_Request( 'GET', '/entre-redes/v1/partidos-programados' );
        $response = rest_do_request( $request );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $body = $response->get_data();
        return is_array( $body ) ? $this->unwrapItems( $body ) : [];
    }

    /**
     * Normalize the /partidos-programados payload to a plain list of match items.
     *
     * The endpoint wraps fixtures in an envelope: { total, items: [...] }.
     * Unwrap to the items list. A bare list (no 'items' key) is returned as-is
     * for forward-compatibility and for stubbed dispatchers in tests.
     *
     * @param array<mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function unwrapItems( array $payload ): array {
        if ( isset( $payload['items'] ) && is_array( $payload['items'] ) ) {
            return array_values( $payload['items'] );
        }

        return $payload;
    }
}

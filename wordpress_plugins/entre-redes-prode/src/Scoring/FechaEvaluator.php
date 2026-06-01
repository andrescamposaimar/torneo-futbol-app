<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Scoring;

use EntreRedes\Prode\Fecha\FechaRepository;
use EntreRedes\Prode\Predictions\PredictionRepository;

/**
 * Orchestrator for evaluating all predictions in a locked fecha (ADR-G3-1).
 *
 * Called from TWO entrypoints:
 *   - EvaluatorCron::run()        — automated cron pass
 *   - EvaluationController::handleEvaluate() — admin-triggered REST call
 *
 * Both entry points are thin adapters; all logic lives here, mirroring how
 * FechaResolver backs both FechaCreationCron and FechaController.
 *
 * Evaluation algorithm (design §3 pseudocode):
 *   1. Load fecha + match list from DB.
 *   2. Load all predictions for the fecha.
 *   3. Paginate /entre-redes/v1/partidos via injected dispatcher to build
 *      match_id → {realHome, realAway, isFinal} map (ADR-G3-5).
 *   4. For each (participant × matchId):
 *      - Not final → no_match_score (0 pts)
 *      - Final + no prediction → no_prediction (0 pts)
 *      - Final + prediction → ScoreCalculator::evaluate()
 *   5. After all upserts: if countUnscoredMatches==0 → flip fecha to 'evaluated'
 *      and fire prode_recompute_rankings_cron (ADR-G3-3, ADR-G3-7).
 *
 * Participants (ADR-G3-2):
 *   DISTINCT user_id in prode_predictions for this fecha_id only — NOT all
 *   registered prode_users. Zero-engagement users get no rows.
 *
 * Dispatcher seam:
 *   Injected callable(WP_REST_Request): WP_REST_Response. In production this
 *   wraps rest_do_request(); in tests it returns a canned envelope. The response
 *   data must be { total: int, items: [...] } envelope OR a bare list — mirrors
 *   FechaResolver::unwrapItems. Pagination reads `total` from the envelope data
 *   key (shim has no header() on WP_REST_Response).
 */
class FechaEvaluator {

    private ScoreRepository     $scoreRepo;
    private PredictionRepository $predRepo;
    private FechaRepository     $fechaRepo;
    /** @var callable */
    private $resultsDispatcher;

    public function __construct(
        ScoreRepository $scoreRepo,
        PredictionRepository $predRepo,
        FechaRepository $fechaRepo,
        callable $resultsDispatcher
    ) {
        $this->scoreRepo         = $scoreRepo;
        $this->predRepo          = $predRepo;
        $this->fechaRepo         = $fechaRepo;
        $this->resultsDispatcher = $resultsDispatcher;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Evaluate all predictions for the given fecha.
     *
     * Returns a summary array with counts for use by both the cron and the
     * admin REST endpoint.
     *
     * @param int $fechaId
     * @return array{fecha_id: int, total_matches: int, final_matches: int, pending_matches: int, scored_rows: int, fecha_state: string}
     */
    public function evaluateFecha( int $fechaId ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        // --- Step 1: load fecha row ------------------------------------------
        $fecha = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$p}prode_fechas WHERE id = %d LIMIT 1",
                $fechaId
            ),
            ARRAY_A
        );

        $emptySummary = [
            'fecha_id'       => $fechaId,
            'total_matches'  => 0,
            'final_matches'  => 0,
            'pending_matches' => 0,
            'scored_rows'    => 0,
            'fecha_state'    => '',
        ];

        if ( empty( $fecha ) ) {
            return $emptySummary;
        }

        $seasonId = (int) $fecha['season_id'];

        // --- Step 2: load match_ids for the fecha ----------------------------
        $matchRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT match_id FROM {$p}prode_fecha_matches WHERE fecha_id = %d",
                $fechaId
            ),
            ARRAY_A
        );

        $matchIds = array_map( static fn( array $r ) => (int) $r['match_id'], $matchRows );

        if ( empty( $matchIds ) ) {
            return array_merge( $emptySummary, [ 'fecha_state' => (string) $fecha['state'] ] );
        }

        // --- Step 3: load all predictions for the fecha ----------------------
        $predRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, user_id, match_id, score_home, score_away
                   FROM {$p}prode_predictions
                  WHERE fecha_id = %d",
                $fechaId
            ),
            ARRAY_A
        );

        // Index predictions by (user_id, match_id) for O(1) lookup.
        $predByUserMatch = [];
        foreach ( $predRows as $pred ) {
            $uid = (int) $pred['user_id'];
            $mid = (int) $pred['match_id'];
            $predByUserMatch[ $uid ][ $mid ] = [
                'id'         => (int) $pred['id'],
                'score_home' => (int) $pred['score_home'],
                'score_away' => (int) $pred['score_away'],
            ];
        }

        // Participants = DISTINCT user_id from predictions for this fecha (ADR-G3-2).
        $participants = array_keys( $predByUserMatch );

        if ( empty( $participants ) ) {
            // No engaged users → nothing to evaluate; fire hook and return.
            do_action( 'prode_recompute_rankings_cron' );
            return array_merge( $emptySummary, [
                'fecha_state' => (string) $fecha['state'],
            ] );
        }

        // --- Step 4: fetch results map from /partidos (paginated) ------------
        $resultsMap = $this->fetchResultsMap( $seasonId, $matchIds );

        // --- Step 5: upsert one row per (participant × matchId) --------------
        $calc      = new ScoreCalculator();
        $now       = current_time( 'mysql' );
        $scoredRows = 0;

        foreach ( $matchIds as $matchId ) {
            $r = $resultsMap[ $matchId ] ?? [ 'isFinal' => false, 'realHome' => 0, 'realAway' => 0 ];

            foreach ( $participants as $userId ) {
                $pred = $predByUserMatch[ $userId ][ $matchId ] ?? null;

                if ( ! $r['isFinal'] ) {
                    // Match not yet finished → no_match_score.
                    $this->scoreRepo->upsertScore(
                        $userId,
                        $fechaId,
                        $matchId,
                        $pred ? $pred['id'] : null,
                        0,
                        'no_match_score',
                        $now
                    );
                } elseif ( null === $pred ) {
                    // Match final, user did not predict → no_prediction.
                    $this->scoreRepo->upsertScore(
                        $userId,
                        $fechaId,
                        $matchId,
                        null,
                        0,
                        'no_prediction',
                        $now
                    );
                } else {
                    // Match final, user predicted → compute score.
                    $result = $calc->evaluate(
                        $pred['score_home'],
                        $pred['score_away'],
                        $r['realHome'],
                        $r['realAway']
                    );
                    $this->scoreRepo->upsertScore(
                        $userId,
                        $fechaId,
                        $matchId,
                        $pred['id'],
                        $result['points'],
                        $result['method'],
                        $now
                    );
                }

                $scoredRows++;
            }
        }

        // --- Step 6: conditional state flip (ADR-G3-7) -----------------------
        $pending = $this->scoreRepo->countUnscoredMatches( $fechaId );

        if ( 0 === $pending ) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$p}prode_fechas SET state = 'evaluated', evaluated_at = %s WHERE id = %d",
                    $now,
                    $fechaId
                )
            );
            $newState = 'evaluated';
        } else {
            $newState = (string) $fecha['state'];
        }

        // Fire ranking hook after every successful pass (ADR-G3-3, R5.1).
        do_action( 'prode_recompute_rankings_cron' );

        // Count final matches for summary.
        $finalMatches = count( array_filter(
            $resultsMap,
            static fn( array $r ) => $r['isFinal']
        ) );

        return [
            'fecha_id'        => $fechaId,
            'total_matches'   => count( $matchIds ),
            'final_matches'   => $finalMatches,
            'pending_matches' => $pending,
            'scored_rows'     => $scoredRows,
            'fecha_state'     => $newState,
        ];
    }

    // -------------------------------------------------------------------------
    // Internal — paginated results fetch (ADR-G3-5, design §2)
    // -------------------------------------------------------------------------

    /**
     * Fetch the /partidos result map for the given season, filtered to $matchIds.
     *
     * Paginates using the envelope `total` key (shim has no header() on
     * WP_REST_Response — using data key is forward-compatible with both test
     * stubs and production envelopes). Mirrors FechaResolver::unwrapItems.
     *
     * @param int   $seasonId  Season to filter by.
     * @param int[] $matchIds  Fecha match_ids to include in the map.
     * @return array<int, array{realHome: int, realAway: int, isFinal: bool}>
     */
    private function fetchResultsMap( int $seasonId, array $matchIds ): array {
        $allowedMatchIds = array_flip( $matchIds );
        $allItems        = [];
        $perPage         = 100;
        $page            = 1;
        $totalFetched    = 0;
        $total           = null;

        do {
            $request = new \WP_REST_Request( 'GET', '/entre-redes/v1/partidos' );
            $request->set_param( 'temporada', (string) $seasonId );
            $request->set_param( 'per_page', (string) $perPage );
            $request->set_param( 'page', (string) $page );

            $response = ( $this->resultsDispatcher )( $request );

            if ( is_wp_error( $response ) ) {
                break;
            }

            $data  = $response->get_data();
            $items = $this->unwrapItems( is_array( $data ) ? $data : [] );

            // Read total from envelope data key (primary path for both tests and production).
            if ( null === $total && is_array( $data ) ) {
                if ( isset( $data['total'] ) ) {
                    $total = (int) $data['total'];
                }
            }

            if ( empty( $items ) ) {
                break;
            }

            $pageCount     = count( $items );
            $allItems      = array_merge( $allItems, $items );
            $totalFetched += $pageCount;
            $page++;

            // Stop when we have fetched at least `total` items.
            if ( null !== $total && $totalFetched >= $total ) {
                break;
            }

            // Defensive stop: page returned fewer items than perPage AND
            // we have no `total` to rely on (e.g. header-only pagination).
            if ( null === $total && $pageCount < $perPage ) {
                break;
            }

        } while ( true );

        // Build match_id => {realHome, realAway, isFinal} map, filtered to fecha matches.
        $map = [];
        foreach ( $allItems as $item ) {
            $matchId = (int) ( $item['id'] ?? 0 );
            if ( ! isset( $allowedMatchIds[ $matchId ] ) ) {
                continue;
            }

            $golesLocal     = $item['goles_local'] ?? null;
            $golesVisitante = $item['goles_visitante'] ?? null;
            $isFinal        = ( null !== $golesLocal && null !== $golesVisitante );

            $map[ $matchId ] = [
                'realHome' => $isFinal ? (int) $golesLocal : 0,
                'realAway' => $isFinal ? (int) $golesVisitante : 0,
                'isFinal'  => $isFinal,
            ];
        }

        return $map;
    }

    /**
     * Normalize the /partidos payload to a plain list of match items.
     *
     * The endpoint wraps items in an envelope: { total, items: [...] }.
     * Unwrap to the items list. A bare list (no 'items' key) is returned as-is.
     * Mirrors FechaResolver::unwrapItems.
     *
     * @param array<mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function unwrapItems( array $payload ): array {
        if ( isset( $payload['items'] ) && is_array( $payload['items'] ) ) {
            return array_values( $payload['items'] );
        }

        // Bare list — return as-is.
        return array_values( array_filter( $payload, 'is_array' ) );
    }
}

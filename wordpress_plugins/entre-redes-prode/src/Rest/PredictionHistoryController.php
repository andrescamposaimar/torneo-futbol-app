<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Rest;

use EntreRedes\Prode\Auth\AuthMiddleware;
use EntreRedes\Prode\Predictions\PredictionRepository;

/**
 * REST controller for GET /prode/predicciones.
 *
 * Returns the authenticated caller's predictions for FINISHED matches as a
 * flat, paginated, most-recent-first list. This powers the app's "Anteriores"
 * (past predictions) infinite-scroll tab, which loads pages of 15.
 *
 * Auth: requireAuth (mirrors PredictionController). The caller's prode user id
 * is read from the _prode_user request param attached by the middleware.
 *
 * Params:
 *   page     (optional int) — default 1; non-numeric or < 1 → 400.
 *   per_page (optional int) — default 15; non-numeric or > MAX_PER_PAGE (50) → 400.
 *
 * Item shape (mirrors the match contract in MatchShaper + the user_prediction
 * fields, so the client can reuse its finished-match card rendering):
 *   {
 *     fecha_id, season_id, match_id,
 *     kickoff, zona, home_team, away_team, home_escudo, away_escudo,
 *     score_home, score_away,                 // the caller's prediction
 *     real_score_home, real_score_away, is_final,
 *     points, evaluation_method               // null until the fecha is evaluated
 *   }
 *
 * Envelope: { items:[...], total:int, page:int, per_page:int }.
 */
class PredictionHistoryController {

    private const NAMESPACE    = 'entre-redes/v1';
    private const MAX_PER_PAGE = 50;
    private const DEFAULT_PER_PAGE = 15;

    public function __construct(
        private PredictionRepository $predRepo,
        private AuthMiddleware       $middleware
    ) {}

    /**
     * Register the GET /prode/predicciones route.
     *
     * requireAuth is the permission callback so _prode_user is attached before
     * the handler runs (mirrors PredictionController).
     */
    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE,
            '/prode/predicciones',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'getHistory' ],
                'permission_callback' => [ $this->middleware, 'requireAuth' ],
            ]
        );
    }

    /**
     * Delegate to AuthMiddleware::requireAuth for tests that call it directly.
     *
     * @param \WP_REST_Request $request
     * @return true|\WP_Error
     */
    public function requireAuth( \WP_REST_Request $request ) {
        return $this->middleware->requireAuth( $request );
    }

    /**
     * GET /prode/predicciones
     *
     * @param \WP_REST_Request $request  Request with _prode_user already set.
     * @return \WP_REST_Response
     */
    public function getHistory( \WP_REST_Request $request ): \WP_REST_Response {
        // ── Parameter validation (mirrors RankingController) ──────────────────

        $rawPage    = $request->get_param( 'page' );
        $rawPerPage = $request->get_param( 'per_page' );

        if ( null !== $rawPage ) {
            if ( ! is_numeric( $rawPage ) ) {
                return new \WP_REST_Response( [ 'error' => 'invalid_params' ], 400 );
            }
            $page = (int) $rawPage;
            if ( $page < 1 ) {
                return new \WP_REST_Response( [ 'error' => 'invalid_params' ], 400 );
            }
        } else {
            $page = 1;
        }

        if ( null !== $rawPerPage ) {
            if ( ! is_numeric( $rawPerPage ) ) {
                return new \WP_REST_Response( [ 'error' => 'invalid_params' ], 400 );
            }
            $perPage = (int) $rawPerPage;
            if ( $perPage < 1 || $perPage > self::MAX_PER_PAGE ) {
                return new \WP_REST_Response( [ 'error' => 'invalid_params' ], 400 );
            }
        } else {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        // ── Caller resolution ────────────────────────────────────────────────

        $user   = $request->get_param( '_prode_user' );
        $userId = (int) ( $user['id'] ?? 0 );

        // ── Fetch + shape ─────────────────────────────────────────────────────

        $total  = $this->predRepo->countFinishedByUser( $userId );
        $offset = ( $page - 1 ) * $perPage;
        $rows   = $this->predRepo->findFinishedByUserPaginated( $userId, $perPage, $offset );

        $items = array_map( [ self::class, 'shapeItem' ], $rows );

        return new \WP_REST_Response(
            [
                'items'    => $items,
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
            ],
            200
        );
    }

    /**
     * Shape a raw repository row into the public item contract.
     *
     * is_final is always truthy here (filtered in the query), so real scores are
     * always exposed — there is no live-score leak risk for finished matches.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shapeItem( array $row ): array {
        return [
            'fecha_id'        => (int) ( $row['fecha_id'] ?? 0 ),
            'season_id'       => (int) ( $row['season_id'] ?? 0 ),
            'match_id'        => (int) ( $row['match_id'] ?? 0 ),
            'kickoff'         => (string) ( $row['match_kickoff'] ?? '' ),
            'zona'            => (string) ( $row['zona'] ?? '' ),
            'home_team'       => (string) ( $row['home_team'] ?? '' ),
            'away_team'       => (string) ( $row['away_team'] ?? '' ),
            'home_escudo'     => isset( $row['home_escudo'] ) && '' !== $row['home_escudo'] ? (string) $row['home_escudo'] : null,
            'away_escudo'     => isset( $row['away_escudo'] ) && '' !== $row['away_escudo'] ? (string) $row['away_escudo'] : null,
            'score_home'      => (int) ( $row['score_home'] ?? 0 ),
            'score_away'      => (int) ( $row['score_away'] ?? 0 ),
            'real_score_home' => isset( $row['real_score_home'] ) ? (int) $row['real_score_home'] : null,
            'real_score_away' => isset( $row['real_score_away'] ) ? (int) $row['real_score_away'] : null,
            'is_final'        => true,
            'points'          => isset( $row['points'] ) ? (int) $row['points'] : null,
            'evaluation_method' => isset( $row['evaluation_method'] ) ? (string) $row['evaluation_method'] : null,
        ];
    }
}

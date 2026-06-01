<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Rest;

use EntreRedes\Prode\Auth\AuthMiddleware;
use EntreRedes\Prode\Fecha\Settings;
use EntreRedes\Prode\Scoring\RankingComputer;
use EntreRedes\Prode\Scoring\RankingRepository;

/**
 * REST controller for GET /prode/ranking.
 *
 * Auth: optionalAuth (mirrors FechaController — ADR-G4-5).
 *   Anonymous → 200, all is_me=false. Authenticated → own row has is_me=true.
 *   The _prode_user request param is set by AuthMiddleware::optionalAuth() in
 *   production; in tests it is injected directly.
 *
 * Params:
 *   temporada  (optional int)  — season ID; defaults to Settings::seasonId().
 *   fecha_id   (optional int)  — switches to per-fecha view (reads prode_ranking_fecha_cache).
 *   page       (optional int)  — default 1; page < 1 → 400.
 *   per_page   (optional int)  — default 50; > MAX_PER_PAGE (100) → 400 (TASK-0 pin C).
 *
 * Season view:  aggregateBySeason → RankingComputer.assignRanks → paginate.
 * Per-fecha view: findFechaCache (stored ranks) + aggregateByFecha (for exact_count) → paginate.
 *
 * Row shape: { user_id:int, display_name:string, total_points:int, rank:int, exact_count:int, is_me:bool }.
 * Envelope: { items:[...], total:int, page:int, per_page:int }.
 *
 * Mirrors FechaController structure and PredictionController/EvaluationController constructor pattern.
 */
class RankingController {

    private const NAMESPACE   = 'entre-redes/v1';
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private RankingRepository $repo,
        private RankingComputer   $computer,
        private Settings          $settings,
        private ?AuthMiddleware   $middleware = null
    ) {}

    /**
     * Register the GET /prode/ranking route.
     * Called by RestController::register_routes() via the nullable slot pattern.
     */
    public function register_routes(): void {
        $permissionCallback = null !== $this->middleware
            ? [ $this->middleware, 'optionalAuth' ]
            : '__return_true';

        register_rest_route(
            self::NAMESPACE,
            '/prode/ranking',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'getRanking' ],
                'permission_callback' => $permissionCallback,
            ]
        );
    }

    /**
     * GET /prode/ranking
     *
     * Returns a paginated, ranked leaderboard for the requested season or fecha.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function getRanking( \WP_REST_Request $request ): \WP_REST_Response {
        // ── Parameter validation ────────────────────────────────────────────

        $rawPage    = $request->get_param( 'page' );
        $rawPerPage = $request->get_param( 'per_page' );
        $rawFechaId = $request->get_param( 'fecha_id' );

        // page: must be numeric (or null → default 1), and >= 1.
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

        // per_page: must be numeric (or null → default 50), and <= MAX_PER_PAGE.
        if ( null !== $rawPerPage ) {
            if ( ! is_numeric( $rawPerPage ) ) {
                return new \WP_REST_Response( [ 'error' => 'invalid_params' ], 400 );
            }
            $perPage = (int) $rawPerPage;
            if ( $perPage > self::MAX_PER_PAGE ) {
                return new \WP_REST_Response( [ 'error' => 'invalid_params' ], 400 );
            }
        } else {
            $perPage = 50;
        }

        // fecha_id: if present must be numeric.
        if ( null !== $rawFechaId ) {
            if ( ! is_numeric( $rawFechaId ) ) {
                return new \WP_REST_Response( [ 'error' => 'invalid_params' ], 400 );
            }
            $fechaId = (int) $rawFechaId;
        } else {
            $fechaId = null;
        }

        // ── Season resolution ────────────────────────────────────────────────

        $rawTemporada = $request->get_param( 'temporada' );
        $seasonId     = null !== $rawTemporada
            ? (int) $rawTemporada
            : $this->settings->seasonId();

        // ── Build ranked rows ────────────────────────────────────────────────

        if ( null !== $fechaId ) {
            // Per-fecha view: read stored cache (ranks written by RankingCron).
            $cacheRows = $this->repo->findFechaCache( $fechaId );

            // Recompute exact_count for payload (no column in cache — Assumption A3).
            $aggRows  = $this->repo->aggregateByFecha( $fechaId );
            $exactMap = [];
            foreach ( $aggRows as $agg ) {
                $exactMap[ (int) $agg['user_id'] ] = (int) $agg['exact_count'];
            }

            $rows = array_map( static function ( array $row ) use ( $exactMap ): array {
                $uid = (int) $row['user_id'];
                return [
                    'user_id'      => $uid,
                    'total_points' => (int) $row['total_points'],
                    'rank'         => (int) $row['rank'],
                    'exact_count'  => $exactMap[ $uid ] ?? 0,
                ];
            }, $cacheRows );
        } else {
            // Season view: aggregate on-read and rank in PHP.
            $aggRows = $this->repo->aggregateBySeason( $seasonId );
            $rows    = $this->computer->assignRanks( $aggRows );
        }

        // ── Pagination ───────────────────────────────────────────────────────

        $total  = count( $rows );
        $offset = ( $page - 1 ) * $perPage;
        $slice  = array_slice( $rows, $offset, $perPage );

        // ── Display names ────────────────────────────────────────────────────

        $userIds = array_map( static fn( array $r ): int => (int) $r['user_id'], $slice );
        $names   = $this->repo->resolveDisplayNames( $userIds );

        // ── is_me resolution ─────────────────────────────────────────────────

        $prodeUser = $request->get_param( '_prode_user' );
        $meId      = isset( $prodeUser['id'] ) ? (int) $prodeUser['id'] : null;

        // ── Shape items ─────────────────────────────────────────────────────

        $items = array_map( static function ( array $row ) use ( $names, $meId ): array {
            $uid = (int) $row['user_id'];
            return [
                'user_id'      => $uid,
                'display_name' => $names[ $uid ] ?? '',
                'total_points' => (int) $row['total_points'],
                'rank'         => (int) $row['rank'],
                'exact_count'  => (int) $row['exact_count'],
                'is_me'        => $meId !== null && $uid === $meId,
            ];
        }, $slice );

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
}

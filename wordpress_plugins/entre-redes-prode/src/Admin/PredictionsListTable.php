<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Admin;

/**
 * WP_List_Table subclass for the Predictions admin page (detail view).
 *
 * Read-only display (B-admin): no write actions, no POST handlers.
 * Design: render-time class_exists(WP_List_Table) guard applied in PredictionsPage.
 *
 * Columns per spec (capability B — per-user detail):
 *   fecha_id, home_team, away_team, predicted_score, real_score, points,
 *   evaluation_method.
 *
 * No wp_users JOIN anywhere (design constraint — CC-06).
 */
class PredictionsListTable extends \WP_List_Table {

    private const PER_PAGE = 25;

    private const EVALUATION_METHOD_LABELS = [
        'exact_score'    => 'Exact score',
        'result_only'    => 'Result only',
        'no_prediction'  => 'No prediction',
        'no_match_score' => 'No match score',
    ];

    /** @var array<int, array<string, mixed>> */
    private array $items_data = [];

    private int $total_items_count = 0;

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function setData( array $items, int $total ): void {
        $this->items_data        = $items;
        $this->total_items_count = $total;
        $this->items             = $items;
    }

    /** @return array<string, string> */
    public function get_columns(): array {
        return [
            'fecha_id'          => __( 'Fecha', 'entre-redes-prode' ),
            'home_team'         => __( 'Home', 'entre-redes-prode' ),
            'away_team'         => __( 'Away', 'entre-redes-prode' ),
            'predicted_score'   => __( 'Predicted score', 'entre-redes-prode' ),
            'real_score'        => __( 'Real score', 'entre-redes-prode' ),
            'points'            => __( 'Points', 'entre-redes-prode' ),
            'evaluation_method' => __( 'Method', 'entre-redes-prode' ),
        ];
    }

    /** @return array<string, string> */
    protected function get_sortable_columns(): array {
        return [];
    }

    /** @return array<string, string> */
    protected function get_hidden_columns(): array {
        return [];
    }

    public function prepare_items(): void {
        $this->_column_headers = [
            $this->get_columns(),
            $this->get_hidden_columns(),
            $this->get_sortable_columns(),
        ];

        $this->set_pagination_args( [
            'total_items' => $this->total_items_count,
            'per_page'    => self::PER_PAGE,
            'total_pages' => (int) ceil( $this->total_items_count / self::PER_PAGE ),
        ] );
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function column_default( $item, $column_name ): string {
        $val = $item[ $column_name ] ?? null;
        if ( $val === null || $val === '' ) {
            return '—';
        }
        return esc_html( (string) $val );
    }

    /**
     * Renders "home_score - away_score" from the user's predicted scores.
     *
     * @param array<string, mixed> $item
     */
    protected function column_predicted_score( $item ): string {
        $home = $item['score_home'] ?? null;
        $away = $item['score_away'] ?? null;

        if ( $home === null || $away === null ) {
            return '—';
        }

        return esc_html( (int) $home . ' - ' . (int) $away );
    }

    /**
     * Renders "real_score_home - real_score_away", or '—' when not available.
     *
     * @param array<string, mixed> $item
     */
    protected function column_real_score( $item ): string {
        $home    = $item['real_score_home'] ?? null;
        $away    = $item['real_score_away'] ?? null;

        if ( $home === null || $away === null ) {
            return '—';
        }

        return esc_html( (int) $home . ' - ' . (int) $away );
    }

    /**
     * Renders the numeric points value, or '—' when null (pre-evaluation).
     *
     * @param array<string, mixed> $item
     */
    protected function column_points( $item ): string {
        $val = $item['points'] ?? null;

        if ( $val === null ) {
            return '—';
        }

        return esc_html( (string) (int) $val );
    }

    /**
     * Maps evaluation_method slug to a human-readable English label.
     *
     * @param array<string, mixed> $item
     */
    protected function column_evaluation_method( $item ): string {
        $slug = $item['evaluation_method'] ?? null;

        if ( $slug === null || $slug === '' ) {
            return '—';
        }

        $label = self::EVALUATION_METHOD_LABELS[ (string) $slug ] ?? (string) $slug;
        return esc_html( $label );
    }

    public function no_items(): void {
        esc_html_e( 'No predictions found for this user.', 'entre-redes-prode' );
    }
}

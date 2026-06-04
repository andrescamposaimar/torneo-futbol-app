<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Admin;

/**
 * WP_List_Table subclass for the player registry admin page.
 *
 * Design D2: WP_List_Table is required via a guard so the file remains
 * loadable in the test context (where WP_List_Table does not exist).
 * This class is instantiated only inside RegistryPage::render(), which is
 * not unit-tested.
 *
 * Columns per REG-02: id, display_name, email, provider, dni,
 * player_name (from player_id row), created_at, last_login_at, status, actions.
 *
 * Pagination: 25 rows per page (REG-03). Tab filter: Activos/Eliminados (REG-04).
 */
class RegistryListTable extends \WP_List_Table {

    /** @var array<int, array<string, mixed>> */
    private array $items_data = [];

    private int $total_items_count = 0;

    private string $activeFilter = 'active';

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function setData( array $items, int $total, string $activeFilter ): void {
        $this->items_data         = $items;
        $this->total_items_count  = $total;
        $this->activeFilter       = $activeFilter;
        $this->items              = $items;
    }

    /** @return array<string, string> */
    public function get_columns(): array {
        return [
            'id'            => __( 'ID', 'entre-redes-prode' ),
            'display_name'  => __( 'Nombre', 'entre-redes-prode' ),
            'email'         => __( 'Email', 'entre-redes-prode' ),
            'provider'      => __( 'Proveedor', 'entre-redes-prode' ),
            'dni'           => __( 'DNI', 'entre-redes-prode' ),
            'player_name'   => __( 'Jugador', 'entre-redes-prode' ),
            'created_at'    => __( 'Creado', 'entre-redes-prode' ),
            'last_login_at' => __( 'Último login', 'entre-redes-prode' ),
            'status'        => __( 'Estado', 'entre-redes-prode' ),
            'actions'       => __( 'Acciones', 'entre-redes-prode' ),
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
            'per_page'    => 25,
            'total_pages' => (int) ceil( $this->total_items_count / 25 ),
        ] );
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function column_default( $item, $column_name ): string {
        return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function column_status( $item ): string {
        $deleted = ! empty( $item['deleted_at'] );
        return $deleted
            ? esc_html__( 'Eliminado', 'entre-redes-prode' )
            : esc_html__( 'Activo', 'entre-redes-prode' );
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function column_player_name( $item ): string {
        // The query does not JOIN wp_jugadores; player_name is not available
        // directly — we display player_id as identifier. If a display_name
        // is stored in the association it would appear here; absent → show ID.
        $val = $item['player_id'] ?? '';
        return $val !== '' ? esc_html( (string) $val ) : '—';
    }

    /**
     * Renders the Desvincular action link (REG-05, REG-06, REG-08).
     *
     * Only shown when the user has an active association (assoc_deleted_at IS NULL).
     *
     * @param array<string, mixed> $item
     */
    protected function column_actions( $item ): string {
        // REG-05: show action only when active association exists.
        $hasActiveAssoc = array_key_exists( 'assoc_deleted_at', $item ) && $item['assoc_deleted_at'] === null;

        if ( ! $hasActiveAssoc ) {
            return '—';
        }

        $userId     = (int) $item['id'];
        $playerName = esc_js( (string) ( $item['player_id'] ?? '' ) );
        $nonce      = wp_create_nonce( 'prode_unlink_' . $userId );
        $adminUrl   = admin_url( 'admin.php?page=prode-registry' );

        // REG-08: JS confirm with player name.
        $confirmMsg = sprintf(
            /* translators: player identifier */
            __( '¿Confirmás que querés desvincular al jugador %s? Esta acción no se puede deshacer.', 'entre-redes-prode' ),
            $playerName
        );

        $formId = 'prode-unlink-form-' . $userId;
        $html   = sprintf(
            '<form id="%s" method="post" action="%s" style="display:inline;">'
            . '<input type="hidden" name="prode_action" value="unlink_user">'
            . '<input type="hidden" name="prode_user_id" value="%d">'
            . '<input type="hidden" name="prode_unlink_nonce" value="%s">'
            . '</form>'
            . '<a href="#" onclick="if(confirm(\'%s\')){document.getElementById(\'%s\').submit();} return false;" class="submitdelete">%s</a>',
            esc_attr( $formId ),
            esc_url( $adminUrl ),
            $userId,
            esc_attr( $nonce ),
            esc_js( $confirmMsg ),
            esc_attr( $formId ),
            esc_html__( 'Desvincular', 'entre-redes-prode' )
        );

        return $html;
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function column_cb( $item ): string {
        return '';
    }

    public function no_items(): void {
        esc_html_e( 'No se encontraron jugadores registrados.', 'entre-redes-prode' );
    }
}

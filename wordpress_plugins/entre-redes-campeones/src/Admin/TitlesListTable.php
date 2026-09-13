<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Admin;

/**
 * WP_List_Table subclass for the Titles admin page (slug: campeones).
 *
 * Required behind a class_exists guard at render time by TitlesPage — never
 * at file load — mirrors entre-redes-prode's RegistryListTable.
 *
 * Columns: año, zona, posición, equipo, acciones (Editar / Revalidar /
 * Eliminar). Design §6.
 */
class TitlesListTable extends \WP_List_Table {

    /** @var array<int, array<string, mixed>> */
    private array $itemsData = [];

    private int $totalItems = 0;

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function setData( array $items, int $total ): void {
        $this->itemsData = $items;
        $this->totalItems = $total;
        $this->items       = $items;
    }

    /** @return array<string, string> */
    public function get_columns(): array {
        return [
            'anio'          => __( 'Año', 'entre-redes-campeones' ),
            'zona'          => __( 'Zona', 'entre-redes-campeones' ),
            'posicion'      => __( 'Posición', 'entre-redes-campeones' ),
            'equipo_nombre' => __( 'Equipo', 'entre-redes-campeones' ),
            'acciones'      => __( 'Acciones', 'entre-redes-campeones' ),
        ];
    }

    public function prepare_items(): void {
        $this->_column_headers = [ $this->get_columns(), [], [] ];

        $this->set_pagination_args( [
            'total_items' => $this->totalItems,
            'per_page'    => 25,
            'total_pages' => (int) ceil( $this->totalItems / 25 ),
        ] );
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function column_default( $item, $column_name ): string {
        return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
    }

    /**
     * Editar / Revalidar / Eliminar, one per row. Editar links straight to
     * the hidden campeones-titulo-edit subpage; Revalidar and Eliminar are
     * one-click forms guarded by their own per-row nonce (LINK-9/ADMIN-7).
     *
     * @param array<string, mixed> $item
     */
    protected function column_acciones( $item ): string {
        $tituloId = (int) $item['id'];
        $editUrl  = admin_url( 'admin.php?page=campeones-titulo-edit&titulo_id=' . $tituloId );
        $adminUrl = admin_url( 'admin.php?page=campeones' );

        $editLink = sprintf(
            '<a href="%s">%s</a>',
            esc_url( $editUrl ),
            esc_html__( 'Editar', 'entre-redes-campeones' )
        );

        $revalidarForm = ActionForm::render(
            'campeones_titulo_action',
            'revalidar',
            $adminUrl,
            [ 'titulo_id' => $tituloId ],
            'campeones_titulo_nonce',
            wp_create_nonce( 'campeones_revalidar_' . $tituloId ),
            __( 'Revalidar', 'entre-redes-campeones' )
        );

        $eliminarForm = ActionForm::render(
            'campeones_titulo_action',
            'eliminar',
            $adminUrl,
            [ 'titulo_id' => $tituloId ],
            'campeones_titulo_nonce',
            wp_create_nonce( 'campeones_eliminar_titulo_' . $tituloId ),
            __( 'Eliminar', 'entre-redes-campeones' ),
            '',
            __( '¿Eliminar este título y todo su plantel? Esta acción no se puede deshacer.', 'entre-redes-campeones' ),
            'button-link submitdelete'
        );

        return $editLink . ' | ' . $revalidarForm . ' | ' . $eliminarForm;
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function column_cb( $item ): string {
        return '';
    }

    public function no_items(): void {
        esc_html_e( 'Todavía no hay títulos cargados.', 'entre-redes-campeones' );
    }
}

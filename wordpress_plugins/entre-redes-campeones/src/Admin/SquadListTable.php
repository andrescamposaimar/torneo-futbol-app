<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Admin;

use EntreRedes\Campeones\Linking\LinkState;

/**
 * WP_List_Table subclass for one title's squad, on the hidden
 * campeones-titulo-edit subpage (design §6).
 *
 * Columns: orden, jugador (name + captain marker), estado, acciones (Editar
 * / Eliminar the row, plus Vincular / Cambiar / Desvincular the link).
 *
 * The link control is a raw player-id field (task 3.4's own scope note —
 * ranked name search is slice 5), submitted alongside the per-row nonce
 * campeones_link_{plantelId} (design §6, LINK-8).
 */
class SquadListTable extends \WP_List_Table {

    /** @var array<int, array<string, mixed>> */
    private array $itemsData = [];

    private int $tituloId = 0;

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function setData( array $items, int $tituloId ): void {
        $this->itemsData = $items;
        $this->tituloId  = $tituloId;
        $this->items       = $items;
    }

    /** @return array<string, string> */
    public function get_columns(): array {
        return [
            'orden'          => __( 'Orden', 'entre-redes-campeones' ),
            'jugador_nombre' => __( 'Jugador', 'entre-redes-campeones' ),
            'estado_vinculo' => __( 'Estado', 'entre-redes-campeones' ),
            'acciones'       => __( 'Acciones', 'entre-redes-campeones' ),
        ];
    }

    public function prepare_items(): void {
        $this->_column_headers = [ $this->get_columns(), [], [] ];

        $this->set_pagination_args( [
            'total_items' => count( $this->itemsData ),
            'per_page'    => count( $this->itemsData ) ?: 1,
            'total_pages' => 1,
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
    protected function column_jugador_nombre( $item ): string {
        $name = esc_html( (string) $item['jugador_nombre'] );
        if ( ! empty( $item['es_capitan'] ) ) {
            $name .= ' ' . esc_html__( '(C)', 'entre-redes-campeones' );
        }
        return $name;
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function column_estado_vinculo( $item ): string {
        $labels = [
            LinkState::AUTO          => __( 'Automático', 'entre-redes-campeones' ),
            LinkState::AMBIGUO       => __( 'Ambiguo', 'entre-redes-campeones' ),
            LinkState::SIN_CANDIDATO => __( 'Sin candidato', 'entre-redes-campeones' ),
            LinkState::MANUAL        => __( 'Manual', 'entre-redes-campeones' ),
        ];

        $estado = (string) ( $item['estado_vinculo'] ?? '' );
        return esc_html( $labels[ $estado ] ?? $estado );
    }

    /**
     * Editar (name / captain / order) plus Eliminar the row, plus the link
     * control (Vincular / Cambiar / Desvincular). One nonce per row, keyed
     * to the row id (LINK-8), shared by every form below — editar_fila
     * included.
     *
     * @param array<string, mixed> $item
     */
    protected function column_acciones( $item ): string {
        $plantelId = (int) $item['id'];
        $nonce     = wp_create_nonce( 'campeones_link_' . $plantelId );
        $adminUrl  = admin_url( 'admin.php?page=campeones-titulo-edit&titulo_id=' . $this->tituloId );
        $isLinked  = ! empty( $item['jugador_id'] );
        $linkLabel = $isLinked ? __( 'Cambiar', 'entre-redes-campeones' ) : __( 'Vincular', 'entre-redes-campeones' );
        $linkAction = $isLinked ? 'cambiar' : 'vincular';

        $html = sprintf(
            '<form method="post" action="%1$s" style="display:inline;">'
            . '<input type="hidden" name="campeones_editor_action" value="editar_fila">'
            . '<input type="hidden" name="titulo_id" value="%7$d">'
            . '<input type="hidden" name="plantel_id" value="%2$d">'
            . '<input type="hidden" name="campeones_link_nonce" value="%3$s">'
            . '<input type="text" name="jugador_nombre" value="%4$s" style="width:10em;">'
            . '<label><input type="checkbox" name="es_capitan" value="1"%5$s> %6$s</label>'
            . '<input type="hidden" name="orden" value="%8$d">'
            . '<button type="submit" class="button-link">%9$s</button></form> ',
            esc_url( $adminUrl ),
            $plantelId,
            esc_attr( $nonce ),
            esc_attr( (string) ( $item['jugador_nombre'] ?? '' ) ),
            ! empty( $item['es_capitan'] ) ? ' checked' : '',
            esc_html__( 'Capitán', 'entre-redes-campeones' ),
            $this->tituloId,
            (int) ( $item['orden'] ?? 0 ),
            esc_html__( 'Guardar', 'entre-redes-campeones' )
        );

        $html .= sprintf(
            '<form method="post" action="%1$s" style="display:inline;">'
            . '<input type="hidden" name="campeones_editor_action" value="%2$s">'
            . '<input type="hidden" name="titulo_id" value="%7$d">'
            . '<input type="hidden" name="plantel_id" value="%3$d">'
            . '<input type="hidden" name="campeones_link_nonce" value="%4$s">'
            . '<input type="number" name="jugador_id" placeholder="%5$s" style="width:6em;">'
            . '<button type="submit" class="button-link">%6$s</button></form>',
            esc_url( $adminUrl ),
            esc_attr( $linkAction ),
            $plantelId,
            esc_attr( $nonce ),
            esc_attr__( 'ID jugador', 'entre-redes-campeones' ),
            esc_html( $linkLabel ),
            $this->tituloId
        );

        if ( $isLinked ) {
            $html .= sprintf(
                ' <form method="post" action="%1$s" style="display:inline;">'
                . '<input type="hidden" name="campeones_editor_action" value="desvincular">'
                . '<input type="hidden" name="titulo_id" value="%5$d">'
                . '<input type="hidden" name="plantel_id" value="%2$d">'
                . '<input type="hidden" name="campeones_link_nonce" value="%3$s">'
                . '<button type="submit" class="button-link">%4$s</button></form>',
                esc_url( $adminUrl ),
                $plantelId,
                esc_attr( $nonce ),
                esc_html__( 'Desvincular', 'entre-redes-campeones' ),
                $this->tituloId
            );
        }

        $html .= sprintf(
            ' <form method="post" action="%1$s" style="display:inline;" onsubmit="return confirm(\'%2$s\');">'
            . '<input type="hidden" name="campeones_editor_action" value="eliminar_fila">'
            . '<input type="hidden" name="titulo_id" value="%6$d">'
            . '<input type="hidden" name="plantel_id" value="%3$d">'
            . '<input type="hidden" name="campeones_link_nonce" value="%4$s">'
            . '<button type="submit" class="button-link submitdelete">%5$s</button></form>',
            esc_url( $adminUrl ),
            esc_js( __( '¿Eliminar esta fila del plantel?', 'entre-redes-campeones' ) ),
            $plantelId,
            esc_attr( $nonce ),
            esc_html__( 'Eliminar', 'entre-redes-campeones' ),
            $this->tituloId
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
        esc_html_e( 'Este título todavía no tiene jugadores cargados.', 'entre-redes-campeones' );
    }
}

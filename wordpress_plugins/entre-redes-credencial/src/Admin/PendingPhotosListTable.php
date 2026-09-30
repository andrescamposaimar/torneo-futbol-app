<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Admin;

/**
 * WP_List_Table subclass for "Credenciales > Fotos pendientes" (design D12).
 * Guarded/instantiated only inside PendingPhotosPage::render(), which — like
 * entre-redes-prode's RegistryListTable — is not unit-tested: rendering HTML
 * is not where this design's risk lives; PendingPhotosPageTest covers the
 * capability/nonce gate and the actual approve/reject/publish mutation.
 *
 * Two row kinds share one table (design D12): 'pending' (Approve/Reject) and
 * 'unpublished' ("Approved, not yet published" — Finish publishing only).
 */
class PendingPhotosListTable extends \WP_List_Table {

    /** @var array<int, array<string, mixed>> */
    private array $pendingRows = [];

    /** @var array<int, array<string, mixed>> */
    private array $unpublishedRows = [];

    /**
     * @param array<int, array<string, mixed>> $pendingRows
     * @param array<int, array<string, mixed>> $unpublishedRows
     */
    public function setData( array $pendingRows, array $unpublishedRows ): void {
        $this->pendingRows     = $pendingRows;
        $this->unpublishedRows = $unpublishedRows;
        $this->items           = array_merge( $pendingRows, $unpublishedRows );
    }

    /** @return array<string, string> */
    public function get_columns(): array {
        return [
            'kind'    => __( 'Estado', 'entre-redes-credencial' ),
            'player'  => __( 'Jugador', 'entre-redes-credencial' ),
            'photo'   => __( 'Foto', 'entre-redes-credencial' ),
            'created' => __( 'Fecha', 'entre-redes-credencial' ),
            'actions' => __( 'Acciones', 'entre-redes-credencial' ),
        ];
    }

    public function prepare_items(): void {
        $this->_column_headers = [ $this->get_columns(), [], [] ];
    }

    public function no_items(): void {
        esc_html_e( 'No hay solicitudes de foto pendientes.', 'entre-redes-credencial' );
    }

    /** @param array<string, mixed> $item */
    protected function column_default( $item, $column_name ): string {
        return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
    }

    /** @param array<string, mixed> $item */
    protected function column_kind( $item ): string {
        return 'pending' === $item['kind']
            ? esc_html__( 'Pendiente', 'entre-redes-credencial' )
            : esc_html__( 'Aprobada, sin publicar', 'entre-redes-credencial' );
    }

    /** @param array<string, mixed> $item */
    protected function column_player( $item ): string {
        return esc_html( (string) $item['player_id'] );
    }

    /** @param array<string, mixed> $item */
    protected function column_photo( $item ): string {
        if ( 'pending' === $item['kind'] ) {
            $current = ! empty( $item['current_photo_url'] )
                ? sprintf( '<img src="%s" style="max-height:80px;" alt="">', esc_url( (string) $item['current_photo_url'] ) )
                : esc_html__( '(sin foto actual)', 'entre-redes-credencial' );

            $new = ! empty( $item['new_photo_data_uri'] )
                ? sprintf( '<img src="%s" style="max-height:80px;" alt="">', esc_attr( (string) $item['new_photo_data_uri'] ) )
                : esc_html__( '(no se pudo leer la foto pendiente)', 'entre-redes-credencial' );

            return $current . ' &rarr; ' . $new;
        }

        return esc_html__( '(ver ficha del jugador)', 'entre-redes-credencial' );
    }

    /** @param array<string, mixed> $item */
    protected function column_actions( $item ): string {
        $requestId = (int) $item['id'];
        $pageUrl   = admin_url( 'admin.php?page=' . PendingPhotosPage::SLUG );

        if ( 'unpublished' === $item['kind'] ) {
            return $this->actionForm( 'publish', $requestId, $pageUrl, __( 'Terminar de publicar', 'entre-redes-credencial' ) );
        }

        $approveForm = $this->actionForm( 'approve', $requestId, $pageUrl, __( 'Aprobar', 'entre-redes-credencial' ) );
        $rejectForm  = $this->actionForm(
            'reject',
            $requestId,
            $pageUrl,
            __( 'Rechazar', 'entre-redes-credencial' ),
            '<input type="text" name="credencial_review_note" placeholder="' . esc_attr__( 'Nota interna (opcional)', 'entre-redes-credencial' ) . '">'
        );

        return $approveForm . ' ' . $rejectForm;
    }

    private function actionForm( string $action, int $requestId, string $pageUrl, string $label, string $extraField = '' ): string {
        $nonce = wp_create_nonce( PendingPhotosPage::nonceActionFor( $action, $requestId ) );

        return sprintf(
            '<form method="post" action="%s" style="display:inline-block;margin-right:6px;">'
            . '<input type="hidden" name="credencial_action" value="%s">'
            . '<input type="hidden" name="credencial_request_id" value="%d">'
            . '<input type="hidden" name="credencial_nonce" value="%s">'
            . '%s'
            . '<button type="submit" class="button">%s</button>'
            . '</form>',
            esc_url( $pageUrl ),
            esc_attr( $action ),
            $requestId,
            esc_attr( $nonce ),
            $extraField,
            esc_html( $label )
        );
    }
}

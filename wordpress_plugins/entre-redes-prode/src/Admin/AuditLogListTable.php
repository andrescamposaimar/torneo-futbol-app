<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Admin;

/**
 * WP_List_Table subclass for the Bitácora admin page.
 *
 * Read-only display (BIT-04): no write actions, no forms, no POST handlers.
 *
 * Design D2: WP_List_Table required via guard inside AuditLogPage::render().
 *
 * Columns per BIT-02: id, event_type, player_name, provider,
 * actor_wp_user_id, created_at, metadata_json.
 *
 * BIT-03: dni_hash, provider_id_hash, ip_address_hash are NOT shown.
 */
class AuditLogListTable extends \WP_List_Table {

    /** @var array<int, array<string, mixed>> */
    private array $items_data = [];

    private int $total_items_count = 0;

    private const EVENT_TYPE_LABELS = [
        'association_created'                   => 'Asociación creada',
        'association_rejected_dni_not_found'    => 'Rechazada: DNI no encontrado',
        'association_rejected_already_associated' => 'Rechazada: ya asociado',
        'admin_unlink'                          => 'Desvinculación admin',
        'user_account_deletion'                 => 'Eliminación de cuenta',
    ];

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
            'id'               => __( 'ID', 'entre-redes-prode' ),
            'event_type'       => __( 'Tipo de evento', 'entre-redes-prode' ),
            'player_name'      => __( 'Jugador', 'entre-redes-prode' ),
            'provider'         => __( 'Proveedor', 'entre-redes-prode' ),
            'actor_wp_user_id' => __( 'Actor WP', 'entre-redes-prode' ),
            'created_at'       => __( 'Fecha/Hora', 'entre-redes-prode' ),
            'metadata_json'    => __( 'Metadatos', 'entre-redes-prode' ),
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
        $val = $item[ $column_name ] ?? null;
        if ( $val === null || $val === '' ) {
            return '—';
        }
        return esc_html( (string) $val );
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function column_event_type( $item ): string {
        $type  = (string) ( $item['event_type'] ?? '' );
        $label = self::EVENT_TYPE_LABELS[ $type ] ?? $type;
        return esc_html( $label );
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function column_player_name( $item ): string {
        $val = $item['player_name'] ?? null;
        return ( $val !== null && $val !== '' ) ? esc_html( (string) $val ) : '—';
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function column_provider( $item ): string {
        $val = $item['provider'] ?? null;
        return ( $val !== null && $val !== '' ) ? esc_html( (string) $val ) : '—';
    }

    /**
     * BIT-09: pretty-print JSON; truncate at 300 chars; null → "—".
     *
     * @param array<string, mixed> $item
     */
    protected function column_metadata_json( $item ): string {
        $raw = $item['metadata_json'] ?? null;

        if ( $raw === null || $raw === '' ) {
            return '—';
        }

        $decoded = json_decode( (string) $raw, true );

        if ( $decoded === null ) {
            // Invalid JSON — display raw escaped value.
            return esc_html( (string) $raw );
        }

        $pretty = json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        if ( $pretty === false ) {
            return '—';
        }

        // Truncate at 300 chars (BIT-09).
        if ( mb_strlen( $pretty ) > 300 ) {
            $pretty = mb_substr( $pretty, 0, 300 ) . '…';
        }

        return '<pre style="white-space:pre-wrap;word-break:break-all;max-width:400px;">'
            . esc_html( $pretty )
            . '</pre>';
    }

    public function no_items(): void {
        esc_html_e( 'No se encontraron registros en la bitácora para los filtros seleccionados.', 'entre-redes-prode' );
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Admin;

/**
 * Renders the Bitácora admin subpage (slug: prode-audit-log).
 *
 * Read-only page (BIT-04): no write actions, no forms (except the filter form
 * which submits via GET), no POST handlers.
 *
 * Design D2: WP_List_Table guard applied at render time.
 *
 * Filters (BIT-05, BIT-06):
 *   - event_type dropdown (GET param: event_type).
 *   - date range (GET params: date_from, date_to).
 *   - Filters persist across pagination via add_query_arg().
 *
 * Security (ADR-P014, CC-01..04):
 *   - manage_options checked in render().
 *   - All output escaped with esc_html() / esc_attr().
 *   - No write actions, no nonce needed on GET filter form.
 */
class AuditLogPage {

    private const PER_PAGE = 25;

    private const EVENT_TYPES = [
        'association_created',
        'association_rejected_dni_not_found',
        'association_rejected_already_associated',
        'admin_unlink',
        'user_account_deletion',
    ];

    private const EVENT_TYPE_LABELS = [
        'association_created'                     => 'Asociación creada',
        'association_rejected_dni_not_found'      => 'Rechazada: DNI no encontrado',
        'association_rejected_already_associated' => 'Rechazada: ya asociado',
        'admin_unlink'                            => 'Desvinculación admin',
        'user_account_deletion'                   => 'Eliminación de cuenta',
    ];

    public function __construct(
        private AuditLogRepository $auditLogRepo
    ) {}

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tenés permiso para acceder a esta página.', 'entre-redes-prode' ) );
        }

        // D2 guard: load WP_List_Table only at render time.
        if ( ! class_exists( 'WP_List_Table' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        // Read and sanitize filter params (BIT-05, BIT-06).
        // phpcs:disable WordPress.Security.NonceVerification
        $filterEventType = isset( $_GET['event_type'] ) ? sanitize_text_field( (string) $_GET['event_type'] ) : '';
        $filterFrom      = isset( $_GET['date_from'] ) ? sanitize_text_field( (string) $_GET['date_from'] ) : '';
        $filterTo        = isset( $_GET['date_to'] ) ? sanitize_text_field( (string) $_GET['date_to'] ) : '';
        $currentPage     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        // phpcs:enable WordPress.Security.NonceVerification

        $offset = ( $currentPage - 1 ) * self::PER_PAGE;

        $eventTypeParam = $filterEventType !== '' ? $filterEventType : null;
        $fromParam      = $filterFrom !== '' ? $filterFrom : null;
        $toParam        = $filterTo !== '' ? $filterTo : null;

        $items = $this->auditLogRepo->listEvents(
            $eventTypeParam,
            $fromParam,
            $toParam,
            self::PER_PAGE,
            $offset
        );

        $total = $this->auditLogRepo->countEvents( $eventTypeParam, $fromParam, $toParam );

        $listTable = new AuditLogListTable( [ 'singular' => 'evento', 'plural' => 'eventos', 'ajax' => false ] );
        $listTable->setData( $items, $total );
        $listTable->prepare_items();

        $adminUrl      = admin_url( 'admin.php?page=prode-audit-log' );
        $filterBaseUrl = $adminUrl;

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <form method="get" action="<?php echo esc_url( $adminUrl ); ?>">
                <input type="hidden" name="page" value="prode-audit-log">

                <label for="event_type"><?php esc_html_e( 'Tipo de evento:', 'entre-redes-prode' ); ?></label>
                <select id="event_type" name="event_type">
                    <option value=""><?php esc_html_e( 'Todos', 'entre-redes-prode' ); ?></option>
                    <?php foreach ( self::EVENT_TYPES as $type ) : ?>
                    <option value="<?php echo esc_attr( $type ); ?>" <?php selected( $filterEventType, $type ); ?>>
                        <?php echo esc_html( self::EVENT_TYPE_LABELS[ $type ] ?? $type ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                &nbsp;

                <label for="date_from"><?php esc_html_e( 'Desde:', 'entre-redes-prode' ); ?></label>
                <input type="date" id="date_from" name="date_from" value="<?php echo esc_attr( $filterFrom ); ?>">

                &nbsp;

                <label for="date_to"><?php esc_html_e( 'Hasta:', 'entre-redes-prode' ); ?></label>
                <input type="date" id="date_to" name="date_to" value="<?php echo esc_attr( $filterTo ); ?>">

                &nbsp;

                <?php submit_button( __( 'Filtrar', 'entre-redes-prode' ), 'secondary', 'filter', false ); ?>

                <?php if ( $filterEventType !== '' || $filterFrom !== '' || $filterTo !== '' ) : ?>
                <a href="<?php echo esc_url( $adminUrl ); ?>" class="button">
                    <?php esc_html_e( 'Limpiar filtros', 'entre-redes-prode' ); ?>
                </a>
                <?php endif; ?>
            </form>

            <br>

            <?php
            // Preserve active filters in pagination links (BIT-07).
            // WP_List_Table uses the current URL for pagination; query args are
            // already in the URL when filters are submitted via GET.
            $listTable->display();
            ?>
        </div>
        <?php
    }
}

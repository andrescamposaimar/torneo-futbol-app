<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Admin;

use EntreRedes\Prode\Audit\AuditLogger;
use EntreRedes\Prode\Audit\DniHasher;

/**
 * Renders and handles POST for the Registro de jugadores admin subpage
 * (slug: prode-registry).
 *
 * Design notes (D2, D3):
 *   - render() loads WP_List_Table via the guard and delegates display
 *     to RegistryListTable.
 *   - handlePost() is registered on admin_init and processes the unlink action.
 *   - PRG pattern: after any POST, always redirect.
 *
 * Security (ADR-P014, CC-01..06):
 *   - manage_options checked in render() AND handlePost().
 *   - Per-row nonce: prode_unlink_{user_id} (REG-06).
 *   - No wp_users JOIN anywhere (CC-06, REG-01).
 *   - All output escaped with esc_html() / esc_attr().
 */
class RegistryPage {

    private const PER_PAGE = 25;

    public function __construct(
        private RegistryRepository $registryRepo,
        private AuditLogger $auditLogger,
        private DniHasher $dniHasher
    ) {}

    // -------------------------------------------------------------------------
    // POST handler — registered on admin_init (D3)
    // -------------------------------------------------------------------------

    public function handlePost(): void {
        $action = $_POST['prode_action'] ?? '';

        if ( $action === 'unlink_user' ) {
            $this->handleUnlink();
        }
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tenés permiso para acceder a esta página.', 'entre-redes-prode' ) );
        }

        // D2 guard: load WP_List_Table only at render time (not at file load).
        if ( ! class_exists( 'WP_List_Table' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $activeFilter = isset( $_GET['filter'] ) && $_GET['filter'] === 'deleted' ? 'deleted' : 'active';
        $activeOnly   = $activeFilter === 'active';

        // phpcs:ignore WordPress.Security.NonceVerification
        $currentPage = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $offset      = ( $currentPage - 1 ) * self::PER_PAGE;

        $tenantId    = defined( 'PRODE_TENANT_ID' ) ? (string) PRODE_TENANT_ID : '';
        $items       = $this->registryRepo->listUsers( $tenantId, $activeOnly, self::PER_PAGE, $offset );
        $totalActive = $this->registryRepo->countUsers( $tenantId, true );
        $totalDeleted = $this->registryRepo->countUsers( $tenantId, false );
        $total       = $activeOnly ? $totalActive : $totalDeleted;

        $listTable = new RegistryListTable( [ 'singular' => 'jugador', 'plural' => 'jugadores', 'ajax' => false ] );
        $listTable->setData( $items, $total, $activeFilter );
        $listTable->prepare_items();

        // Notice after unlink redirect.
        $notice     = '';
        $noticeType = 'success';
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( isset( $_GET['prode_registry_notice'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification
            $key = sanitize_text_field( (string) $_GET['prode_registry_notice'] );
            if ( $key === 'unlinked' ) {
                $notice = __( 'El usuario fue desvinculado correctamente.', 'entre-redes-prode' );
            } elseif ( $key === 'unlinked_no_audit' ) {
                $notice     = __( 'El usuario fue desvinculado, pero no se pudo registrar la entrada en la bitácora de auditoría. Verificá la configuración del plugin (pepper de auditoría).', 'entre-redes-prode' );
                $noticeType = 'warning';
            } elseif ( $key === 'already_unlinked' ) {
                $notice     = __( 'El usuario ya fue desvinculado.', 'entre-redes-prode' );
                $noticeType = 'info';
            } elseif ( $key === 'error' ) {
                $notice     = __( 'Error al desvincular el usuario. Intentá nuevamente.', 'entre-redes-prode' );
                $noticeType = 'error';
            }
        }

        $adminUrl = admin_url( 'admin.php?page=prode-registry' );

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php if ( $notice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $noticeType ); ?> is-dismissible">
                <p><?php echo esc_html( $notice ); ?></p>
            </div>
            <?php endif; ?>

            <ul class="subsubsub">
                <li>
                    <a href="<?php echo esc_url( $adminUrl ); ?>"
                       class="<?php echo esc_attr( $activeFilter === 'active' ? 'current' : '' ); ?>">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: count */
                                __( 'Activos (%d)', 'entre-redes-prode' ),
                                $totalActive
                            )
                        );
                        ?>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo esc_url( add_query_arg( 'filter', 'deleted', $adminUrl ) ); ?>"
                       class="<?php echo esc_attr( $activeFilter === 'deleted' ? 'current' : '' ); ?>">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: count */
                                __( 'Eliminados (%d)', 'entre-redes-prode' ),
                                $totalDeleted
                            )
                        );
                        ?>
                    </a>
                </li>
            </ul>

            <?php $listTable->display(); ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Private handlers
    // -------------------------------------------------------------------------

    private function handleUnlink(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tenés permiso para realizar esta acción.', 'entre-redes-prode' ) );
        }

        $userId = absint( $_POST['prode_user_id'] ?? 0 );
        if ( $userId === 0 ) {
            wp_die( esc_html__( 'ID de usuario inválido.', 'entre-redes-prode' ) );
        }

        // Per-row nonce (REG-06).
        $nonce = (string) ( $_POST['prode_unlink_nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'prode_unlink_' . $userId ) ) {
            wp_die( esc_html__( 'Verificación de seguridad fallida. Por favor recargá la página e intentá de nuevo.', 'entre-redes-prode' ) );
        }

        $tenantId = defined( 'PRODE_TENANT_ID' ) ? (string) PRODE_TENANT_ID : '';

        // Fetch association data BEFORE soft-delete (needed for audit log).
        $assocData = $this->registryRepo->findUserForUnlink( $tenantId, $userId );

        $redirectBase = admin_url( 'admin.php?page=prode-registry' );

        // EDGE-03: already unlinked — no active association found.
        if ( $assocData === null ) {
            wp_safe_redirect( add_query_arg( 'prode_registry_notice', 'already_unlinked', $redirectBase ) );
            exit;
        }

        $actorWpId  = get_current_user_id();
        $playerName = (string) ( $assocData['player_id'] ?? '' );
        $provider   = (string) ( $assocData['provider'] ?? '' );
        $dni        = (string) ( $assocData['dni'] ?? '' );

        // Soft-delete the association (REG-06 step 3).
        $unlinked = $this->registryRepo->unlinkAssociation( $userId, $actorWpId );

        if ( ! $unlinked ) {
            // 0 rows affected means already unlinked concurrently (EDGE-03).
            wp_safe_redirect( add_query_arg( 'prode_registry_notice', 'already_unlinked', $redirectBase ) );
            exit;
        }

        // Hash DNI and log the admin unlink event (REG-06 step 4).
        // Only called when the soft-delete succeeded (REG-06: do NOT log if delete failed).
        $auditFailed = false;
        try {
            $dniHash = $this->dniHasher->hash( $dni );
            $this->auditLogger->logAdminUnlink( $userId, $actorWpId, $playerName, $provider, $dniHash );
        } catch ( \Throwable ) {
            // Audit log failure must NOT roll back the unlink.
            // Surface a warning notice so the operator knows the audit entry was not written.
            $auditFailed = true;
        }

        $noticeKey = $auditFailed ? 'unlinked_no_audit' : 'unlinked';
        wp_safe_redirect( add_query_arg( 'prode_registry_notice', $noticeKey, $redirectBase ) );
        exit;
    }
}

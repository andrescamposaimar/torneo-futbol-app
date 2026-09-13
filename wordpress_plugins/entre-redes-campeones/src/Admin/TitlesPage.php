<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Admin;

use EntreRedes\Campeones\Linking\RevalidationService;
use EntreRedes\Campeones\Titles\TitleDeletionService;
use EntreRedes\Campeones\Titles\TitleRepository;

/**
 * Renders and handles POST for the top-level Titles admin page (slug:
 * campeones) — the year list, with per-row Editar / Revalidar / Eliminar
 * (design §6, ADMIN-7, ADMIN-9).
 *
 * Security, identical to entre-redes-prode's RegistryPage: manage_options
 * re-checked in both render() and handlePost(); PRG after every POST;
 * per-row nonces; WP_List_Table required behind a class_exists guard at
 * render time, never at file load.
 */
class TitlesPage {

    public function __construct(
        private readonly TitleRepository $titles,
        private readonly TitleDeletionService $deletionService,
        private readonly RevalidationService $revalidationService
    ) {
    }

    // -------------------------------------------------------------------------
    // POST handler — registered on admin_init
    // -------------------------------------------------------------------------

    public function handlePost(): void {
        $action = (string) ( $_POST['campeones_titulo_action'] ?? '' );
        if ( '' === $action || ! in_array( $action, [ 'eliminar', 'revalidar' ], true ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tenés permiso para realizar esta acción.', 'entre-redes-campeones' ) );
        }

        $tituloId = absint( $_POST['titulo_id'] ?? 0 );
        if ( 0 === $tituloId ) {
            wp_die( esc_html__( 'ID de título inválido.', 'entre-redes-campeones' ) );
        }

        $nonceAction = 'eliminar' === $action
            ? 'campeones_eliminar_titulo_' . $tituloId
            : 'campeones_revalidar_' . $tituloId;
        $nonce = (string) ( $_POST['campeones_titulo_nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, $nonceAction ) ) {
            wp_die( esc_html__( 'Verificación de seguridad fallida. Por favor recargá la página e intentá de nuevo.', 'entre-redes-campeones' ) );
        }

        if ( 'eliminar' === $action ) {
            $notice = $this->handleDelete( $tituloId ) ? 'eliminado' : 'error_eliminar';
        } else {
            $count  = $this->handleRevalidate( $tituloId );
            $notice = 'revalidado_' . $count;
        }

        wp_safe_redirect( add_query_arg( 'campeones_notice', $notice, admin_url( 'admin.php?page=campeones' ) ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tenés permiso para acceder a esta página.', 'entre-redes-campeones' ) );
        }

        if ( ! class_exists( 'WP_List_Table' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        $titles = $this->titles->findAll();
        $rows   = array_map(
            static fn ( $t ): array => [
                'id'            => $t->id,
                'anio'          => $t->anio,
                'zona'          => $t->zona,
                'posicion'      => $t->posicion,
                'equipo_nombre' => $t->equipoNombre,
            ],
            $titles
        );

        $listTable = new TitlesListTable( [ 'singular' => 'titulo', 'plural' => 'titulos', 'ajax' => false ] );
        $listTable->setData( $rows, count( $rows ) );
        $listTable->prepare_items();

        $addUrl = admin_url( 'admin.php?page=campeones-titulo-edit' );

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?>
                <a href="<?php echo esc_url( $addUrl ); ?>" class="page-title-action">
                    <?php echo esc_html__( 'Agregar nuevo', 'entre-redes-campeones' ); ?>
                </a>
            </h1>
            <?php $listTable->display(); ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Private mutation handlers — no capability check of their own; the
    // caller (handlePost()) is the single gate. Kept free of redirect/exit
    // so they can be exercised directly in tests.
    // -------------------------------------------------------------------------

    private function handleDelete( int $tituloId ): bool {
        return $this->deletionService->delete( $tituloId );
    }

    private function handleRevalidate( int $tituloId ): int {
        return $this->revalidationService->revalidateYear( $tituloId );
    }
}

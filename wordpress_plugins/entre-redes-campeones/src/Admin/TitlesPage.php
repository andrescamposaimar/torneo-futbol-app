<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Admin;

use EntreRedes\Campeones\Linking\PlayerDirectoryQueryException;
use EntreRedes\Campeones\Linking\RevalidationResult;
use EntreRedes\Campeones\Linking\RevalidationService;
use EntreRedes\Campeones\Titles\TitleDeletionService;
use EntreRedes\Campeones\Titles\TitleRepository;
use EntreRedes\Campeones\Titles\WriteFailedException;

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

        try {
            if ( 'eliminar' === $action ) {
                $notice = $this->handleDelete( $tituloId ) ? 'eliminado' : 'error_eliminar';
            } else {
                $result = $this->handleRevalidate( $tituloId );
                $notice = sprintf( 'revalidado_%d_%d', $result->succeeded, $result->total );
            }
        } catch ( PlayerDirectoryQueryException | WriteFailedException $e ) {
            // Neither exception is caught anywhere else in this class.
            // handleRevalidate() runs LinkResolver over every resolvable row
            // in the year; a broken directory read must not turn into a
            // fatal mid-loop (item 7).
            error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                'entre-redes-campeones: %s failed for titulo_id=%d. %s',
                $action,
                $tituloId,
                $e->getMessage()
            ) );
            $notice = 'error_directorio';
        }

        wp_safe_redirect( add_query_arg( 'campeones_notice', $notice, admin_url( 'admin.php?page=campeones' ) ) );
        $this->terminateAfterRedirect();
    }

    /**
     * Isolated in its own method (rather than a bare `exit;` inline in
     * handlePost()) so a test can override this single point with a
     * catchable signal instead of ending the PHP process outright — the
     * only way to drive a real success path through the public handlePost()
     * entry point instead of Reflection.
     */
    protected function terminateAfterRedirect(): void {
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
        $notice = $this->resolveNotice();

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?>
                <a href="<?php echo esc_url( $addUrl ); ?>" class="page-title-action">
                    <?php echo esc_html__( 'Agregar nuevo', 'entre-redes-campeones' ); ?>
                </a>
            </h1>
            <?php if ( null !== $notice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
                <p><?php echo esc_html( $notice['message'] ); ?></p>
            </div>
            <?php endif; ?>
            <?php $listTable->display(); ?>
        </div>
        <?php
    }

    /**
     * Resolves $_GET['campeones_notice'] (set by handlePost()'s PRG
     * redirect) into a displayable message + notice type, mirroring
     * entre-redes-prode's RegistryPage::render():80-99. Without this, a
     * failed eliminar/revalidar looked exactly like a successful one — the
     * page redirected either way and rendered nothing to tell them apart.
     *
     * @return array{message: string, type: string}|null
     */
    private function resolveNotice(): ?array {
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! isset( $_GET['campeones_notice'] ) ) {
            return null;
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $key = sanitize_text_field( (string) $_GET['campeones_notice'] );

        if ( 'eliminado' === $key ) {
            return [ 'message' => __( 'El título y su plantel fueron eliminados correctamente.', 'entre-redes-campeones' ), 'type' => 'success' ];
        }

        if ( 'error_eliminar' === $key ) {
            return [ 'message' => __( 'Error al eliminar el título. Intentá nuevamente.', 'entre-redes-campeones' ), 'type' => 'error' ];
        }

        if ( 'error_directorio' === $key ) {
            return [ 'message' => __( 'Error al consultar el directorio de jugadores. Intentá nuevamente en unos minutos.', 'entre-redes-campeones' ), 'type' => 'error' ];
        }

        if ( str_starts_with( $key, 'revalidado_' ) ) {
            // Item 2: the notice must state BOTH how many rows succeeded
            // and how many were attempted — a bare count let a 1-of-1
            // failure render as a green "0 fila(s)" success, indistinguishable
            // from a genuine 0-of-0 no-op.
            $parts     = explode( '_', substr( $key, strlen( 'revalidado_' ) ), 2 );
            $succeeded = (int) ( $parts[0] ?? 0 );
            $total     = (int) ( $parts[1] ?? 0 );

            $type = 'success';
            if ( $total > 0 && $succeeded < $total ) {
                $type = 0 === $succeeded ? 'error' : 'warning';
            }

            return [
                'message' => sprintf(
                    /* translators: 1: number of squad rows successfully re-evaluated, 2: number of squad rows attempted */
                    __( 'Revalidación: %1$d de %2$d fila(s) del plantel fueron re-evaluadas correctamente.', 'entre-redes-campeones' ),
                    $succeeded,
                    $total
                ),
                'type'    => $type,
            ];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Private mutation handlers — no capability check of their own; the
    // caller (handlePost()) is the single gate. Kept free of redirect/exit
    // so they can be exercised directly in tests.
    // -------------------------------------------------------------------------

    private function handleDelete( int $tituloId ): bool {
        return $this->deletionService->delete( $tituloId );
    }

    private function handleRevalidate( int $tituloId ): RevalidationResult {
        return $this->revalidationService->revalidateYear( $tituloId );
    }
}

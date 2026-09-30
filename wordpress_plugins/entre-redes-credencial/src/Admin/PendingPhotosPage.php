<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Admin;

use EntreRedes\Credencial\Approval\ApprovalRequestRepository;
use EntreRedes\Credencial\Approval\ApprovalReviewService;
use EntreRedes\Credencial\Photo\MediaWriter;

/**
 * Renders and handles POST for "Credenciales > Fotos pendientes" (design D12).
 *
 * Security, mirrored from entre-redes-prode's Admin\RegistryPage (decision
 * `credencial/capability-revision-fotos`: the comisión reviewers are WP
 * administrators — no custom capability):
 *   - `manage_options` checked in BOTH render() and handlePost().
 *   - A per-row nonce (`credencial_{action}_{request_id}`, see
 *     nonceActionFor()) gates every approve/reject/publish action.
 *   - PRG pattern: handlePost() always redirects after acting.
 *
 * handlePost() ends in `exit;`, which a real PHP `exit` would terminate the
 * PHPUnit process over — so, same convention as RegistryPage::finalizeUnlink(),
 * the actual mutation is extracted into applyAction(), a public method with
 * no `exit`, directly unit-tested. handlePost()'s own guard clauses
 * (capability, request id, nonce) run and `wp_die()` BEFORE that extraction
 * point, so they ARE safely testable by calling handlePost() itself (the
 * shim's wp_die() throws instead of terminating the process).
 *
 * "Finish publishing" an approved-but-unpublished row is NOT a separate
 * mutation — the `publish` action calls the exact same
 * ApprovalReviewService::approve() as `approve` does (design D11: approving
 * an already-approved request re-runs only its publish tail). Two form
 * actions exist only so each row's button reads correctly in the UI.
 */
final class PendingPhotosPage {

    public const SLUG = 'credencial-fotos-pendientes';

    private const VALID_ACTIONS = [ 'approve', 'reject', 'publish' ];

    public function __construct(
        private ApprovalRequestRepository $requests,
        private ApprovalReviewService $reviewService,
        private MediaWriter $media
    ) {}

    public static function nonceActionFor( string $action, int $requestId ): string {
        return 'credencial_' . $action . '_' . $requestId;
    }

    // -------------------------------------------------------------------------
    // POST handler — registered on admin_init.
    // -------------------------------------------------------------------------

    public function handlePost(): void {
        $action = (string) ( $_POST['credencial_action'] ?? '' );

        if ( ! in_array( $action, self::VALID_ACTIONS, true ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tenés permiso para realizar esta acción.', 'entre-redes-credencial' ) );
        }

        $requestId = absint( $_POST['credencial_request_id'] ?? 0 );
        if ( 0 === $requestId ) {
            wp_die( esc_html__( 'ID de solicitud inválido.', 'entre-redes-credencial' ) );
        }

        $nonce = (string) ( $_POST['credencial_nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, self::nonceActionFor( $action, $requestId ) ) ) {
            wp_die( esc_html__( 'Verificación de seguridad fallida. Recargá la página e intentá de nuevo.', 'entre-redes-credencial' ) );
        }

        $noticeKey = $this->applyAction( $action, $requestId );

        wp_safe_redirect( add_query_arg( 'credencial_notice', $noticeKey, admin_url( 'admin.php?page=' . self::SLUG ) ) );
        exit;
    }

    /**
     * The actual mutation, with no `exit` — see class docblock. `publish` and
     * `approve` are the SAME call on purpose (design D11).
     */
    public function applyAction( string $action, int $requestId ): string {
        $now = time();

        if ( 'reject' === $action ) {
            $note    = sanitize_text_field( (string) ( $_POST['credencial_review_note'] ?? '' ) );
            $applied = $this->reviewService->reject( $requestId, get_current_user_id(), '' === $note ? null : $note, $now );

            return $applied ? 'rejected' : 'already_decided';
        }

        $result = $this->reviewService->approve( $requestId, get_current_user_id(), $now );

        return match ( $result ) {
            ApprovalReviewService::RESULT_PUBLISHED => 'approved',
            ApprovalReviewService::RESULT_UNPUBLISHED => 'approved_unpublished',
            ApprovalReviewService::RESULT_ALREADY_REJECTED => 'already_decided',
            ApprovalReviewService::RESULT_STEP_FAILED => 'step_failed',
            default => 'error',
        };
    }

    // -------------------------------------------------------------------------
    // Render.
    // -------------------------------------------------------------------------

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tenés permiso para acceder a esta página.', 'entre-redes-credencial' ) );
        }

        if ( ! class_exists( 'WP_List_Table' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        // Design D11 step (g): run the blob sweep on every page load.
        $this->reviewService->sweepBlobs();

        $listTable = new PendingPhotosListTable( [ 'singular' => 'solicitud', 'plural' => 'solicitudes', 'ajax' => false ] );
        $listTable->setData( $this->buildPendingRows(), $this->buildUnpublishedRows() );
        $listTable->prepare_items();

        $notice = $this->noticeFor( sanitize_text_field( (string) ( $_GET['credencial_notice'] ?? '' ) ) );

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php if ( null !== $notice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
                <p><?php echo esc_html( $notice['text'] ); ?></p>
            </div>
            <?php endif; ?>

            <?php $listTable->display(); ?>
        </div>
        <?php
    }

    /** @return array{type: string, text: string}|null */
    private function noticeFor( string $key ): ?array {
        return match ( $key ) {
            'approved'             => [ 'type' => 'success', 'text' => __( 'Foto aprobada y publicada.', 'entre-redes-credencial' ) ],
            'approved_unpublished' => [ 'type' => 'warning', 'text' => __( 'La solicitud fue aprobada, pero todavía no se pudo publicar. Volvé a intentarlo con "Terminar de publicar".', 'entre-redes-credencial' ) ],
            'rejected'              => [ 'type' => 'success', 'text' => __( 'Solicitud rechazada.', 'entre-redes-credencial' ) ],
            'already_decided'       => [ 'type' => 'info', 'text' => __( 'Esta solicitud ya había sido resuelta.', 'entre-redes-credencial' ) ],
            'step_failed'           => [ 'type' => 'error', 'text' => __( 'No se pudo procesar la solicitud. Intentá de nuevo.', 'entre-redes-credencial' ) ],
            default                 => null,
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function buildPendingRows(): array {
        $rows = [];

        foreach ( $this->requests->findPendingByType( ApprovalRequestRepository::TYPE_PHOTO ) as $row ) {
            $playerId = $row['target_player_id'];

            $rows[] = [
                'kind'               => 'pending',
                'id'                 => $row['id'],
                'player_id'          => $playerId,
                'requested_by'       => $row['requested_by'],
                'created'            => $row['created_at'],
                'current_photo_url'  => null === $playerId ? false : get_the_post_thumbnail_url( $playerId ),
                'new_photo_data_uri' => $this->dataUriFor( $row['id'] ),
            ];
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function buildUnpublishedRows(): array {
        $rows = [];

        foreach ( $this->requests->findApprovedPlayerIds( ApprovalRequestRepository::TYPE_PHOTO ) as $playerId ) {
            $liveThumb = $this->media->getFeaturedImageId( $playerId );

            if ( ! $this->requests->isUnpublished( $playerId, $liveThumb ) ) {
                continue;
            }

            $newest = $this->requests->findNewestApprovedPhotoRequest( $playerId );
            if ( null === $newest ) {
                continue; // Defensive — isUnpublished() already implies this exists.
            }

            $rows[] = [
                'kind'      => 'unpublished',
                'id'        => $newest['id'],
                'player_id' => $playerId,
                'created'   => $newest['reviewed_at'],
            ];
        }

        return $rows;
    }

    private function dataUriFor( int $requestId ): ?string {
        $binary = $this->requests->getBlobBinary( $requestId );

        // Never web-reachable (design's Accepted Risks): embedded directly in
        // this manage_options-gated admin response, not a fetchable URL.
        return null === $binary ? null : 'data:image/jpeg;base64,' . base64_encode( $binary );
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Admin;

use EntreRedes\Prode\Predictions\PredictionRepository;

/**
 * Renders the Predictions admin subpage (slug: prode-predictions).
 *
 * Read-only master/detail page (capability B):
 *   - Master (no user_id GET param): list of all users from RegistryRepository.
 *   - Detail (?user_id=N): full prediction history for that user.
 *
 * Security (ADR-P014, capability B spec):
 *   - manage_options checked in render() — double-guard on both branches.
 *   - No POST actions or nonces needed (GET-only, no mutations).
 *   - All output escaped with esc_html() / esc_attr() / esc_url().
 *
 * WP_List_Table guard (design D2): WP_List_Table is required only at render
 * time via class_exists() guard so this file is loadable in headless tests.
 */
class PredictionsPage {

    private const PER_PAGE = 25;

    public function __construct(
        private PredictionRepository $predictionRepo,
        private RegistryRepository   $registryRepo
    ) {}

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'entre-redes-prode' ) );
        }

        // D2 guard: load WP_List_Table only at render time.
        if ( ! class_exists( 'WP_List_Table' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        // phpcs:disable WordPress.Security.NonceVerification
        $userId      = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        $currentPage = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        // phpcs:enable WordPress.Security.NonceVerification

        if ( $userId > 0 ) {
            $this->renderDetail( $userId, $currentPage );
        } else {
            $this->renderMaster( $currentPage );
        }
    }

    // -------------------------------------------------------------------------
    // Master view — paginated user list
    // -------------------------------------------------------------------------

    private function renderMaster( int $currentPage ): void {
        $tenantId = defined( 'PRODE_TENANT_ID' ) ? (string) PRODE_TENANT_ID : '';
        $offset   = ( $currentPage - 1 ) * self::PER_PAGE;
        $items    = $this->registryRepo->listUsers( $tenantId, true, self::PER_PAGE, $offset );
        $total    = $this->registryRepo->countUsers( $tenantId, true );
        $adminUrl = admin_url( 'admin.php?page=prode-predictions' );
        $pages    = (int) ceil( $total / self::PER_PAGE );

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'ID', 'entre-redes-prode' ); ?></th>
                        <th><?php esc_html_e( 'Display name', 'entre-redes-prode' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'entre-redes-prode' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'entre-redes-prode' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $items ) ) : ?>
                    <tr>
                        <td colspan="4"><?php esc_html_e( 'No users found.', 'entre-redes-prode' ); ?></td>
                    </tr>
                    <?php else : ?>
                    <?php foreach ( $items as $user ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) ( $user['id'] ?? '' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $user['display_name'] ?? '' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $user['email'] ?? '' ) ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'prode-predictions', 'user_id' => (int) ( $user['id'] ?? 0 ) ], admin_url( 'admin.php' ) ) ); ?>">
                                <?php esc_html_e( 'View predictions', 'entre-redes-prode' ); ?>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php $this->renderPagination( $currentPage, $pages, $adminUrl ); ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Detail view — per-user prediction history
    // -------------------------------------------------------------------------

    private function renderDetail( int $userId, int $currentPage ): void {
        $total    = $this->predictionRepo->countByUser( $userId );
        $offset   = ( $currentPage - 1 ) * self::PER_PAGE;
        $allItems = $this->predictionRepo->findAllByUser( $userId );
        // Manual slice for pagination (repository returns all rows; slice in PHP).
        $items    = array_slice( $allItems, $offset, self::PER_PAGE );
        $pages    = (int) ceil( $total / self::PER_PAGE );
        $adminUrl = admin_url( 'admin.php?page=prode-predictions&user_id=' . $userId );
        $backUrl  = admin_url( 'admin.php?page=prode-predictions' );

        $listTable = new PredictionsListTable( [ 'singular' => 'prediction', 'plural' => 'predictions', 'ajax' => false ] );
        $listTable->setData( $items, $total );
        $listTable->prepare_items();

        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'Predictions — User', 'entre-redes-prode' ); ?>
                <?php echo esc_html( (string) $userId ); ?>
            </h1>

            <p>
                <a href="<?php echo esc_url( $backUrl ); ?>">&larr; <?php esc_html_e( 'Back to user list', 'entre-redes-prode' ); ?></a>
            </p>

            <?php $listTable->display(); ?>

            <?php $this->renderPagination( $currentPage, $pages, $adminUrl ); ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Pagination helper
    // -------------------------------------------------------------------------

    private function renderPagination( int $currentPage, int $totalPages, string $baseUrl ): void {
        if ( $totalPages <= 1 ) {
            return;
        }

        echo '<div class="tablenav"><div class="tablenav-pages">';

        for ( $i = 1; $i <= $totalPages; $i++ ) {
            if ( $i === $currentPage ) {
                echo '<span class="current">' . esc_html( (string) $i ) . '</span> ';
            } else {
                $url = add_query_arg( 'paged', $i, $baseUrl );
                echo '<a href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a> ';
            }
        }

        echo '</div></div>';
    }
}

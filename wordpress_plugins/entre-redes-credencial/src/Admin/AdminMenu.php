<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Admin;

/**
 * Registers the "Credenciales" top-level wp-admin menu (design D12: "Fotos
 * pendientes"). Mirrors entre-redes-prode's own Admin\AdminMenu: one class,
 * `manage_options` on every page and POST handler (decision
 * `credencial/capability-revision-fotos`), no custom capability.
 */
final class AdminMenu {

    public function __construct( private PendingPhotosPage $pendingPhotosPage ) {}

    /** Called on the `admin_menu` hook. Registers the menu and its POST handler. */
    public function register(): void {
        add_menu_page(
            __( 'Credenciales', 'entre-redes-credencial' ),
            __( 'Credenciales', 'entre-redes-credencial' ),
            'manage_options',
            PendingPhotosPage::SLUG,
            [ $this->pendingPhotosPage, 'render' ],
            'dashicons-id-alt'
        );

        // PRG pattern (design D12), same convention as prode's AdminMenu:
        // handlePost() runs on admin_init, BEFORE render() would ever run for
        // the same request.
        add_action( 'admin_init', [ $this->pendingPhotosPage, 'handlePost' ] );
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Admin;

/**
 * Registers the "Prode" top-level menu and its submenus in wp-admin.
 *
 * PR-01 scope: all subpages showed a "Próximamente" placeholder.
 * PR-09 scope: real Page implementations wired here.
 * PR-3 (B-admin): PredictionsPage added as 4th submenu.
 *
 * All pages are gated by the `manage_options` capability (ADR-P014).
 *
 * @see SettingsPage
 * @see RegistryPage
 * @see AuditLogPage
 * @see PredictionsPage
 */
class AdminMenu {

    public function __construct(
        private SettingsPage    $settingsPage,
        private RegistryPage    $registryPage,
        private AuditLogPage    $auditLogPage,
        private PredictionsPage $predictionsPage
    ) {}

    /**
     * Called on admin_menu hook. Registers top-level menu + three submenus,
     * and registers POST handlers on admin_init.
     */
    public function register(): void {
        add_menu_page(
            __( 'Prode Interno', 'entre-redes-prode' ),
            __( 'Prode', 'entre-redes-prode' ),
            'manage_options',
            'prode',
            [ $this->settingsPage, 'render' ],
            'dashicons-welcome-learn-more',
            56
        );

        add_submenu_page(
            'prode',
            __( 'Configuración', 'entre-redes-prode' ),
            __( 'Configuración', 'entre-redes-prode' ),
            'manage_options',
            'prode-settings',
            [ $this->settingsPage, 'render' ]
        );

        add_submenu_page(
            'prode',
            __( 'Registro de jugadores', 'entre-redes-prode' ),
            __( 'Registro de jugadores', 'entre-redes-prode' ),
            'manage_options',
            'prode-registry',
            [ $this->registryPage, 'render' ]
        );

        add_submenu_page(
            'prode',
            __( 'Bitácora', 'entre-redes-prode' ),
            __( 'Bitácora', 'entre-redes-prode' ),
            'manage_options',
            'prode-audit-log',
            [ $this->auditLogPage, 'render' ]
        );

        add_submenu_page(
            'prode',
            __( 'Predictions', 'entre-redes-prode' ),
            __( 'Predictions', 'entre-redes-prode' ),
            'manage_options',
            'prode-predictions',
            [ $this->predictionsPage, 'render' ]
        );

        // Register POST handlers on admin_init (D3: same-page POST + PRG).
        add_action( 'admin_init', [ $this->settingsPage, 'handlePost' ] );
        add_action( 'admin_init', [ $this->registryPage, 'handlePost' ] );
        // AuditLogPage and PredictionsPage have no POST handlers (read-only pages).
    }
}

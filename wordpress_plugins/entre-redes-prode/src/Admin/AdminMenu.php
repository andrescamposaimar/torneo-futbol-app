<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Admin;

/**
 * Registers the "Prode" top-level menu and its submenus in wp-admin.
 *
 * PR-01 scope: all subpages show a "Próximamente" placeholder.
 * Real implementations arrive in PR-09.
 *
 * All pages are gated by the `manage_options` capability (ADR-P014).
 */
class AdminMenu {

    public static function register(): void {
        add_menu_page(
            __( 'Prode Interno', 'entre-redes-prode' ),
            __( 'Prode', 'entre-redes-prode' ),
            'manage_options',
            'prode',
            [ self::class, 'renderPlaceholder' ],
            'dashicons-welcome-learn-more',
            56
        );

        add_submenu_page(
            'prode',
            __( 'Configuración', 'entre-redes-prode' ),
            __( 'Configuración', 'entre-redes-prode' ),
            'manage_options',
            'prode-settings',
            [ self::class, 'renderPlaceholder' ]
        );

        add_submenu_page(
            'prode',
            __( 'Registro de jugadores', 'entre-redes-prode' ),
            __( 'Registro de jugadores', 'entre-redes-prode' ),
            'manage_options',
            'prode-registry',
            [ self::class, 'renderPlaceholder' ]
        );

        add_submenu_page(
            'prode',
            __( 'Bitácora', 'entre-redes-prode' ),
            __( 'Bitácora', 'entre-redes-prode' ),
            'manage_options',
            'prode-audit-log',
            [ self::class, 'renderPlaceholder' ]
        );
    }

    public static function renderPlaceholder(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'entre-redes-prode' ) );
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';
        echo '<p>';
        esc_html_e( 'Próximamente — esta sección estará disponible en una versión futura del plugin.', 'entre-redes-prode' );
        echo '</p>';
        echo '</div>';
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Admin;

/**
 * Registers this plugin's own top-level wp-admin menu — a single page, the
 * process owner's bandeja (see BandejaPage) — mirroring entre-redes-prode's
 * Admin\AdminMenu for the shape of this wiring, with one deliberate
 * difference: the capability gating this menu is `ProcessOwnerAuthorizer::
 * CAPABILITY` (`gestionar_cambios`), never `manage_options` — see that
 * class's own docblock for why.
 */
final class AdminMenu {

    public function __construct( private BandejaPage $bandejaPage ) {
    }

    /**
     * Called on the `admin_menu` hook. Registers the top-level page and the
     * `admin_init` POST handler — same "register the handler right next to
     * the menu that leads to it" discipline as entre-redes-prode's
     * Admin\AdminMenu::register().
     */
    public function register(): void {
        add_menu_page(
            __( 'Cambios de jugadores', 'entre-redes-cambios' ),
            __( 'Cambios', 'entre-redes-cambios' ),
            ProcessOwnerAuthorizer::CAPABILITY,
            BandejaPage::SLUG,
            [ $this->bandejaPage, 'render' ],
            'dashicons-randomize'
        );

        add_action( 'admin_init', [ $this->bandejaPage, 'handlePost' ] );
    }
}

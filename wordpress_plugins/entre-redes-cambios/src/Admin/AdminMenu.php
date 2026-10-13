<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Admin;

/**
 * Registers this plugin's own top-level wp-admin menu — mirroring
 * entre-redes-prode's Admin\AdminMenu for the shape of this wiring, with one
 * deliberate difference: the capability gating this menu is
 * `ProcessOwnerAuthorizer::CAPABILITY` (`gestionar_cambios`), never
 * `manage_options` — see that class's own docblock for why.
 *
 * *** TWO PAGES SINCE 0.1.22 ***
 * `BandejaPage` is the worklist — what still needs deciding — and stays the
 * landing page, so the default destination never changes for whoever already
 * uses it. `HistoricoPage` is the read-only record: live substitutions and
 * every already-resolved solicitud. They are separate screens on purpose: the
 * tray is where the process owner ACTS, and padding it with rows that need no
 * action is the fastest way to make an action list stop being read.
 *
 * WordPress auto-creates a first submenu entry duplicating the top-level page
 * and labels it with the MENU title. Registering `BandejaPage::SLUG` again as
 * an explicit submenu overwrites that entry, which is the only way to give it
 * its own name ("Bandeja") instead of inheriting "Cambios" — so the two
 * children read as the two things they are.
 */
final class AdminMenu {

    public function __construct(
        private BandejaPage $bandejaPage,
        private HistoricoPage $historicoPage
    ) {
    }

    /**
     * Called on the `admin_menu` hook. Registers both pages and the
     * `admin_init` POST handler — same "register the handler right next to
     * the menu that leads to it" discipline as entre-redes-prode's
     * Admin\AdminMenu::register(). Only `BandejaPage` has one:
     * `HistoricoPage` writes nothing (see its class docblock).
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

        add_submenu_page(
            BandejaPage::SLUG,
            __( 'Bandeja de cambios', 'entre-redes-cambios' ),
            __( 'Bandeja', 'entre-redes-cambios' ),
            ProcessOwnerAuthorizer::CAPABILITY,
            BandejaPage::SLUG,
            [ $this->bandejaPage, 'render' ]
        );

        add_submenu_page(
            BandejaPage::SLUG,
            __( 'Histórico de cambios', 'entre-redes-cambios' ),
            __( 'Histórico', 'entre-redes-cambios' ),
            ProcessOwnerAuthorizer::CAPABILITY,
            HistoricoPage::SLUG,
            [ $this->historicoPage, 'render' ]
        );

        add_action( 'admin_init', [ $this->bandejaPage, 'handlePost' ] );
    }
}

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
 * *** "NUEVA SOLICITUD" SINCE 0.1.34 ***
 * A fourth entry, between the tray and the record: the commission loads a
 * request on behalf of a captain who phoned in (`NuevaSolicitudPage`). It
 * sits next to the Bandeja because that is where the request lands.
 *
 * *** THREE PAGES SINCE 0.1.23 ***
 * `BandejaPage` is the worklist — what still needs deciding — and stays the
 * landing page, so the default destination never changes for whoever already
 * uses it. `HistoricoPage` is the read-only record: live substitutions and
 * every already-resolved solicitud. They are separate screens on purpose: the
 * tray is where the process owner ACTS, and padding it with rows that need no
 * action is the fastest way to make an action list stop being read.
 * `ConfiguracionPage` is the policy rows of `cambios_settings` made editable
 * — rare, deliberate changes that have no business sharing a screen with
 * Friday's worklist.
 *
 * WordPress auto-creates a first submenu entry duplicating the top-level page
 * and labels it with the MENU title. Registering `BandejaPage::SLUG` again as
 * an explicit submenu overwrites that entry, which is the only way to give it
 * its own name ("Bandeja") instead of inheriting "Cambios" — so the two
 * children read as the two things they are.
 */
final class AdminMenu {

    /**
     * @param NuevaSolicitudPage|null $nuevaSolicitudPage The page the
     *        commission loads a request on a captain's behalf from (0.1.34).
     *        Optional only so a caller that builds this menu for the other
     *        three pages — every existing test — keeps working; `Plugin`
     *        always passes it.
     */
    public function __construct(
        private BandejaPage $bandejaPage,
        private HistoricoPage $historicoPage,
        private ConfiguracionPage $configuracionPage,
        private ?NuevaSolicitudPage $nuevaSolicitudPage = null
    ) {
    }

    /**
     * Called on the `admin_menu` hook. Registers both pages and the
     * `admin_init` POST handler — same "register the handler right next to
     * the menu that leads to it" discipline as entre-redes-prode's
     * Admin\AdminMenu::register(). `BandejaPage` and `ConfiguracionPage`
     * each register one; `HistoricoPage` writes nothing (see its class
     * docblock) and therefore has none.
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

        if ( null !== $this->nuevaSolicitudPage ) {
            add_submenu_page(
                BandejaPage::SLUG,
                __( 'Nueva solicitud', 'entre-redes-cambios' ),
                __( 'Nueva solicitud', 'entre-redes-cambios' ),
                ProcessOwnerAuthorizer::CAPABILITY,
                NuevaSolicitudPage::SLUG,
                [ $this->nuevaSolicitudPage, 'render' ]
            );
        }

        add_submenu_page(
            BandejaPage::SLUG,
            __( 'Histórico de cambios', 'entre-redes-cambios' ),
            __( 'Histórico', 'entre-redes-cambios' ),
            ProcessOwnerAuthorizer::CAPABILITY,
            HistoricoPage::SLUG,
            [ $this->historicoPage, 'render' ]
        );

        add_submenu_page(
            BandejaPage::SLUG,
            __( 'Configuración de cambios', 'entre-redes-cambios' ),
            __( 'Configuración', 'entre-redes-cambios' ),
            ProcessOwnerAuthorizer::CAPABILITY,
            ConfiguracionPage::SLUG,
            [ $this->configuracionPage, 'render' ]
        );

        add_action( 'admin_init', [ $this->bandejaPage, 'handlePost' ] );
        add_action( 'admin_init', [ $this->configuracionPage, 'handlePost' ] );

        if ( null !== $this->nuevaSolicitudPage ) {
            add_action( 'admin_init', [ $this->nuevaSolicitudPage, 'handlePost' ] );
        }
    }
}

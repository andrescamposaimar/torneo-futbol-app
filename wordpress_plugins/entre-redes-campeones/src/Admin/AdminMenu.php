<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Admin;

/**
 * Registers the "campeones" top-level menu and its hidden detail subpage
 * in wp-admin (design §6).
 *
 * Only what this slice ships is wired here: the Titles list (top-level
 * page) and the hidden campeones-titulo-edit subpage, registered via
 * add_submenu_page(null, ...) — the standard WP hidden-detail-page idiom,
 * reachable only from a row link on the Titles page, never shown in the
 * visible menu. campeones-revision (slice 4) and campeones-import
 * (slice 6) are added by extending this class in their own slices.
 *
 * All pages are gated by manage_options, re-checked inside each page's own
 * render()/handlePost() — not here.
 */
class AdminMenu {

    public function __construct(
        private readonly TitlesPage $titlesPage,
        private readonly TitleEditorPage $titleEditorPage
    ) {
    }

    /**
     * Called on the admin_menu hook. Registers the top-level menu, the
     * hidden editor subpage, and both pages' POST handlers on admin_init.
     */
    public function register(): void {
        add_menu_page(
            __( 'Campeones', 'entre-redes-campeones' ),
            __( 'Campeones', 'entre-redes-campeones' ),
            'manage_options',
            'campeones',
            [ $this->titlesPage, 'render' ],
            'dashicons-awards',
            57
        );

        add_submenu_page(
            null,
            __( 'Editar título', 'entre-redes-campeones' ),
            __( 'Editar título', 'entre-redes-campeones' ),
            'manage_options',
            'campeones-titulo-edit',
            [ $this->titleEditorPage, 'render' ]
        );

        add_action( 'admin_init', [ $this->titlesPage, 'handlePost' ] );
        add_action( 'admin_init', [ $this->titleEditorPage, 'handlePost' ] );
    }
}

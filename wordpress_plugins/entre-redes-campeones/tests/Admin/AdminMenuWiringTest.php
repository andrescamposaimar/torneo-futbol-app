<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Admin;

use EntreRedes\Campeones\Admin\AdminMenu;
use EntreRedes\Campeones\Admin\TitleEditorPage;
use EntreRedes\Campeones\Admin\TitlesPage;
use EntreRedes\Campeones\Linking\LinkResolver;
use EntreRedes\Campeones\Linking\LinkWriteService;
use EntreRedes\Campeones\Linking\RevalidationService;
use EntreRedes\Campeones\Linking\WpPlayerDirectory;
use EntreRedes\Campeones\Migrations\InitialSchema;
use EntreRedes\Campeones\Titles\SquadRepository;
use EntreRedes\Campeones\Titles\TitleDeletionService;
use EntreRedes\Campeones\Titles\TitleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdminMenu wiring (task 3.2). Mirrors entre-redes-prode's
 * AdminMenuPredictionsTest / PluginAdminWiringTest: all WP hook functions
 * (add_menu_page, add_submenu_page, add_action) are no-ops in the shim, so
 * register() must complete without throwing. This only proves the wiring
 * exists — TitlesPage/TitleEditorPage's own capability guards are tested
 * in their own test files.
 */
class AdminMenuWiringTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();
    }

    private function makeAdminMenu(): AdminMenu {
        global $wpdb;

        $titles    = new TitleRepository( $wpdb );
        $squads    = new SquadRepository( $wpdb );
        $directory = new WpPlayerDirectory( $wpdb );
        $resolver  = new LinkResolver( $directory );
        $writer    = new LinkWriteService( $squads, $directory );

        $titlesPage = new TitlesPage(
            $titles,
            new TitleDeletionService( $wpdb, $titles, $squads ),
            new RevalidationService( $titles, $squads, $resolver, $writer )
        );
        $editorPage = new TitleEditorPage( $titles, $squads, $resolver, $writer, $directory );

        return new AdminMenu( $titlesPage, $editorPage );
    }

    public function test_admin_menu_can_be_instantiated(): void {
        $this->assertInstanceOf( AdminMenu::class, $this->makeAdminMenu() );
    }

    public function test_register_runs_without_error(): void {
        $menu = $this->makeAdminMenu();
        $menu->register();
        $this->assertTrue( true );
    }
}

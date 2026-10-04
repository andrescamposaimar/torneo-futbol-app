<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas;

use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\Exception\ListaEsperaTeamUnresolvableException;
use EntreRedes\Cambios\Plazas\ListaEsperaResolver;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ListaEsperaResolver against the in-memory SQLite
 * shim — real Settings, plus ad hoc `wp_terms` / `wp_posts` tables (same
 * pattern as CandidatosResolverTest's own ad hoc WordPress core tables).
 */
class ListaEsperaResolverTest extends TestCase {

    private const SEASON_ID = 359;

    private Settings $settings;
    private InMemoryEventLog $eventLog;
    private ListaEsperaResolver $resolver;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;

        // Emptied, deliberately NOT reseeded here (see SettingsTest's own
        // setUp()/tearDown() split for the same convention) — putSetting()
        // below does a plain INSERT, which would collide with
        // InitialSchema::up()'s own seeded row for the same setting_key.
        $wpdb->query( "DELETE FROM {$p}cambios_settings" );

        wp_test_create_posts_table( $wpdb );
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}terms ( term_id INTEGER PRIMARY KEY, name TEXT, slug TEXT )" );
        $wpdb->query( "DELETE FROM {$p}terms" );

        $this->settings = new Settings( $wpdb );
        $this->eventLog = new InMemoryEventLog();
        $this->resolver = new ListaEsperaResolver( $wpdb, $this->settings, $this->eventLog );
    }

    protected function tearDown(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_settings" );
        InitialSchema::up();
        $wpdb->query( "DELETE FROM {$p}posts" );
        $wpdb->query( "DELETE FROM {$p}terms" );
    }

    private function putSetting( string $key, string $value ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$p}cambios_settings (setting_key, setting_value, updated_at) VALUES (%s, %s, %s)",
                $key,
                $value,
                '2026-01-01 00:00:00'
            )
        );
    }

    private function seedSeasonTerm( int $seasonId, string $name ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'terms', [ 'term_id' => $seasonId, 'name' => $name, 'slug' => $name ] );
    }

    private function seedTeam( int $id, string $slug, string $status = 'publish' ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'posts', [ 'ID' => $id, 'post_type' => 'sp_team', 'post_status' => $status, 'post_name' => $slug ] );
    }

    // -------------------------------------------------------------------------
    // Override wins, no lookup at all
    // -------------------------------------------------------------------------

    public function test_an_explicit_override_is_returned_without_any_lookup(): void {
        $this->putSetting( 'lista_espera_team_id', '14349' );
        // Deliberately no season term and no sp_team post seeded — if the
        // override were not trusted on its own, this would throw.

        $this->assertSame( 14349, $this->resolver->resolve( self::SEASON_ID ) );
    }

    // -------------------------------------------------------------------------
    // Dynamic resolution by slug
    // -------------------------------------------------------------------------

    public function test_resolves_the_published_team_whose_slug_matches_the_seasons_year(): void {
        $this->seedSeasonTerm( self::SEASON_ID, '2026' );
        $this->seedTeam( 14349, 'lista-de-espera-2026' );

        $this->assertSame( 14349, $this->resolver->resolve( self::SEASON_ID ) );
    }

    /**
     * The season term's name does not have to be EXACTLY the year — see the
     * class's own docblock for why this is a substring search, not an exact
     * match.
     */
    public function test_resolves_the_year_as_a_substring_of_a_longer_season_term_name(): void {
        $this->seedSeasonTerm( self::SEASON_ID, 'Temporada 2026' );
        $this->seedTeam( 14349, 'lista-de-espera-2026' );

        $this->assertSame( 14349, $this->resolver->resolve( self::SEASON_ID ) );
    }

    public function test_never_matches_the_lista_de_no_inscriptos_team(): void {
        $this->seedSeasonTerm( self::SEASON_ID, '2026' );
        $this->seedTeam( 14349, 'lista-de-espera-2026' );
        $this->seedTeam( 16089, 'lista-de-no-inscriptos-2026' );

        $this->assertSame( 14349, $this->resolver->resolve( self::SEASON_ID ) );
    }

    // -------------------------------------------------------------------------
    // Loud failures — never a fallback to "no team"/0
    // -------------------------------------------------------------------------

    public function test_throws_when_the_season_term_does_not_exist(): void {
        // No term seeded at all for self::SEASON_ID.
        $this->expectException( ListaEsperaTeamUnresolvableException::class );

        $this->resolver->resolve( self::SEASON_ID );
    }

    public function test_throws_when_the_season_term_name_carries_no_4_digit_year(): void {
        $this->seedSeasonTerm( self::SEASON_ID, 'Temporada Actual' );

        $this->expectException( ListaEsperaTeamUnresolvableException::class );

        $this->resolver->resolve( self::SEASON_ID );
    }

    public function test_throws_when_no_published_team_has_the_expected_slug(): void {
        $this->seedSeasonTerm( self::SEASON_ID, '2026' );
        // No sp_team posts at all.

        $this->expectException( ListaEsperaTeamUnresolvableException::class );

        $this->resolver->resolve( self::SEASON_ID );
    }

    public function test_throws_when_the_matching_team_exists_but_is_not_published(): void {
        $this->seedSeasonTerm( self::SEASON_ID, '2026' );
        $this->seedTeam( 14349, 'lista-de-espera-2026', 'draft' );

        $this->expectException( ListaEsperaTeamUnresolvableException::class );

        $this->resolver->resolve( self::SEASON_ID );
    }

    public function test_records_a_lectura_fallida_event_before_throwing(): void {
        $this->seedSeasonTerm( self::SEASON_ID, '2026' );

        try {
            $this->resolver->resolve( self::SEASON_ID );
            $this->fail( 'Expected ListaEsperaTeamUnresolvableException.' );
        } catch ( ListaEsperaTeamUnresolvableException $e ) {
            // expected
        }

        $this->assertTrue( $this->eventLog->has( 'lectura.fallida' ) );
        $this->assertSame( 'ListaEsperaResolver::resolve', $this->eventLog->last()['contexto']['operacion'] );
    }
}

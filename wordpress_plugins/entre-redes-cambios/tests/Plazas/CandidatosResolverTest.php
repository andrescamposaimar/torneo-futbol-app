<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas;

use EntreRedes\Cambios\Dictamen\BloqueoReemplazoPolicy;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\CandidatosResolver;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for CandidatosResolver against the in-memory SQLite
 * shim — real PlazaRepository, plus ad hoc `wp_posts` / `wp_term_relationships`
 * / `wp_term_taxonomy` / `wp_postmeta` tables (WordPress core tables this
 * plugin's own test schema does not otherwise create — see
 * DictamenContextAssemblerTest for the same pattern applied to `wp_postmeta`
 * alone).
 */
class CandidatosResolverTest extends TestCase {

    private const SEASON_ID = 359;
    private const OTHER_SEASON_ID = 360;

    private PlazaRepository $plazaRepository;
    private CandidatosResolver $resolver;

    /** @var callable(int): int */
    private $countResolvedFechasSinceFn;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->query( "DELETE FROM {$p}cambios_ocupacion" );
        $wpdb->query( "DELETE FROM {$p}cambios_plaza" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}posts (
                ID INTEGER PRIMARY KEY,
                post_type TEXT,
                post_status TEXT
            )"
        );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}term_relationships (
                object_id INTEGER,
                term_taxonomy_id INTEGER
            )"
        );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}term_taxonomy (
                term_taxonomy_id INTEGER PRIMARY KEY,
                term_id INTEGER,
                taxonomy TEXT
            )"
        );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}postmeta (
                meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                meta_key TEXT,
                meta_value TEXT
            )"
        );
        $wpdb->query( "DELETE FROM {$p}posts" );
        $wpdb->query( "DELETE FROM {$p}term_relationships" );
        $wpdb->query( "DELETE FROM {$p}term_taxonomy" );
        $wpdb->query( "DELETE FROM {$p}postmeta" );

        // ONE sp_season taxonomy row per season, term_taxonomy_id == term_id
        // == season_id for simplicity — nothing in this test cares about the
        // distinction, only the plugin's own raw JOIN does.
        $wpdb->insert( $p . 'term_taxonomy', [ 'term_taxonomy_id' => self::SEASON_ID, 'term_id' => self::SEASON_ID, 'taxonomy' => 'sp_season' ] );
        $wpdb->insert( $p . 'term_taxonomy', [ 'term_taxonomy_id' => self::OTHER_SEASON_ID, 'term_id' => self::OTHER_SEASON_ID, 'taxonomy' => 'sp_season' ] );

        $this->plazaRepository = new PlazaRepository( $wpdb, new InMemoryEventLog() );
        $this->resolver        = new CandidatosResolver( $wpdb, $this->plazaRepository );

        $this->countResolvedFechasSinceFn = static fn ( int $fechaId ): int => 10;

        $this->seedFecha( 1, self::SEASON_ID );
    }

    protected function tearDown(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_ocupacion" );
        $wpdb->query( "DELETE FROM {$p}cambios_plaza" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );
        $wpdb->query( "DELETE FROM {$p}posts" );
        $wpdb->query( "DELETE FROM {$p}term_relationships" );
        $wpdb->query( "DELETE FROM {$p}term_taxonomy" );
        $wpdb->query( "DELETE FROM {$p}postmeta" );
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function seedFecha( int $fechaId, int $seasonId, string $playDate = '2026-05-30' ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'cambios_fecha',
            [
                'id'                 => $fechaId,
                'season_id'          => $seasonId,
                'orden'              => $fechaId,
                'torneo_liga_ids'    => '1',
                'torneo_label'       => 'Apertura',
                'numero_en_torneo'   => $fechaId,
                'play_date'          => $playDate,
                'play_date_original' => $playDate,
                'estado'             => 'programada',
                'created_at'         => '2026-01-01 00:00:00',
                'updated_at'         => '2026-01-01 00:00:00',
            ]
        );
    }

    /** @param array<string, mixed>|null $metrics Null = no sp_metrics row at all. */
    private function seedPlayer( int $playerId, int $seasonId, ?array $metrics ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( $p . 'posts', [ 'ID' => $playerId, 'post_type' => 'sp_player', 'post_status' => 'publish' ] );
        $wpdb->insert( $p . 'term_relationships', [ 'object_id' => $playerId, 'term_taxonomy_id' => $seasonId ] );

        if ( null !== $metrics ) {
            $wpdb->insert( $p . 'postmeta', [ 'post_id' => $playerId, 'meta_key' => 'sp_metrics', 'meta_value' => serialize( $metrics ) ] );
        }
    }

    private function plaza( int $puntajeTechoHalfPoints = 6 /* 3.0 */ ): int {
        return $this->plazaRepository->openPlaza(
            self::SEASON_ID,
            100,
            700,
            Puntaje::fromHalfPoints( $puntajeTechoHalfPoints ),
            'campo',
            1,
            '2026-03-01 10:00:00'
        );
    }

    // -------------------------------------------------------------------------
    // paraPlaza() — pool membership
    // -------------------------------------------------------------------------

    public function test_excludes_the_plazas_own_current_vigent_occupant(): void {
        $plazaId = $this->plaza();
        // 700 is the plaza's own titular/vigent occupant (see plaza()).
        $this->seedPlayer( 700, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '3' ] );
        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '3' ] );

        $plaza      = $this->plazaRepository->findPlaza( $plazaId );
        $candidatos = $this->resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );

        $ids = array_map( static fn ( $c ) => $c->playerId(), $candidatos );

        $this->assertNotContains( 700, $ids );
        $this->assertContains( 800, $ids );
    }

    public function test_excludes_players_registered_in_a_different_season(): void {
        $plazaId = $this->plaza();
        $this->seedPlayer( 800, self::OTHER_SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '3' ] );

        $plaza      = $this->plazaRepository->findPlaza( $plazaId );
        $candidatos = $this->resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );

        $this->assertSame( [], $candidatos );
    }

    // -------------------------------------------------------------------------
    // Viability
    // -------------------------------------------------------------------------

    public function test_a_padre_within_techo_and_free_is_viable(): void {
        $plazaId = $this->plaza( 6 ); // techo 3.0
        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );

        $plaza      = $this->plazaRepository->findPlaza( $plazaId );
        $candidatos = $this->resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );

        $this->assertCount( 1, $candidatos );
        $this->assertTrue( $candidatos[0]->esPadre() );
        $this->assertTrue( $candidatos[0]->viable() );
        $this->assertNull( $candidatos[0]->motivoNoViable() );
        $this->assertSame( 2.5, $candidatos[0]->puntaje()->toDecimal() );
    }

    public function test_a_candidate_over_the_techo_is_not_viable(): void {
        $plazaId = $this->plaza( 6 ); // techo 3.0
        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '5' ] );

        $plaza      = $this->plazaRepository->findPlaza( $plazaId );
        $candidatos = $this->resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );

        $this->assertFalse( $candidatos[0]->viable() );
        $this->assertSame( 'puntaje_excede_techo', $candidatos[0]->motivoNoViable() );
    }

    public function test_a_candidate_with_no_resolvable_puntaje_is_not_viable(): void {
        $plazaId = $this->plaza();
        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo' ] ); // no puntaje key

        $plaza      = $this->plazaRepository->findPlaza( $plazaId );
        $candidatos = $this->resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );

        $this->assertFalse( $candidatos[0]->viable() );
        $this->assertSame( 'puntaje_indeterminado', $candidatos[0]->motivoNoViable() );
    }

    public function test_a_candidate_occupying_another_vigent_plaza_is_not_viable(): void {
        $plazaId = $this->plaza();
        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );

        // 800 already vigently occupies a SECOND plaza this season.
        $this->plazaRepository->openPlaza( self::SEASON_ID, 200, 800, Puntaje::fromDecimal( 3.0 ), 'suplente', 1, '2026-03-01 10:00:00' );

        $plaza      = $this->plazaRepository->findPlaza( $plazaId );
        $candidatos = $this->resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );

        $candidato800 = current( array_filter( $candidatos, static fn ( $c ) => 800 === $c->playerId() ) );
        $this->assertFalse( $candidato800->viable() );
        $this->assertSame( 'ocupa_otra_plaza_vigente', $candidato800->motivoNoViable() );
    }

    /**
     * THE case that motivated the definition of "viable" (see
     * CandidatosResolver's own class docblock and the task brief): a padre
     * with a puntaje that fits the techo, but BLOCKED elsewhere by a trunca
     * closure, must NOT count as viable — otherwise a non-padre entrante
     * would be blocked by Reglas\PrioridadDePadresRespetada against a padre
     * who could not actually take the plaza either, leaving the team unable
     * to change anyone.
     */
    public function test_a_padre_blocked_by_a_trunca_closure_elsewhere_is_not_viable(): void {
        $this->seedFecha( 4, self::SEASON_ID, '2026-04-01' );
        $this->seedFecha( 7, self::SEASON_ID, '2026-05-01' );

        $plazaId = $this->plaza();
        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );

        // 800 left ANOTHER plaza 'trunca' — only 2 resolved fechas have
        // passed since (< 3, TOPE_TRES_FECHAS keeps them blocked).
        $otraPlazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 200, 111, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->plazaRepository->succeedOcupacion( $otraPlazaId, 800, 4, 'reemplazada', '2026-04-01 10:00:00' );
        $this->plazaRepository->succeedOcupacion( $otraPlazaId, 999, 7, 'trunca', '2026-05-01 10:00:00' );

        $plaza = $this->plazaRepository->findPlaza( $plazaId );
        $countResolvedFechasSinceFn = static fn ( int $fechaId ): int => 7 === $fechaId ? 2 : 10;

        $candidatos = $this->resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $countResolvedFechasSinceFn );

        $candidato800 = current( array_filter( $candidatos, static fn ( $c ) => 800 === $c->playerId() ) );
        $this->assertFalse( $candidato800->viable() );
        $this->assertSame( 'bloqueado_por_cierre_truncado', $candidato800->motivoNoViable() );
        $this->assertSame( 0, $this->resolver->contarPadresViables( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $countResolvedFechasSinceFn ) );
    }

    // -------------------------------------------------------------------------
    // contarPadresViables()
    // -------------------------------------------------------------------------

    public function test_contar_padres_viables_counts_only_viable_padres(): void {
        $plazaId = $this->plaza( 6 ); // techo 3.0

        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] ); // viable padre
        $this->seedPlayer( 801, self::SEASON_ID, [ 'caracter' => 'Padre de Alumno', 'puntaje' => '3' ] ); // viable padre
        $this->seedPlayer( 802, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '5' ] ); // padre pero excede techo
        $this->seedPlayer( 803, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2,5' ] ); // viable pero NO padre

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $this->assertSame(
            2,
            $this->resolver->contarPadresViables( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn )
        );
    }
}

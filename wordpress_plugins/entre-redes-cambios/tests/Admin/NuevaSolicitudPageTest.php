<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Admin;

use EntreRedes\Cambios\Admin\NuevaSolicitudPage;
use EntreRedes\Cambios\Admin\ProcessOwnerAuthorizer;
use EntreRedes\Cambios\Capitania\CapitanRepository;
use EntreRedes\Cambios\Dictamen\BloqueoReemplazoPolicy;
use EntreRedes\Cambios\Notificaciones\NotificaSolicitudes;
use EntreRedes\Cambios\Plazas\CandidatosResolver;
use EntreRedes\Cambios\Solicitudes\CrearSolicitud;
use EntreRedes\Cambios\Solicitudes\EstadoSolicitud;
use EntreRedes\Cambios\Solicitudes\SolicitudesAbiertasReader;
use EntreRedes\Cambios\Tests\Support\InMemoryCreationLock;
use EntreRedes\Cambios\Tests\Support\SeedsEquipoParaSolicitudes;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the "Nueva solicitud" admin page, against real
 * repositories and the real creation use case.
 *
 * `handleCrear()` ends in `wp_safe_redirect()` + `exit` and is never called
 * through to its end here — see `BandejaPageTest`'s docblock. Every test
 * either dies before it (`wp_die()` THROWS in the shim) or calls the public,
 * non-exiting `ejecutarCrear()`, which holds all of the page's decisions.
 */
class NuevaSolicitudPageTest extends TestCase {

    use SeedsEquipoParaSolicitudes;

    private const CAPITAN_PLAYER_ID = 555;
    private const WP_USER_ID        = 42;

    private NuevaSolicitudPage $page;
    private CapitanRepository $capitanRepository;

    /** @var array<int, string> */
    private array $avisos = [];

    protected function setUp(): void {
        $this->armarEquipo();

        global $wpdb, $wp_test_post_titles, $wp_test_users, $wp_test_current_user_id;
        $p = $wpdb->prefix;

        // The candidate pool reads wp_posts / term tables — WordPress core
        // tables the plugin's own schema does not own.
        wp_test_create_posts_table( $wpdb );
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}term_relationships ( object_id INTEGER, term_taxonomy_id INTEGER )" );
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}term_taxonomy ( term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT )" );
        foreach ( [ 'posts', 'term_relationships', 'term_taxonomy' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }
        $wpdb->insert( $p . 'term_taxonomy', [ 'term_taxonomy_id' => $this->seasonId, 'term_id' => $this->seasonId, 'taxonomy' => 'sp_season' ] );
        foreach ( [ $this->entrantePlayerId, $this->otroEntrantePlayerId ] as $playerId ) {
            $wpdb->insert( $p . 'posts', [ 'ID' => $playerId, 'post_type' => 'sp_player', 'post_status' => 'publish' ] );
            $wpdb->insert( $p . 'term_relationships', [ 'object_id' => $playerId, 'term_taxonomy_id' => $this->seasonId ] );
        }

        $wp_test_post_titles = [
            $this->teamId                => 'SUIZA',
            self::CAPITAN_PLAYER_ID      => 'Capitan Suarez',
            $this->arqueroPlayerId       => 'Arquero Perez',
            $this->titularPlayerId       => 'Titular Gomez',
            $this->entrantePlayerId      => 'Entrante Lopez',
            $this->otroEntrantePlayerId  => 'Otro Diaz',
        ];
        // The genesis fecha only exists to let openPlaza() run. Left
        // `programada` it would be the EARLIEST unresolved fecha — i.e. "the
        // open fecha" — instead of the real one, fecha 5.
        $wpdb->query( "UPDATE {$p}cambios_fecha SET estado = 'jugada' WHERE id = 1" );

        $wp_test_users           = [ self::WP_USER_ID => 'Fernando Comision' ];
        $wp_test_current_user_id = self::WP_USER_ID;

        $this->capitanRepository = new CapitanRepository( $wpdb, $this->eventLog );
        $this->avisos            = [];

        unset(
            $GLOBALS['wp_test_current_user_can'],
            $GLOBALS['wp_test_check_admin_referer'],
            $GLOBALS['_prode_test_transients']
        );
        $_POST = [];
        $_GET  = [];

        $this->page = $this->nuevaPagina();
    }

    protected function tearDown(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        foreach ( [ 'posts', 'term_relationships', 'term_taxonomy' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }

        $this->limpiarEquipo();

        unset(
            $GLOBALS['wp_test_post_titles'],
            $GLOBALS['wp_test_users'],
            $GLOBALS['wp_test_current_user_id'],
            $GLOBALS['wp_test_current_user_can'],
            $GLOBALS['wp_test_check_admin_referer'],
            $GLOBALS['_prode_test_transients']
        );
        $_POST = [];
        $_GET  = [];
    }

    // -------------------------------------------------------------------------
    // Authorization and nonce
    // -------------------------------------------------------------------------

    public function test_an_unauthorized_post_is_denied_logged_and_creates_nothing(): void {
        $this->designarCapitan();
        $GLOBALS['wp_test_current_user_can'] = false;
        $_POST = $this->postValido();

        try {
            $this->page->handlePost();
            $this->fail( 'Expected wp_die() for an unauthorized request.' );
        } catch ( \RuntimeException $e ) {
            // wp_die() throws in the shim.
        }

        $this->assertSame( 0, $this->countSolicitudes() );
        $this->assertTrue( $this->eventLog->has( 'admin.autorizacion_denegada' ) );
        $this->assertSame( [], $this->avisos );
    }

    public function test_an_authorized_post_with_a_bad_nonce_dies_before_creating_anything(): void {
        $this->designarCapitan();
        $GLOBALS['wp_test_current_user_can']     = true;
        $GLOBALS['wp_test_check_admin_referer'] = false;
        $_POST = $this->postValido();

        try {
            $this->page->handlePost();
            $this->fail( 'Expected check_admin_referer() to die on an invalid nonce.' );
        } catch ( \RuntimeException $e ) {
            // check_admin_referer() -> wp_die() throws in the shim.
        }

        $this->assertSame( 0, $this->countSolicitudes() );
        $this->assertSame( [], $this->avisos );
    }

    public function test_a_post_for_another_action_is_ignored(): void {
        $GLOBALS['wp_test_current_user_can'] = false;
        $_POST = [ 'cambios_action' => 'aprobar' ];

        $this->page->handlePost();

        $this->assertFalse( $this->eventLog->has( 'admin.autorizacion_denegada' ), 'Another page\'s POST is none of this page\'s business.' );
    }

    public function test_rendering_without_the_capability_is_denied(): void {
        $GLOBALS['wp_test_current_user_can'] = false;

        $this->expectException( \RuntimeException::class );

        $this->page->render();
    }

    // -------------------------------------------------------------------------
    // ejecutarCrear()
    // -------------------------------------------------------------------------

    public function test_a_team_without_a_current_captain_is_refused_and_nothing_is_created(): void {
        $resultado = $this->page->ejecutarCrear( $this->teamId, 'sustitucion', $this->plazaCampoId, $this->entrantePlayerId, 0, self::WP_USER_ID );

        $this->assertFalse( $resultado['ok'] );
        $this->assertStringContainsString( 'no tiene un capitán vigente', $resultado['mensaje'] );
        $this->assertSame( 0, $this->countSolicitudes() );
        $this->assertSame( [], $this->avisos );
    }

    public function test_a_revoked_captain_counts_as_no_captain(): void {
        $this->designarCapitan();
        $this->capitanRepository->revokeCapitan( $this->seasonId, $this->teamId, '2026-05-01 00:00:00' );

        $resultado = $this->page->ejecutarCrear( $this->teamId, 'regreso', $this->plazaCampoId, 0, 0, self::WP_USER_ID );

        $this->assertFalse( $resultado['ok'] );
        $this->assertSame( 0, $this->countSolicitudes() );
    }

    public function test_success_stores_the_captain_as_solicitante_and_the_wp_user_as_loader(): void {
        $this->designarCapitan();

        $resultado = $this->page->ejecutarCrear( $this->teamId, 'sustitucion', $this->plazaCampoId, $this->entrantePlayerId, 0, self::WP_USER_ID );

        $this->assertTrue( $resultado['ok'], $resultado['mensaje'] );
        $this->assertNotNull( $resultado['solicitud_id'] );

        $row = $this->solicitudRepository->findSolicitud( (int) $resultado['solicitud_id'] );
        $this->assertNotNull( $row );
        $this->assertSame( self::CAPITAN_PLAYER_ID, (int) $row['solicitada_por'], 'The request is the captain\'s.' );
        $this->assertSame( self::WP_USER_ID, (int) $row['creada_por_wp_user'], 'The WP user who typed it in is recorded apart.' );
        $this->assertSame( EstadoSolicitud::PENDIENTE, $row['estado'] );
        $this->assertSame( $this->fechaId, (int) $row['fecha_id'], 'It attaches to the open fecha.' );
        $this->assertSame( $this->fixedNow, (int) $row['solicitud_instante_epoch'], 'It is evaluated at the injected "now".' );

        $this->assertStringContainsString( 'Capitan Suarez', $resultado['mensaje'] );
        $this->assertStringContainsString( 'Dictamen: procede', $resultado['mensaje'] );
    }

    public function test_both_notifications_fire_after_a_successful_load(): void {
        $this->designarCapitan();

        $resultado = $this->page->ejecutarCrear( $this->teamId, 'sustitucion', $this->plazaCampoId, $this->entrantePlayerId, 0, self::WP_USER_ID );

        $id = (int) $resultado['solicitud_id'];
        $this->assertSame( [ "creada:{$id}", "capitan:{$id}" ], $this->avisos, 'The captain\'s mail is the confirmation that it was loaded.' );
    }

    public function test_the_wp_user_reaches_the_creation_event(): void {
        $this->designarCapitan();

        $this->page->ejecutarCrear( $this->teamId, 'sustitucion', $this->plazaCampoId, $this->entrantePlayerId, 0, self::WP_USER_ID );

        $creada = array_values( array_filter(
            $this->eventLog->all(),
            static fn ( array $e ): bool => 'solicitud.creada' === $e['evento']
        ) );

        $this->assertCount( 1, $creada );
        $this->assertSame( self::WP_USER_ID, $creada[0]['contexto']['creada_por_wp_user'] );
        $this->assertSame( 'Fernando Comision', $creada[0]['contexto']['creada_por_wp_user_nombre'] );
    }

    public function test_a_duplicate_shows_an_error_and_persists_nothing_more(): void {
        $this->designarCapitan();
        $this->page->ejecutarCrear( $this->teamId, 'sustitucion', $this->plazaCampoId, $this->entrantePlayerId, 0, self::WP_USER_ID );
        $this->avisos = [];

        $resultado = $this->page->ejecutarCrear( $this->teamId, 'sustitucion', $this->plazaCampoId, $this->otroEntrantePlayerId, 0, self::WP_USER_ID );

        $this->assertFalse( $resultado['ok'] );
        $this->assertNull( $resultado['solicitud_id'] );
        $this->assertStringContainsString( 'ya tiene una solicitud abierta', $resultado['mensaje'] );
        $this->assertSame( 1, $this->countSolicitudes() );
        $this->assertSame( [], $this->avisos, 'A refused load must not be announced.' );
    }

    public function test_an_entrante_already_in_an_open_request_is_refused(): void {
        $this->designarCapitan();
        $this->page->ejecutarCrear( $this->teamId, 'sustitucion', $this->plazaCampoId, $this->entrantePlayerId, 0, self::WP_USER_ID );

        $resultado = $this->page->ejecutarCrear( $this->teamId, 'sustitucion', $this->plazaArcoId, $this->entrantePlayerId, 0, self::WP_USER_ID );

        $this->assertFalse( $resultado['ok'] );
        $this->assertStringContainsString( 'ya figura como entrante', $resultado['mensaje'] );
        $this->assertSame( 1, $this->countSolicitudes() );
    }

    /** Advisory rules never refuse: the dictamen's verdict is reported, the row is stored. */
    public function test_an_out_of_window_load_is_stored_pendiente_and_says_it_does_not_procede(): void {
        global $wpdb;
        $this->designarCapitan();
        $wpdb->query( "UPDATE {$wpdb->prefix}cambios_fecha SET play_date = '2026-07-01', play_date_original = '2026-07-01' WHERE id = {$this->fechaId}" );

        $resultado = $this->page->ejecutarCrear( $this->teamId, 'sustitucion', $this->plazaCampoId, $this->entrantePlayerId, 0, self::WP_USER_ID );

        $this->assertTrue( $resultado['ok'], $resultado['mensaje'] );
        $this->assertStringContainsString( 'Dictamen: no procede', $resultado['mensaje'] );
        $this->assertStringContainsString( 'aprobarla de todos modos', $resultado['mensaje'] );

        $row = $this->solicitudRepository->findSolicitud( (int) $resultado['solicitud_id'] );
        $this->assertNotNull( $row );
        $this->assertSame( EstadoSolicitud::PENDIENTE, $row['estado'] );
    }

    public function test_a_regreso_needs_no_entrante(): void {
        $this->designarCapitan();

        $resultado = $this->page->ejecutarCrear( $this->teamId, 'regreso', $this->plazaCampoId, 0, 0, self::WP_USER_ID );

        $this->assertTrue( $resultado['ok'], $resultado['mensaje'] );
        $row = $this->solicitudRepository->findSolicitud( (int) $resultado['solicitud_id'] );
        $this->assertNotNull( $row );
        $this->assertSame( 'regreso', $row['tipo'] );
        $this->assertNull( $row['entrante_player_id'] );
    }

    public function test_a_reasignacion_arquero_uses_the_teams_goal_plaza_whatever_the_form_says(): void {
        $this->designarCapitan();

        // plaza_id 0 AND a wrong plaza would both be ignored: the goal plaza
        // is resolved server-side.
        $resultado = $this->page->ejecutarCrear( $this->teamId, 'reasignacion_arquero', 999999, $this->titularPlayerId, $this->entrantePlayerId, self::WP_USER_ID );

        $this->assertTrue( $resultado['ok'], $resultado['mensaje'] );

        $row = $this->solicitudRepository->findSolicitud( (int) $resultado['solicitud_id'] );
        $this->assertNotNull( $row );
        $this->assertSame( $this->plazaArcoId, (int) $row['plaza_id'] );
        $this->assertSame( $this->plazaCampoId, (int) $row['plaza_campo_id'] );
        $this->assertSame( self::WP_USER_ID, (int) $row['creada_por_wp_user'] );
    }

    /** The form carries no captain id: the captain is re-read when the POST lands. */
    public function test_a_captain_changed_between_steps_files_the_request_under_the_current_one(): void {
        $this->designarCapitan();
        $this->capitanRepository->revokeCapitan( $this->seasonId, $this->teamId, '2026-05-10 00:00:00' );
        $this->capitanRepository->designateCapitan( $this->seasonId, $this->teamId, 556, null, '2026-05-10 00:00:01' );

        $resultado = $this->page->ejecutarDesdePost( $this->postValido(), self::WP_USER_ID );

        $this->assertTrue( $resultado['ok'], $resultado['mensaje'] );
        $row = $this->solicitudRepository->findSolicitud( (int) $resultado['solicitud_id'] );
        $this->assertNotNull( $row );
        $this->assertSame( 556, (int) $row['solicitada_por'] );
    }

    public function test_a_reasignacion_arquero_post_maps_titular_and_outside_player_to_the_right_slots(): void {
        $this->designarCapitan();

        $resultado = $this->page->ejecutarDesdePost( [
            'team_id'            => (string) $this->teamId,
            'tipo'               => 'reasignacion_arquero',
            'plaza_id'           => '0',
            'titular_player_id'  => (string) $this->titularPlayerId,
            'entrante_player_id' => (string) $this->entrantePlayerId,
        ], self::WP_USER_ID );

        $this->assertTrue( $resultado['ok'], $resultado['mensaje'] );
        $row = $this->solicitudRepository->findSolicitud( (int) $resultado['solicitud_id'] );
        $this->assertNotNull( $row );
        $this->assertSame( $this->titularPlayerId, (int) $row['entrante_player_id'], 'The field titular moving into goal.' );
        $this->assertSame( $this->entrantePlayerId, (int) $row['entrante_campo_player_id'], 'The outside player filling the field plaza.' );
    }

    public function test_a_reasignacion_arquero_post_without_a_titular_reports_titular_requerido(): void {
        $this->designarCapitan();

        $resultado = $this->page->ejecutarDesdePost( [
            'team_id'            => (string) $this->teamId,
            'tipo'               => 'reasignacion_arquero',
            'entrante_player_id' => (string) $this->entrantePlayerId,
        ], self::WP_USER_ID );

        $this->assertFalse( $resultado['ok'] );
        $this->assertStringContainsString( 'titular de campo', $resultado['mensaje'] );
        $this->assertSame( 0, $this->countSolicitudes() );
    }

    public function test_a_reasignacion_arquero_post_without_the_outside_player_reports_entrante_campo_requerido(): void {
        $this->designarCapitan();

        $resultado = $this->page->ejecutarDesdePost( [
            'team_id'           => (string) $this->teamId,
            'tipo'              => 'reasignacion_arquero',
            'titular_player_id' => (string) $this->titularPlayerId,
        ], self::WP_USER_ID );

        $this->assertFalse( $resultado['ok'] );
        $this->assertStringContainsString( 'entrante_campo_player_id es obligatorio', $resultado['mensaje'] );
        $this->assertSame( 0, $this->countSolicitudes() );
    }

    public function test_a_team_without_a_goal_plaza_gets_a_specific_notice_and_never_reaches_the_use_case(): void {
        global $wpdb;
        $this->designarCapitan();
        $wpdb->query( "UPDATE {$wpdb->prefix}cambios_plaza SET es_arco = 0" );

        $resultado = $this->page->ejecutarCrear( $this->teamId, 'reasignacion_arquero', 0, $this->titularPlayerId, $this->entrantePlayerId, self::WP_USER_ID );

        $this->assertFalse( $resultado['ok'] );
        $this->assertStringContainsString( 'no tiene cargada una plaza de arquero', $resultado['mensaje'] );
        $this->assertStringNotContainsString( 'no es válida', $resultado['mensaje'] );
        $this->assertSame( 0, $this->countSolicitudes() );
        $this->assertSame( [], $this->avisos );
    }

    public function test_a_titular_without_exactly_one_field_plaza_names_the_player(): void {
        $this->designarCapitan();

        // 333 holds no plaza at all, so the vacated field plaza cannot be derived.
        $resultado = $this->page->ejecutarCrear( $this->teamId, 'reasignacion_arquero', 0, $this->entrantePlayerId, $this->otroEntrantePlayerId, self::WP_USER_ID );

        $this->assertFalse( $resultado['ok'] );
        $this->assertStringContainsString( 'Entrante Lopez no ocupa exactamente una plaza de campo', $resultado['mensaje'] );
        $this->assertFalse( $this->eventLog->has( 'admin.nueva_solicitud_fallida' ), 'It is a named precondition, not an internal failure.' );
        $this->assertSame( 0, $this->countSolicitudes() );
    }

    public function test_a_missing_entrante_is_reported_not_stored(): void {
        $this->designarCapitan();

        $resultado = $this->page->ejecutarCrear( $this->teamId, 'sustitucion', $this->plazaCampoId, 0, 0, self::WP_USER_ID );

        $this->assertFalse( $resultado['ok'] );
        $this->assertStringContainsString( 'no es válida', $resultado['mensaje'] );
        $this->assertSame( 0, $this->countSolicitudes() );
    }

    public function test_a_plaza_of_another_team_is_refused(): void {
        global $wpdb;
        $this->designarCapitan();
        $this->capitanRepository->designateCapitan( $this->seasonId, 200, 556, null, '2026-05-01 00:00:00' );

        $resultado = $this->page->ejecutarCrear( 200, 'regreso', $this->plazaCampoId, 0, 0, self::WP_USER_ID );

        $this->assertFalse( $resultado['ok'] );
        $this->assertSame( 0, $this->countSolicitudes() );
    }

    public function test_without_an_open_fecha_nothing_is_created(): void {
        global $wpdb;
        $this->designarCapitan();
        $wpdb->query( "UPDATE {$wpdb->prefix}cambios_fecha SET estado = 'jugada'" );

        $resultado = $this->page->ejecutarCrear( $this->teamId, 'regreso', $this->plazaCampoId, 0, 0, self::WP_USER_ID );

        $this->assertFalse( $resultado['ok'] );
        $this->assertStringContainsString( 'No hay una fecha abierta', $resultado['mensaje'] );
        $this->assertSame( 0, $this->countSolicitudes() );
    }

    public function test_an_unexpected_failure_is_logged_and_reported_without_leaking_the_exception(): void {
        $this->designarCapitan();

        $roto = new class() implements NotificaSolicitudes {
            public function notificarCreada( int $solicitudId ): void {
                throw new \RuntimeException( 'smtp secret detail' );
            }

            public function notificarCreadaAlCapitan( int $solicitudId ): void {
            }

            public function notificarDecision( int $solicitudId, string $estado ): void {
            }

            public function notificarCancelada( int $solicitudId ): void {
            }
        };

        $pagina = $this->nuevaPagina( $roto );

        $resultado = $pagina->ejecutarCrear( $this->teamId, 'regreso', $this->plazaCampoId, 0, 0, self::WP_USER_ID );

        $this->assertFalse( $resultado['ok'] );
        $this->assertStringNotContainsString( 'smtp secret detail', $resultado['mensaje'] );
        $this->assertTrue( $this->eventLog->has( 'admin.nueva_solicitud_fallida' ), 'The name must contain "fallid" so WpEventLog persists it.' );
    }

    // -------------------------------------------------------------------------
    // Rendering — the steps of the form
    // -------------------------------------------------------------------------

    public function test_step_1_lists_only_teams_with_a_captain_and_names_the_captain(): void {
        $this->designarCapitan();
        $GLOBALS['wp_test_current_user_can'] = true;

        $html = $this->renderizar( [] );

        $this->assertStringContainsString( 'SUIZA', $html );
        $this->assertStringContainsString( 'capitán: Capitan Suarez', $html );
        $this->assertStringContainsString( 'name="team_id"', $html );
    }

    public function test_step_1_with_no_captains_offers_nothing_to_pick(): void {
        $GLOBALS['wp_test_current_user_can'] = true;

        $html = $this->renderizar( [] );

        $this->assertStringNotContainsString( 'SUIZA', $html );
    }

    public function test_step_2_shows_who_will_appear_as_solicitante_and_asks_for_the_tipo(): void {
        $this->designarCapitan();
        $GLOBALS['wp_test_current_user_can'] = true;

        $html = $this->renderizar( [ 'team_id' => (string) $this->teamId ] );

        $this->assertStringContainsString( 'Quedará como solicitante el capitán', $html );
        $this->assertStringContainsString( 'Capitan Suarez', $html );
        $this->assertStringContainsString( 'value="reasignacion_arquero"', $html );
    }

    public function test_step_3_for_a_sustitucion_lists_the_plazas(): void {
        $this->designarCapitan();
        $GLOBALS['wp_test_current_user_can'] = true;

        $html = $this->renderizar( [ 'team_id' => (string) $this->teamId, 'tipo' => 'sustitucion' ] );

        $this->assertStringContainsString( 'name="plaza_id"', $html );
        $this->assertStringContainsString( 'Titular Gomez', $html );
        $this->assertStringContainsString( 'Arquero Perez', $html );
    }

    public function test_step_3_for_a_regreso_says_so_when_every_titular_is_in_his_plaza(): void {
        $this->designarCapitan();
        $GLOBALS['wp_test_current_user_can'] = true;

        $html = $this->renderizar( [ 'team_id' => (string) $this->teamId, 'tipo' => 'regreso' ] );

        $this->assertStringContainsString( 'no hay regresos para cargar', $html );
        $this->assertStringNotContainsString( 'name="plaza_id"', $html );
    }

    public function test_step_4_shows_candidates_with_puntaje_inside_a_nonce_protected_post_form(): void {
        $this->designarCapitan();
        $GLOBALS['wp_test_current_user_can'] = true;

        $html = $this->renderizar( [ 'team_id' => (string) $this->teamId, 'tipo' => 'sustitucion', 'plaza_id' => (string) $this->plazaCampoId ] );

        $this->assertStringContainsString( 'Entrante Lopez', $html );
        $this->assertStringContainsString( '2.5', $html, 'The candidate\'s puntaje must be shown.' );
        $this->assertStringContainsString( 'method="post"', $html );
        $this->assertStringContainsString( self::NONCE_FIELD_NAME, $html );
        $this->assertStringContainsString( 'value="crear_solicitud"', $html );
        $this->assertStringContainsString( 'Cargar solicitud', $html );
    }

    public function test_step_4_marks_a_non_viable_candidate_and_disables_it(): void {
        global $wpdb;
        $this->designarCapitan();
        $GLOBALS['wp_test_current_user_can'] = true;

        // 444 already holds ANOTHER plaza of a different team: he is in the
        // pool but cannot enter this one.
        $plazaDeOtroEquipo = $this->plazaRepository->openPlaza( $this->seasonId, 200, $this->otroEntrantePlayerId, \EntreRedes\Cambios\Plazas\Puntaje::fromDecimal( 2.5 ), 1, '2026-01-01 00:00:00' );
        $this->assertGreaterThan( 0, $plazaDeOtroEquipo );

        $html = $this->renderizar( [ 'team_id' => (string) $this->teamId, 'tipo' => 'sustitucion', 'plaza_id' => (string) $this->plazaCampoId ] );

        $this->assertStringContainsString( 'No puede entrar: ya ocupa otra plaza', $html );
        $this->assertMatchesRegularExpression( '/value="' . $this->otroEntrantePlayerId . '" required disabled/', $html );
        $this->assertDoesNotMatchRegularExpression( '/value="' . $this->entrantePlayerId . '" required disabled/', $html );
    }

    public function test_a_regreso_plaza_step_ends_in_the_confirmation_form_without_candidates(): void {
        global $wpdb;
        $this->designarCapitan();
        $GLOBALS['wp_test_current_user_can'] = true;

        // A suplente holds the field plaza, so a regreso is meaningful.
        $this->plazaRepository->succeedOcupacion( $this->plazaCampoId, $this->entrantePlayerId, 1, 'reemplazada', '2026-02-01 00:00:00' );

        $html = $this->renderizar( [ 'team_id' => (string) $this->teamId, 'tipo' => 'regreso', 'plaza_id' => (string) $this->plazaCampoId ] );

        $this->assertStringContainsString( 'Vuelve el titular', $html );
        $this->assertStringContainsString( 'Cargar solicitud', $html );
        $this->assertStringNotContainsString( 'name="entrante_player_id"', $html );
    }

    public function test_a_success_notice_links_to_the_bandeja(): void {
        $this->designarCapitan();
        $GLOBALS['wp_test_current_user_can'] = true;

        set_transient( 'cambios_nueva_solicitud_notice_' . self::WP_USER_ID, [ 'tipo' => 'success', 'mensaje' => 'Solicitud #7 cargada.' ], 60 );

        $html = $this->renderizar( [] );

        $this->assertStringContainsString( 'Solicitud #7 cargada.', $html );
        $this->assertStringContainsString( 'page=cambios-bandeja', $html );
        $this->assertStringContainsString( 'Ver en la Bandeja', $html );
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private const NONCE_FIELD_NAME = 'cambios_nueva_solicitud_nonce';

    private function designarCapitan(): void {
        $this->capitanRepository->designateCapitan( $this->seasonId, $this->teamId, self::CAPITAN_PLAYER_ID, null, '2026-01-01 00:00:00' );
    }

    /** @return array<string, string> */
    private function postValido(): array {
        return [
            'cambios_action'     => 'crear_solicitud',
            'team_id'            => (string) $this->teamId,
            'tipo'               => 'sustitucion',
            'plaza_id'           => (string) $this->plazaCampoId,
            'entrante_player_id' => (string) $this->entrantePlayerId,
        ];
    }

    /** @param array<string, string> $get */
    private function renderizar( array $get ): string {
        $_GET = $get;

        ob_start();
        try {
            $this->page->render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private function nuevaPagina( ?NotificaSolicitudes $notifier = null ): NuevaSolicitudPage {
        global $wpdb;

        $avisos = &$this->avisos;

        $notifier ??= new class( $avisos ) implements NotificaSolicitudes {
            /** @var array<int, string> */
            private array $registro;

            /** @param array<int, string> $avisos */
            public function __construct( array &$avisos ) {
                $this->registro = &$avisos;
            }

            public function notificarCreada( int $solicitudId ): void {
                $this->registro[] = 'creada:' . $solicitudId;
            }

            public function notificarCreadaAlCapitan( int $solicitudId ): void {
                $this->registro[] = 'capitan:' . $solicitudId;
            }

            public function notificarDecision( int $solicitudId, string $estado ): void {
            }

            public function notificarCancelada( int $solicitudId ): void {
            }
        };

        return new NuevaSolicitudPage(
            new ProcessOwnerAuthorizer(),
            new CrearSolicitud( $this->plazaRepository, $this->solicitudRepository, $this->pipeline, new InMemoryCreationLock(), $notifier ),
            $this->capitanRepository ?? new CapitanRepository( $wpdb, $this->eventLog ),
            $this->plazaRepository,
            $this->fechaRepository,
            $this->settings,
            new CandidatosResolver( $wpdb, $this->plazaRepository, $this->eventLog, null, null, null, new SolicitudesAbiertasReader( $wpdb, $this->eventLog ) ),
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->jugadorMetricasReader,
            $this->eventLog,
            fn (): int => $this->fixedNow
        );
    }
}

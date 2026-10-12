<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Rest;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Capitania\CapitanAuthorizer;
use EntreRedes\Cambios\Dictamen\DictamenContextAssembler;
use EntreRedes\Cambios\Dictamen\DictamenPipeline;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\JugadorMetricasReader;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Rest\SolicitudesController;
use EntreRedes\Cambios\Solicitudes\SolicitudRepository;
use EntreRedes\Cambios\Tests\Support\SeedsSolicitudes;
use EntreRedes\Cambios\Tests\Support\InMemoryCreationLock;
use PHPUnit\Framework\TestCase;

/**
 * `POST /cambios/solicitudes` REFUSES (409, nothing persisted) a request
 * whose dictamen carries `plaza_con_solicitud_abierta` or
 * `entrante_con_solicitud_abierta`, and keeps STORING every other failing
 * dictamen. The assertion that matters in every refusal test is the row
 * count: a 409 with a row behind it would still hold the plaza.
 */
class SolicitudesControllerAbiertaRefusalTest extends TestCase {
    use SeedsSolicitudes;

    private const SEASON_ID = 359;
    private const TEAM_ID   = 100;
    private const OTHER_TEAM = 200;

    private InMemoryEventLog $eventLog;
    private PlazaRepository $plazaRepository;
    private SolicitudRepository $solicitudRepository;
    private SolicitudesController $controller;
    private int $fixedNow;
    private int $plazaArco;
    private int $plazaCampo;
    private int $plazaOrdinaria;
    private int $plazaAjena;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb, $wp_test_position_terms;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}postmeta (
                meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                meta_key TEXT,
                meta_value TEXT
            )"
        );
        $wpdb->query( "DELETE FROM {$p}postmeta" );

        $this->eventLog        = new InMemoryEventLog();
        $this->plazaRepository = new PlazaRepository( $wpdb, $this->eventLog );
        $fechaRepository       = new FechaRepository( $wpdb, new InMemoryEventLog() );
        $settings              = new Settings( $wpdb, $this->eventLog );

        $assembler = new DictamenContextAssembler( $this->plazaRepository, $fechaRepository, $settings, $wpdb, $this->eventLog );
        $pipeline  = new DictamenPipeline( $assembler, $this->eventLog, null, false, $settings->exencionArcoActiva() );

        $this->solicitudRepository = new SolicitudRepository( $wpdb, $this->plazaRepository, $pipeline, $this->eventLog );

        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $this->fixedNow   = ( new \DateTimeImmutable( '2026-05-20 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $this->controller = new SolicitudesController(
            $authorizer,
            $this->solicitudRepository,
            $pipeline,
            $this->eventLog,
            $this->plazaRepository,
            new JugadorMetricasReader( $wpdb, $this->eventLog ),
            fn (): int => $this->fixedNow,
            new InMemoryCreationLock()
        );

        $this->seedFecha( 1, '2026-01-01' );
        $this->seedFecha( 5, gmdate( 'Y-m-d', $this->fixedNow + 3 * DAY_IN_SECONDS ) );

        $wp_test_position_terms = [ 111 => [ 3 ] ]; // 111 is the goalkeeper
        $this->plazaArco        = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 111, Puntaje::fromDecimal( 2.5 ), 1, '2026-01-01 00:00:00' );

        $wp_test_position_terms = [];
        $this->plazaCampo      = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 222, Puntaje::fromDecimal( 3.0 ), 1, '2026-01-01 00:00:00' );
        $this->plazaOrdinaria  = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-01-01 00:00:00' );
        $this->plazaAjena      = $this->plazaRepository->openPlaza( self::SEASON_ID, self::OTHER_TEAM, 555, Puntaje::fromDecimal( 3.0 ), 1, '2026-01-01 00:00:00' );

        foreach ( [ 222 => 3.0, 333 => 2.5, 888 => 2.5, 889 => 2.5 ] as $playerId => $puntaje ) {
            $this->seedPuntaje( $playerId, $puntaje );
        }
    }

    protected function tearDown(): void {
        global $wpdb, $wp_test_position_terms;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha', 'postmeta' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }
        $wp_test_position_terms = [];
    }

    private function seedFecha( int $fechaId, string $playDate ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'cambios_fecha',
            [
                'id' => $fechaId, 'season_id' => self::SEASON_ID, 'orden' => $fechaId, 'torneo_liga_ids' => '1',
                'torneo_label' => 'Apertura', 'numero_en_torneo' => $fechaId, 'play_date' => $playDate,
                'play_date_original' => $playDate, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
            ]
        );
    }

    private function seedPuntaje( int $playerId, float $puntaje ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'postmeta',
            [ 'post_id' => $playerId, 'meta_key' => 'sp_metrics', 'meta_value' => serialize( [ 'puntaje' => (string) $puntaje ] ) ]
        );
    }

    private function filas(): int {
        global $wpdb;

        $rows = $wpdb->get_results( "SELECT COUNT(*) AS n FROM {$wpdb->prefix}cambios_solicitud", ARRAY_A );

        return (int) $rows[0]['n'];
    }

    /** @param array<string, mixed> $params */
    private function crear( array $params ): \WP_REST_Response {
        $request = new \WP_REST_Request();
        $request->set_header( 'authorization', 'Bearer a-valid-jwt' );

        foreach ( array_merge( [ 'season_id' => self::SEASON_ID, 'team_id' => self::TEAM_ID, 'fecha_id' => 5 ], $params ) as $k => $v ) {
            $request->set_param( $k, $v );
        }

        return $this->controller->crear( $request );
    }

    /** @return array<string, mixed> */
    private function sustitucion( int $plazaId, int $entrante ): array {
        return [ 'plaza_id' => $plazaId, 'tipo' => 'sustitucion', 'entrante_player_id' => $entrante ];
    }

    /** @return array<string, mixed> */
    private function grupo( int $titular = 222, int $filler = 333 ): array {
        return [
            'plaza_id' => $this->plazaArco, 'tipo' => 'reasignacion_arquero',
            'entrante_player_id' => $titular, 'entrante_campo_player_id' => $filler,
        ];
    }

    private const MENSAJE_PLAZA    = 'Ya hay una solicitud abierta para esa plaza. Tenés que esperar a que se resuelva antes de pedir otro cambio.';
    private const MENSAJE_ENTRANTE = 'Ese jugador ya figura en una solicitud abierta de esta temporada. No se puede pedir de nuevo hasta que se resuelva.';

    private function assertRefusado( \WP_REST_Response $response, string $code, int $filasAntes ): void {
        $this->assertSame( 409, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( $code, $data['code'] );
        $this->assertSame( 409, $data['data']['status'] );
        $this->assertSame(
            'plaza_con_solicitud_abierta' === $code ? self::MENSAJE_PLAZA : self::MENSAJE_ENTRANTE,
            $data['message'],
            'Each refusing code must carry ITS OWN copy.'
        );

        $this->assertTrue( $this->eventLog->has( 'solicitud.creacion_rechazada_duplicado' ), 'A refused duplicate must be traceable.' );
        $this->assertSame( $code, $this->eventLog->last()['contexto']['codigo'] );
        $this->assertSame( $filasAntes, $this->filas(), 'A refusal must persist NOTHING.' );
    }

    // --- sustitucion ----------------------------------------------------------

    public function test_sustitucion_on_a_plaza_with_an_open_request_is_refused_and_nothing_is_stored(): void {
        $this->seedSolicitud( [ 'plaza_id' => $this->plazaOrdinaria, 'entrante_player_id' => 999 ] );

        $this->assertRefusado( $this->crear( $this->sustitucion( $this->plazaOrdinaria, 888 ) ), 'plaza_con_solicitud_abierta', 1 );
    }

    public function test_sustitucion_naming_a_player_open_in_another_teams_request_is_refused_without_leaking_it(): void {
        $otra = $this->seedSolicitud( [ 'team_id' => self::OTHER_TEAM, 'plaza_id' => $this->plazaAjena, 'entrante_player_id' => 888 ] );

        $response = $this->crear( $this->sustitucion( $this->plazaOrdinaria, 888 ) );

        $this->assertRefusado( $response, 'entrante_con_solicitud_abierta', 1 );
        $this->assertStringNotContainsString( (string) $otra, json_encode( $response->get_data() ) );
        $this->assertStringNotContainsString( (string) self::OTHER_TEAM, json_encode( $response->get_data() ) );
    }

    public function test_a_closed_request_does_not_refuse(): void {
        $this->seedSolicitud( [ 'plaza_id' => $this->plazaOrdinaria, 'entrante_player_id' => 888, 'estado' => 'publicada' ] );

        $response = $this->crear( $this->sustitucion( $this->plazaOrdinaria, 888 ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 2, $this->filas() );
    }

    // --- regreso (plaza rule only) --------------------------------------------

    public function test_regreso_on_a_plaza_with_an_open_request_is_refused_and_nothing_is_stored(): void {
        $this->seedSolicitud( [ 'plaza_id' => $this->plazaOrdinaria, 'entrante_player_id' => 999 ] );

        $this->assertRefusado( $this->crear( [ 'plaza_id' => $this->plazaOrdinaria, 'tipo' => 'regreso' ] ), 'plaza_con_solicitud_abierta', 1 );
    }

    // --- grouped: either leg refuses, database untouched ----------------------

    public function test_grouped_refused_when_the_goal_plaza_has_an_open_request(): void {
        $this->seedSolicitud( [ 'plaza_id' => $this->plazaArco, 'entrante_player_id' => 999 ] );

        $this->assertRefusado( $this->crear( $this->grupo() ), 'plaza_con_solicitud_abierta', 1 );
    }

    public function test_grouped_refused_when_the_vacated_field_plaza_has_an_open_request(): void {
        $this->seedSolicitud( [ 'plaza_id' => $this->plazaCampo, 'entrante_player_id' => 999 ] );

        $this->assertRefusado( $this->crear( $this->grupo() ), 'plaza_con_solicitud_abierta', 1 );
    }

    public function test_grouped_refused_when_the_outside_filler_is_already_committed(): void {
        $this->seedSolicitud( [ 'plaza_id' => $this->plazaAjena, 'team_id' => self::OTHER_TEAM, 'entrante_player_id' => 333 ] );

        $this->assertRefusado( $this->crear( $this->grupo() ), 'entrante_con_solicitud_abierta', 1 );
    }

    public function test_grouped_refused_when_the_field_titular_moving_into_goal_is_already_committed(): void {
        $this->seedSolicitud( [ 'plaza_id' => $this->plazaOrdinaria, 'entrante_player_id' => 222 ] );

        $this->assertRefusado( $this->crear( $this->grupo() ), 'entrante_con_solicitud_abierta', 1 );
    }

    public function test_a_clean_grouped_request_is_stored_and_evaluated_exactly_once(): void {
        global $wpdb;

        $wpdb->queries = [];

        $response = $this->crear( $this->grupo() );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 1, $this->filas() );

        // One evaluateGrupo() = 2 legs x (1 plaza lookup + 1 entrante lookup).
        // A second evaluation (e.g. persisting first, re-evaluating after)
        // would double this.
        $lecturas = array_filter( $wpdb->queries, static fn ( string $sql ): bool => str_contains( $sql, 'estado IN' ) );
        $this->assertCount( 4, $lecturas );
    }

    // --- the other ten rules still persist ------------------------------------

    /**
     * Regression guard: a request that merely breaks a JUDGEMENT rule (here
     * the puntaje ceiling) is still stored `pendiente` with its motivos —
     * the process owner may overrule it. Refusing any failing dictamen would
     * turn every one of those into a 409 and make this test red.
     */
    public function test_an_over_ceiling_request_is_still_persisted_with_its_motivo(): void {
        $this->seedPuntaje( 890, 4.5 ); // techo of the plaza is 3.0

        $response = $this->crear( $this->sustitucion( $this->plazaOrdinaria, 890 ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertFalse( $response->get_data()['dictamen']['procede'] );
        $codigos = array_column( $response->get_data()['dictamen']['motivos'], 'codigo' );
        $this->assertContains( 'puntaje_excede_techo', $codigos );
        $this->assertNotContains( 'plaza_con_solicitud_abierta', $codigos );
        $this->assertSame( 1, $this->filas() );
    }

    // --- the plaza must belong to the authorised team -------------------------

    /**
     * @return array<string, array{array<string, mixed>}> params factories keyed by tipo,
     *         each naming the OTHER team's plaza as `plaza_id`. A closure is
     *         used because the plaza ids only exist after setUp().
     */
    public static function tiposContraPlazaAjena(): array {
        return [ 'sustitucion' => [ [ 'tipo' => 'sustitucion' ] ], 'regreso' => [ [ 'tipo' => 'regreso' ] ], 'reasignacion_arquero' => [ [ 'tipo' => 'reasignacion_arquero' ] ] ];
    }

    /** @return array<string, mixed> */
    private function contraPlazaAjena( string $tipo ): array {
        return match ( $tipo ) {
            'sustitucion'          => $this->sustitucion( $this->plazaAjena, 888 ),
            'regreso'              => [ 'plaza_id' => $this->plazaAjena, 'tipo' => 'regreso' ],
            'reasignacion_arquero' => array_merge( $this->grupo(), [ 'plaza_id' => $this->plazaAjena ] ),
        };
    }

    /**
     * @dataProvider tiposContraPlazaAjena
     * @param array{tipo: string} $caso
     */
    public function test_naming_another_teams_plaza_is_refused_and_nothing_is_stored( array $caso ): void {
        $response = $this->crear( $this->contraPlazaAjena( $caso['tipo'] ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'plaza_no_encontrada', $response->get_data()['code'] );
        $this->assertSame( 0, $this->filas() );
    }

    /**
     * THE ORACLE IS CLOSED: whether or not the other team has an open request
     * on its plaza, a captain of team A gets the identical response, so the
     * 409 can no longer be used to probe it.
     *
     * @dataProvider tiposContraPlazaAjena
     * @param array{tipo: string} $caso
     */
    public function test_the_response_for_another_teams_plaza_does_not_depend_on_its_open_requests( array $caso ): void {
        $sinAbierta = $this->crear( $this->contraPlazaAjena( $caso['tipo'] ) );

        $this->seedSolicitud( [ 'team_id' => self::OTHER_TEAM, 'plaza_id' => $this->plazaAjena, 'entrante_player_id' => 999 ] );
        $conAbierta = $this->crear( $this->contraPlazaAjena( $caso['tipo'] ) );

        $this->assertSame( $sinAbierta->get_status(), $conAbierta->get_status() );
        $this->assertSame( $sinAbierta->get_data(), $conAbierta->get_data() );
        $this->assertSame( 1, $this->filas(), 'Only the seeded row of team B exists; team A stored nothing.' );
    }

    public function test_a_plaza_of_the_same_team_in_another_season_gets_the_same_refusal(): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'cambios_fecha',
            [
                'id' => 90, 'season_id' => 360, 'orden' => 1, 'torneo_liga_ids' => '1', 'torneo_label' => 'Apertura',
                'numero_en_torneo' => 1, 'play_date' => '2026-01-01', 'play_date_original' => '2026-01-01',
                'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
            ]
        );
        $otraTemporada = $this->plazaRepository->openPlaza( 360, self::TEAM_ID, 444, Puntaje::fromDecimal( 3.0 ), 90, '2026-01-01 00:00:00' );

        $response = $this->crear( $this->sustitucion( $otraTemporada, 888 ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'plaza_no_encontrada', $response->get_data()['code'] );
        $this->assertSame( 0, $this->filas() );
    }

    public function test_a_nonexistent_plaza_gets_the_same_refusal(): void {
        $response = $this->crear( $this->sustitucion( 999999, 888 ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'plaza_no_encontrada', $response->get_data()['code'] );
    }

    // --- a regreso commits its returning titular ------------------------------

    /**
     * A `regreso` stores a NULL entrante, but the returning titular IS an
     * incoming player: while a regreso of titular 777 to P1 is open, naming
     * 777 as the entrante of ANY other request is refused. (Before 0.1.21
     * this was accepted — both requests were stored.)
     */
    public function test_a_pending_regreso_commits_its_returning_titular(): void {
        // P1 (plazaOrdinaria, titular 777) is held by suplente 888, so 777 occupies nothing.
        $this->plazaRepository->succeedOcupacion( $this->plazaOrdinaria, 888, 1, 'reemplazada', '2026-02-01 00:00:00' );
        $this->seedSolicitud( [ 'plaza_id' => $this->plazaOrdinaria, 'tipo' => 'regreso', 'entrante_player_id' => null ] );
        $this->seedPuntaje( 777, 2.5 );

        $this->assertRefusado( $this->crear( $this->sustitucion( $this->plazaCampo, 777 ) ), 'entrante_con_solicitud_abierta', 1 );
    }

    public function test_a_closed_regreso_no_longer_commits_its_titular(): void {
        $this->plazaRepository->succeedOcupacion( $this->plazaOrdinaria, 888, 1, 'reemplazada', '2026-02-01 00:00:00' );
        $this->seedSolicitud( [ 'plaza_id' => $this->plazaOrdinaria, 'tipo' => 'regreso', 'entrante_player_id' => null, 'estado' => 'anulada' ] );
        $this->seedPuntaje( 777, 2.5 );

        $this->assertSame( 200, $this->crear( $this->sustitucion( $this->plazaCampo, 777 ) )->get_status() );
    }

    public function test_a_regreso_is_refused_when_its_titular_is_already_the_entrante_of_an_open_request(): void {
        $this->seedSolicitud( [ 'team_id' => self::OTHER_TEAM, 'plaza_id' => $this->plazaAjena, 'entrante_player_id' => 777 ] );

        $this->assertRefusado( $this->crear( [ 'plaza_id' => $this->plazaOrdinaria, 'tipo' => 'regreso' ] ), 'entrante_con_solicitud_abierta', 1 );
    }

    public function test_a_regreso_with_no_competing_commitment_is_still_accepted(): void {
        $this->seedSolicitud( [ 'team_id' => self::OTHER_TEAM, 'plaza_id' => $this->plazaAjena, 'entrante_player_id' => 999 ] );

        $response = $this->crear( [ 'plaza_id' => $this->plazaOrdinaria, 'tipo' => 'regreso' ] );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 2, $this->filas() );
    }
}

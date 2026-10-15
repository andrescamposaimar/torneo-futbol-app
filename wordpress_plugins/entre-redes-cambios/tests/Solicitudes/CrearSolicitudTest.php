<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Solicitudes;

use EntreRedes\Cambios\Notificaciones\NotificaSolicitudes;
use EntreRedes\Cambios\Solicitudes\CrearSolicitud;
use EntreRedes\Cambios\Solicitudes\EstadoSolicitud;
use EntreRedes\Cambios\Solicitudes\Exception\CreationLockUnavailableException;
use EntreRedes\Cambios\Solicitudes\Exception\SolicitudAbiertaException;
use EntreRedes\Cambios\Solicitudes\Exception\SolicitudInvalidaException;
use EntreRedes\Cambios\Tests\Support\InMemoryCreationLock;
use EntreRedes\Cambios\Tests\Support\SeedsEquipoParaSolicitudes;
use PHPUnit\Framework\TestCase;

/**
 * The shared creation use case, against real repositories. The REST route and
 * the admin page both delegate here, so these are the properties BOTH rely on.
 */
class CrearSolicitudTest extends TestCase {

    use SeedsEquipoParaSolicitudes;

    private InMemoryCreationLock $lock;

    /** @var array<int, string> */
    private array $avisos = [];

    protected function setUp(): void {
        $this->armarEquipo();
        $this->lock   = new InMemoryCreationLock();
        $this->avisos = [];
    }

    protected function tearDown(): void {
        $this->limpiarEquipo();
    }

    public function test_sustitucion_is_stored_pendiente_with_its_dictamen(): void {
        $resultado = $this->crear( 'sustitucion', $this->plazaCampoId, $this->entrantePlayerId );

        $this->assertGreaterThan( 0, $resultado['id'] );
        $this->assertTrue( $resultado['dictamen']['procede'] );

        $row = $this->solicitudRepository->findSolicitud( $resultado['id'] );
        $this->assertNotNull( $row );
        $this->assertSame( 'sustitucion', $row['tipo'] );
        $this->assertSame( EstadoSolicitud::PENDIENTE, $row['estado'] );
        $this->assertSame( $this->entrantePlayerId, (int) $row['entrante_player_id'] );
        $this->assertSame( $this->titularPlayerId, (int) $row['saliente_player_id'] );
        $this->assertSame( 777, (int) $row['solicitada_por'] );
    }

    public function test_regreso_is_stored_pendiente_without_an_entrante(): void {
        $resultado = $this->crear( 'regreso', $this->plazaCampoId, null );

        $row = $this->solicitudRepository->findSolicitud( $resultado['id'] );
        $this->assertNotNull( $row );
        $this->assertSame( 'regreso', $row['tipo'] );
        $this->assertNull( $row['entrante_player_id'] );
        $this->assertSame( EstadoSolicitud::PENDIENTE, $row['estado'] );
    }

    public function test_reasignacion_arquero_derives_the_field_plaza_and_stores_both_movements(): void {
        $resultado = $this->crear( 'reasignacion_arquero', $this->plazaArcoId, $this->titularPlayerId, $this->entrantePlayerId );

        $this->assertTrue( $resultado['dictamen']['procede'] );

        $row = $this->solicitudRepository->findSolicitud( $resultado['id'] );
        $this->assertNotNull( $row );
        $this->assertSame( 'reasignacion_arquero', $row['tipo'] );
        $this->assertSame( $this->plazaCampoId, (int) $row['plaza_campo_id'], 'The field plaza must be DERIVED from the titular, never supplied.' );
        $this->assertSame( $this->entrantePlayerId, (int) $row['entrante_campo_player_id'] );
        $this->assertSame( $this->arqueroPlayerId, (int) $row['saliente_player_id'] );
    }

    public function test_a_captain_filed_request_leaves_creada_por_wp_user_null(): void {
        $resultado = $this->crear( 'sustitucion', $this->plazaCampoId, $this->entrantePlayerId );

        $row = $this->solicitudRepository->findSolicitud( $resultado['id'] );
        $this->assertNotNull( $row );
        $this->assertNull( $row['creada_por_wp_user'] );

        $payload = $this->eventLog->last()['contexto'] ?? [];
        $this->assertArrayNotHasKey( 'creada_por_wp_user', $payload, 'An app-filed request keeps the payload it always had.' );
    }

    public function test_a_loaded_for_captain_request_records_the_wp_user_in_the_row_and_the_event(): void {
        global $wp_test_users;
        $wp_test_users = [ 42 => 'Fernando Comision' ];

        $resultado = $this->usoCaso()->ejecutar(
            $this->seasonId, $this->teamId, $this->plazaCampoId, $this->fechaId, 'sustitucion', $this->entrantePlayerId, null, 777, $this->fixedNow, 42
        );

        $row = $this->solicitudRepository->findSolicitud( $resultado['id'] );
        $this->assertNotNull( $row );
        $this->assertSame( 42, (int) $row['creada_por_wp_user'] );
        $this->assertSame( 777, (int) $row['solicitada_por'], 'solicitada_por stays the captain\'s player id.' );

        $creada = array_values( array_filter(
            $this->eventLog->all(),
            static fn ( array $e ): bool => 'solicitud.creada' === $e['evento']
        ) );
        $this->assertSame( 42, $creada[0]['contexto']['creada_por_wp_user'] );
        $this->assertSame( 'Fernando Comision', $creada[0]['contexto']['creada_por_wp_user_nombre'] );

        $wp_test_users = [];
    }

    public function test_a_grouped_request_loaded_for_a_captain_records_the_wp_user_too(): void {
        $resultado = $this->usoCaso()->ejecutar(
            $this->seasonId, $this->teamId, $this->plazaArcoId, $this->fechaId, 'reasignacion_arquero', $this->titularPlayerId, $this->entrantePlayerId, 777, $this->fixedNow, 42
        );

        $row = $this->solicitudRepository->findSolicitud( $resultado['id'] );
        $this->assertNotNull( $row );
        $this->assertSame( 42, (int) $row['creada_por_wp_user'] );
    }

    public function test_an_open_request_on_the_plaza_is_refused_and_nothing_is_stored(): void {
        $this->crear( 'sustitucion', $this->plazaCampoId, $this->entrantePlayerId );
        $this->avisos = [];

        try {
            $this->crear( 'sustitucion', $this->plazaCampoId, $this->otroEntrantePlayerId );
            $this->fail( 'Expected the duplicate plaza to be refused.' );
        } catch ( SolicitudAbiertaException $e ) {
            $this->assertSame( 'plaza_con_solicitud_abierta', $e->codigo() );
        }

        $this->assertSame( 1, $this->countSolicitudes() );
        $this->assertSame( [], $this->avisos, 'A refused request must never be announced.' );
    }

    public function test_an_entrante_already_named_in_an_open_request_is_refused(): void {
        $this->crear( 'sustitucion', $this->plazaCampoId, $this->entrantePlayerId );

        try {
            $this->crear( 'sustitucion', $this->plazaArcoId, $this->entrantePlayerId );
            $this->fail( 'Expected the duplicate entrante to be refused.' );
        } catch ( SolicitudAbiertaException $e ) {
            $this->assertSame( 'entrante_con_solicitud_abierta', $e->codigo() );
        }

        $this->assertSame( 1, $this->countSolicitudes() );
    }

    /**
     * Every OTHER rule is advisory: a request far outside the window is still
     * stored `pendiente`, and the dictamen says why.
     */
    public function test_an_out_of_window_request_is_still_created_pendiente_with_fuera_de_plazo(): void {
        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->prefix}cambios_fecha SET play_date = '2026-07-01', play_date_original = '2026-07-01' WHERE id = {$this->fechaId}" );

        $resultado = $this->crear( 'sustitucion', $this->plazaCampoId, $this->entrantePlayerId );

        $this->assertFalse( $resultado['dictamen']['procede'] );
        $codigos = array_map( static fn ( array $m ): string => (string) $m['codigo'], $resultado['dictamen']['motivos'] );
        $this->assertContains( 'fuera_de_plazo', $codigos );

        $row = $this->solicitudRepository->findSolicitud( $resultado['id'] );
        $this->assertNotNull( $row );
        $this->assertSame( EstadoSolicitud::PENDIENTE, $row['estado'] );
    }

    public function test_a_plaza_of_another_team_is_indistinguishable_from_a_missing_one(): void {
        try {
            $this->usoCaso()->ejecutar( $this->seasonId, 999, $this->plazaCampoId, $this->fechaId, 'regreso', null, null, 777, $this->fixedNow );
            $this->fail( 'Expected plaza_no_encontrada.' );
        } catch ( SolicitudInvalidaException $e ) {
            $this->assertSame( 'plaza_no_encontrada', $e->codigo() );
        }

        $this->assertSame( 0, $this->countSolicitudes() );
    }

    public function test_input_validation_keeps_the_rest_codes(): void {
        $casos = [
            'campos_invalidos'          => [ 0, $this->fechaId, 'regreso', null, null ],
            'tipo_invalido'             => [ $this->plazaCampoId, $this->fechaId, 'otro', null, null ],
            'entrante_requerido'        => [ $this->plazaCampoId, $this->fechaId, 'sustitucion', null, null ],
            'titular_requerido'         => [ $this->plazaArcoId, $this->fechaId, 'reasignacion_arquero', null, 5 ],
            'entrante_campo_requerido'  => [ $this->plazaArcoId, $this->fechaId, 'reasignacion_arquero', $this->titularPlayerId, null ],
        ];

        foreach ( $casos as $codigo => [ $plazaId, $fechaId, $tipo, $entrante, $entranteCampo ] ) {
            try {
                $this->usoCaso()->ejecutar( $this->seasonId, $this->teamId, $plazaId, $fechaId, $tipo, $entrante, $entranteCampo, 777, $this->fixedNow );
                $this->fail( "Expected {$codigo}." );
            } catch ( SolicitudInvalidaException $e ) {
                $this->assertSame( $codigo, $e->codigo() );
            }
        }

        $this->assertSame( 0, $this->countSolicitudes() );
    }

    public function test_both_notifications_fire_once_after_a_successful_creation_and_outside_the_lock(): void {
        $resultado = $this->crear( 'sustitucion', $this->plazaCampoId, $this->entrantePlayerId );

        $this->assertSame(
            [ 'creada:' . $resultado['id'], 'capitan:' . $resultado['id'] ],
            $this->avisos
        );
    }

    /** Guards the guard: the double must actually throw for an inside-the-lock call, for both mails. */
    public function test_the_lock_watching_double_detects_a_call_made_inside_the_lock(): void {
        $notifier = $this->notifierQueVigilaElCandado();

        foreach ( [ 'notificarCreada', 'notificarCreadaAlCapitan' ] as $metodo ) {
            try {
                $this->lock->run( $this->seasonId, static fn () => $notifier->{$metodo}( 1 ) );
                $this->fail( "{$metodo} was not detected inside the lock." );
            } catch ( \LogicException $e ) {
                $this->assertStringContainsString( 'lock was still held', $e->getMessage() );
            }
        }

        $this->assertSame( [], $this->avisos );
    }

    public function test_without_a_notifier_creation_still_succeeds(): void {
        $caso = new CrearSolicitud( $this->plazaRepository, $this->solicitudRepository, $this->pipeline, $this->lock );

        $resultado = $caso->ejecutar( $this->seasonId, $this->teamId, $this->plazaCampoId, $this->fechaId, 'regreso', null, null, 777, $this->fixedNow );

        $this->assertGreaterThan( 0, $resultado['id'] );
    }

    public function test_wired_without_a_lock_it_fails_loudly_instead_of_creating_unserialized(): void {
        $caso = new CrearSolicitud( $this->plazaRepository, $this->solicitudRepository, $this->pipeline, null );

        $this->expectException( \LogicException::class );

        try {
            $caso->ejecutar( $this->seasonId, $this->teamId, $this->plazaCampoId, $this->fechaId, 'regreso', null, null, 777, $this->fixedNow );
        } finally {
            $this->assertSame( 0, $this->countSolicitudes() );
        }
    }

    public function test_an_unavailable_lock_propagates_and_stores_nothing(): void {
        $this->lock = new InMemoryCreationLock( false );

        try {
            $this->crear( 'regreso', $this->plazaCampoId, null );
            $this->fail( 'Expected CreationLockUnavailableException.' );
        } catch ( CreationLockUnavailableException $e ) {
            $this->assertSame( 0, $this->countSolicitudes() );
            $this->assertSame( [], $this->avisos );
        }
    }

    // -------------------------------------------------------------------------

    /** @return array{id: int, dictamen: array<string, mixed>} */
    private function crear( string $tipo, int $plazaId, ?int $entrante, ?int $entranteCampo = null ): array {
        return $this->usoCaso()->ejecutar(
            $this->seasonId,
            $this->teamId,
            $plazaId,
            $this->fechaId,
            $tipo,
            $entrante,
            $entranteCampo,
            777,
            $this->fixedNow
        );
    }

    private function usoCaso(): CrearSolicitud {
        return new CrearSolicitud( $this->plazaRepository, $this->solicitudRepository, $this->pipeline, $this->lock, $this->notifierQueVigilaElCandado() );
    }

    /**
     * The double refuses to be called while the creation lock is held — for
     * BOTH mails. Proven to bite by the companion test below.
     */
    private function notifierQueVigilaElCandado(): NotificaSolicitudes {
        $avisos = &$this->avisos;

        return new class( $avisos, $this->lock ) implements NotificaSolicitudes {
            /** @var array<int, string> */
            private array $registro;

            /** @param array<int, string> $avisos */
            public function __construct( array &$avisos, private InMemoryCreationLock $lock ) {
                $this->registro = &$avisos;
            }

            public function notificarCreada( int $solicitudId ): void {
                if ( $this->lock->isHeld() ) {
                    throw new \LogicException( 'Notified while the creation lock was still held.' );
                }
                $this->registro[] = 'creada:' . $solicitudId;
            }

            public function notificarCreadaAlCapitan( int $solicitudId ): void {
                if ( $this->lock->isHeld() ) {
                    throw new \LogicException( 'Captain notified while the creation lock was still held.' );
                }
                $this->registro[] = 'capitan:' . $solicitudId;
            }

            public function notificarDecision( int $solicitudId, string $estado ): void {
            }

            public function notificarCancelada( int $solicitudId ): void {
            }
        };
    }
}

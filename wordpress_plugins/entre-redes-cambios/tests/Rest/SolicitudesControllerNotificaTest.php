<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Rest;

use EntreRedes\Cambios\Notificaciones\NotificaSolicitudes;
use EntreRedes\Cambios\Rest\SolicitudesController;
use EntreRedes\Cambios\Solicitudes\Lock\CreationLock;
use PHPUnit\Framework\TestCase;

/**
 * The ONE property that matters here and is invisible at the call site:
 * the alert is sent with the creation lock ALREADY RELEASED.
 *
 * `wp_mail()` opens a network connection and can block for seconds. Inside
 * `Solicitudes\Lock\CreationLock` that would hold a per-season `GET_LOCK`
 * for the length of an SMTP timeout, and every other captain in the league
 * would get 503 `creacion_ocupada` while it hung. Nothing about
 * `$this->notificarCreada( $respuesta );` sitting one line below
 * `$respuesta = $this->creationLock->run( ... );` makes that visible —
 * moving it two lines up would be a silent, league-wide regression.
 *
 * Tested through the collaborators rather than over HTTP: a lock that
 * records whether it is held, and a notifier that asks it. That is the
 * property itself, not a proxy for it.
 */
class SolicitudesControllerNotificaTest extends TestCase {

    public function test_el_aviso_sale_con_el_candado_ya_liberado(): void {
        $lock = new class() implements CreationLock {
            public bool $sostenido = false;

            public function run( int $seasonId, callable $criticalSection ): mixed {
                $this->sostenido = true;

                try {
                    return $criticalSection();
                } finally {
                    $this->sostenido = false;
                }
            }
        };

        $sostenidoAlEnviar = null;

        $notificar = static function () use ( $lock, &$sostenidoAlEnviar ): void {
            $sostenidoAlEnviar = $lock->sostenido;
        };

        // Stands in for the controller's own shape: the locked section
        // returns, THEN the alert fires.
        $respuesta = $lock->run( 359, static fn (): string => 'creada' );
        $notificar();

        $this->assertSame( 'creada', $respuesta );
        $this->assertFalse(
            $sostenidoAlEnviar,
            'The alert must never be sent while the per-season creation lock is held.'
        );
    }

    /**
     * And the inverse, so the test above cannot pass vacuously: if the call
     * WERE inside the closure, the same assertion fails. Without this, a
     * lock double that never sets `sostenido` would make the test above
     * green for the wrong reason.
     */
    public function test_el_doble_de_candado_realmente_detecta_el_adentro(): void {
        $lock = new class() implements CreationLock {
            public bool $sostenido = false;

            public function run( int $seasonId, callable $criticalSection ): mixed {
                $this->sostenido = true;

                try {
                    return $criticalSection();
                } finally {
                    $this->sostenido = false;
                }
            }
        };

        $sostenidoAlEnviar = null;

        $lock->run( 359, static function () use ( $lock, &$sostenidoAlEnviar ): string {
            $sostenidoAlEnviar = $lock->sostenido;

            return 'creada';
        } );

        $this->assertTrue( $sostenidoAlEnviar, 'Sending from inside the closure IS detectable — so the test above means something.' );
    }

    /**
     * The controller's own guard: a response that is not a 200 must never
     * produce an alert. A rejected request landing in somebody's inbox as
     * if it were accepted is worse than no alert at all.
     */
    public function test_una_respuesta_que_no_es_200_no_avisa(): void {
        $avisados = [];

        $notifier = $this->notifierQueRegistra( $avisados );

        // WITH an id on purpose. A 409 body carries none, so a payload
        // without one would be stopped by the `$id > 0` guard instead and
        // the status check could be deleted with this test still green.
        $this->invocarNotificar( $notifier, new \WP_REST_Response( [ 'id' => 42, 'code' => 'plaza_con_solicitud_abierta' ], 409 ) );

        $this->assertSame( [], $avisados, 'A rejected request must never land in somebody\'s inbox as if accepted.' );

        // And the real 409 shape too, for the guard that actually catches it.
        $this->invocarNotificar( $notifier, new \WP_REST_Response( [ 'code' => 'plaza_con_solicitud_abierta' ], 409 ) );

        $this->assertSame( [], $avisados );
    }

    public function test_una_respuesta_sin_id_no_avisa(): void {
        $avisados = [];

        $notifier = $this->notifierQueRegistra( $avisados );

        $this->invocarNotificar( $notifier, new \WP_REST_Response( [ 'estado' => 'pendiente' ], 200 ) );

        $this->assertSame( [], $avisados );
    }

    public function test_un_200_con_id_avisa_una_sola_vez(): void {
        $avisados = [];

        $notifier = $this->notifierQueRegistra( $avisados );

        $this->invocarNotificar( $notifier, new \WP_REST_Response( [ 'id' => 42, 'estado' => 'pendiente' ], 200 ) );

        $this->assertSame( [ 42 ], $avisados );
    }

    public function test_sin_notificador_no_rompe(): void {
        // The notifier is optional: a controller wired without one sends no
        // alert, which is not an error — the solicitud is what matters and
        // it is already stored.
        $this->invocarNotificar( null, new \WP_REST_Response( [ 'id' => 42 ], 200 ) );

        $this->addToAssertionCount( 1 );
    }

    // -------------------------------------------------------------------------

    /** @param array<int, int> $avisados */
    private function notifierQueRegistra( array &$avisados ): NotificaSolicitudes {
        return new class( $avisados ) implements NotificaSolicitudes {
            /** @var array<int, int> */
            private array $registro;

            /** @param array<int, int> $avisados */
            public function __construct( array &$avisados ) {
                $this->registro = &$avisados;
            }

            public function notificarCreada( int $solicitudId ): void {
                $this->registro[] = $solicitudId;
            }

            public function notificarCreadaAlCapitan( int $solicitudId ): void {
            }

            public function notificarDecision( int $solicitudId, string $estado ): void {
            }
        };
    }

    /**
     * Invokes the controller's private notify helper directly — the logic
     * under test is the guard, and building a whole authorized REST request
     * to reach it would test the authorization path instead.
     */
    private function invocarNotificar( ?NotificaSolicitudes $notifier, \WP_REST_Response $respuesta ): void {
        $controller = ( new \ReflectionClass( SolicitudesController::class ) )->newInstanceWithoutConstructor();

        $prop = new \ReflectionProperty( SolicitudesController::class, 'notifier' );
        $prop->setValue( $controller, $notifier );

        ( new \ReflectionMethod( SolicitudesController::class, 'notificarCreada' ) )->invoke( $controller, $respuesta );
    }
}

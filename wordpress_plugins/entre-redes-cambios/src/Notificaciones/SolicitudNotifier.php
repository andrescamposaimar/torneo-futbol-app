<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Notificaciones;

use EntreRedes\Cambios\Admin\BandejaPage;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Dictamen\DictamenSnapshot;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Solicitudes\SolicitudRepository;

/**
 * Emails the process owner when a captain files a request (0.1.25).
 *
 * *** WHY THIS EXISTS ***
 * The NinjaForms process this plugin replaces emailed the comisión on every
 * submission. Ours did not: the request appeared in the tray and nothing
 * announced it, so the whole thing depended on somebody remembering to open
 * a page. That is the same shape as the Friday lote nobody triggers — a step
 * that only happens if a human remembers — and it is the one the old,
 * worse process actually had covered.
 *
 * *** THREE RULES THIS CLASS WILL NOT BREAK ***
 *
 * 1. A MAIL FAILURE MUST NEVER FAIL THE REQUEST. By the time this runs the
 *    solicitud is stored and the captain's action succeeded. Turning an SMTP
 *    timeout into an error would tell them their request did not go through,
 *    which is false, and would invite them to file it again — straight into
 *    the duplicate refusal. Every path here is wrapped and swallowed into a
 *    logged event.
 *
 * 2. IT RUNS OUTSIDE THE CREATION LOCK. `wp_mail()` opens a network
 *    connection and can block for seconds. Inside
 *    `Solicitudes\Lock\CreationLock` that would hold a per-season `GET_LOCK`
 *    for the length of an SMTP timeout, and every other captain in the
 *    league would get 503 `creacion_ocupada` while it hung.
 *    `Rest\SolicitudesController::crear()` therefore calls this AFTER
 *    `run()` returns; `SolicitudNotifierTest` pins that as a property, not
 *    as a comment, because it is invisible at the call site.
 *
 * 3. IT SENDS ONE EMAIL PER SOLICITUD. A grouped `reasignacion_arquero` is
 *    ONE request with two movements — see
 *    `Solicitudes\SolicitudRepository`'s docblock — so it gets one email
 *    describing both, never two that look like two separate requests.
 *
 * *** WHY THE SEND FUNCTION IS INJECTED ***
 * A test against the real `wp_mail()` would prove nothing: the test shim
 * has no mailer, so the call would quietly do nothing and every assertion
 * would pass for the wrong reason. The callable lets a test capture exactly
 * what would have been sent.
 */
final class SolicitudNotifier implements NotificaSolicitudes {

    private Settings $settings;
    private SolicitudRepository $solicitudRepository;
    private PlazaRepository $plazaRepository;
    private EventLog $eventLog;

    /** @var callable(string, string, string): bool */
    private $mailFn;

    /**
     * @param callable(string $para, string $asunto, string $cuerpo): bool|null $mailFn
     *        Defaults to `wp_mail()` with a plain-text body — plain text on
     *        purpose: this is an operational alert read on a phone, and an
     *        HTML mail buys nothing while adding a way to render wrong.
     */
    public function __construct(
        Settings $settings,
        SolicitudRepository $solicitudRepository,
        PlazaRepository $plazaRepository,
        EventLog $eventLog,
        ?callable $mailFn = null
    ) {
        $this->settings            = $settings;
        $this->solicitudRepository = $solicitudRepository;
        $this->plazaRepository     = $plazaRepository;
        $this->eventLog            = $eventLog;
        $this->mailFn              = $mailFn ?? static function ( string $para, string $asunto, string $cuerpo ): bool {
            return (bool) wp_mail( $para, $asunto, $cuerpo );
        };
    }

    /**
     * Never throws. See rule 1 in the class docblock.
     */
    public function notificarCreada( int $solicitudId ): void {
        try {
            if ( ! $this->settings->notificacionNuevaSolicitudActiva() ) {
                return;
            }

            $destino = $this->settings->notificacionNuevaSolicitudDestino();

            if ( '' === $destino ) {
                // `Admin\ConfiguracionPage` refuses to turn the switch on
                // without an address, so this is belt and braces — but a row
                // edited by hand must not produce a mail to nowhere and no
                // record of why none arrived.
                $this->eventLog->record( 'notificacion.sin_destino_fallida', [
                    'solicitud_id' => $solicitudId,
                ] );

                return;
            }

            $row = $this->solicitudRepository->findSolicitud( $solicitudId );

            if ( null === $row ) {
                $this->eventLog->record( 'notificacion.solicitud_inexistente_fallida', [
                    'solicitud_id' => $solicitudId,
                ] );

                return;
            }

            $enviado = ( $this->mailFn )( $destino, $this->asunto( $row ), $this->cuerpo( $row ) );

            if ( ! $enviado ) {
                $this->eventLog->record( 'notificacion.envio_fallido', [
                    'solicitud_id' => $solicitudId,
                    'destino'      => $destino,
                ] );

                return;
            }

            $this->eventLog->record( 'notificacion.enviada', [
                'solicitud_id' => $solicitudId,
                'destino'      => $destino,
            ] );
        } catch ( \Throwable $e ) {
            // The solicitud is already stored. Whatever went wrong here, the
            // captain's request succeeded and must be reported as such.
            $this->eventLog->record( 'notificacion.fallida', [
                'solicitud_id' => $solicitudId,
                'excepcion'    => get_class( $e ),
                'mensaje'      => $e->getMessage(),
            ] );
        }
    }

    /** @param array<string, mixed> $row */
    private function asunto( array $row ): string {
        return sprintf(
            /* translators: 1: request id, 2: team name */
            __( 'Cambios: nueva solicitud #%1$d — %2$s', 'entre-redes-cambios' ),
            (int) $row['id'],
            $this->nombrePost( (int) $row['team_id'], __( 'Equipo', 'entre-redes-cambios' ) )
        );
    }

    /**
     * Plain text, and deliberately complete enough to TRIAGE from: who, what
     * movement, for which fecha, and what the dictamen already concluded. An
     * alert that only says "there is something" makes the reader open the
     * tray to find out whether it was worth opening.
     *
     * @param array<string, mixed> $row
     */
    private function cuerpo( array $row ): string {
        $tipo     = (string) $row['tipo'];
        $snapshot = DictamenSnapshot::fromJson( (string) $row['dictamen_original'] );

        $lineas = [
            sprintf( __( 'Equipo: %s', 'entre-redes-cambios' ), $this->nombrePost( (int) $row['team_id'], __( 'Equipo', 'entre-redes-cambios' ) ) ),
            sprintf( __( 'Tipo: %s', 'entre-redes-cambios' ), $this->etiquetaTipo( $tipo ) ),
            sprintf( __( 'Fecha: %d', 'entre-redes-cambios' ), (int) $row['fecha_id'] ),
            '',
        ];

        $sale = null !== $row['saliente_player_id'] ? (int) $row['saliente_player_id'] : null;

        // Both movements of a grouped request, in ONE email — see rule 3.
        if ( SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO === $tipo ) {
            $lineas[] = sprintf(
                __( 'Arco — sale %1$s, entra %2$s', 'entre-redes-cambios' ),
                $this->nombreJugador( $sale ),
                $this->nombreJugador( null !== $row['entrante_player_id'] ? (int) $row['entrante_player_id'] : null )
            );
            $lineas[] = sprintf(
                __( 'Campo — entra %s', 'entre-redes-cambios' ),
                $this->nombreJugador( null !== $row['entrante_campo_player_id'] ? (int) $row['entrante_campo_player_id'] : null )
            );
        } elseif ( SolicitudDeCambio::TIPO_REGRESO === $tipo ) {
            $plaza   = $this->plazaRepository->findPlaza( (int) $row['plaza_id'] );
            $titular = null !== $plaza ? (int) $plaza['titular_player_id'] : null;

            $lineas[] = sprintf(
                __( 'Sale %1$s, vuelve el titular %2$s', 'entre-redes-cambios' ),
                $this->nombreJugador( $sale ),
                $this->nombreJugador( $titular )
            );
        } else {
            $lineas[] = sprintf(
                __( 'Sale %1$s, entra %2$s', 'entre-redes-cambios' ),
                $this->nombreJugador( $sale ),
                $this->nombreJugador( null !== $row['entrante_player_id'] ? (int) $row['entrante_player_id'] : null )
            );
        }

        $lineas[] = '';
        $lineas[] = $snapshot->procede()
            ? __( 'Acción sugerida: aprobar.', 'entre-redes-cambios' )
            : __( 'Acción sugerida: rechazar.', 'entre-redes-cambios' );

        foreach ( $snapshot->motivos() as $motivo ) {
            $mensaje = (string) ( $motivo['mensaje'] ?? '' );

            if ( '' !== $mensaje ) {
                $lineas[] = '  - ' . $mensaje;
            }
        }

        $lineas[] = '';
        $lineas[] = __( 'Resolvela en la bandeja:', 'entre-redes-cambios' );
        $lineas[] = admin_url( 'admin.php?page=' . BandejaPage::SLUG );

        return implode( "\n", $lineas );
    }

    /**
     * Unlike the admin pages, an unrecognised tipo does NOT throw here: the
     * solicitud is already stored and an alert naming it imprecisely is far
     * better than no alert at all. Same reasoning as `Admin\HistoricoPage`'s
     * — nobody decides anything from this string, they open the tray.
     */
    private function etiquetaTipo( string $tipo ): string {
        return match ( $tipo ) {
            SolicitudDeCambio::TIPO_SUSTITUCION          => __( 'Cambio', 'entre-redes-cambios' ),
            SolicitudDeCambio::TIPO_REGRESO              => __( 'Regreso', 'entre-redes-cambios' ),
            SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO => __( 'Reasignación de arquero', 'entre-redes-cambios' ),
            default                                      => $tipo,
        };
    }

    private function nombreJugador( ?int $playerId ): string {
        return null === $playerId
            ? __( 'sin registrar', 'entre-redes-cambios' )
            : $this->nombrePost( $playerId, __( 'Jugador', 'entre-redes-cambios' ) );
    }

    private function nombrePost( int $postId, string $fallbackLabel ): string {
        $titulo = trim( (string) get_the_title( $postId ) );

        return '' !== $titulo ? $titulo : $fallbackLabel . ' #' . $postId;
    }
}

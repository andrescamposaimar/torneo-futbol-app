<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Notificaciones;

use EntreRedes\Cambios\Admin\BandejaPage;
use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\JugadorMetricasReader;
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
    private FechaRepository $fechaRepository;
    private JugadorMetricasReader $metricasReader;
    private EventLog $eventLog;

    /** @var callable(string, string, string): bool */
    private $mailFn;

    /**
     * @param callable(string $para, string $asunto, string $cuerpo, string[] $headers): bool|null $mailFn
     *        Defaults to `wp_mail()`. The headers are part of the contract
     *        because the body is HTML: without `Content-Type: text/html` the
     *        reader gets the markup as literal text, which is worse than the
     *        plain text this replaced.
     */
    public function __construct(
        Settings $settings,
        SolicitudRepository $solicitudRepository,
        PlazaRepository $plazaRepository,
        FechaRepository $fechaRepository,
        JugadorMetricasReader $metricasReader,
        EventLog $eventLog,
        ?callable $mailFn = null
    ) {
        $this->settings            = $settings;
        $this->solicitudRepository = $solicitudRepository;
        $this->plazaRepository     = $plazaRepository;
        $this->fechaRepository     = $fechaRepository;
        $this->metricasReader      = $metricasReader;
        $this->eventLog            = $eventLog;
        $this->mailFn              = $mailFn ?? static function ( string $para, string $asunto, string $cuerpo, array $headers ): bool {
            return (bool) wp_mail( $para, $asunto, $cuerpo, $headers );
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

            $enviado = ( $this->mailFn )(
                $destino,
                $this->asunto( $row ),
                $this->cuerpo( $row ),
                [ 'Content-Type: text/html; charset=UTF-8' ]
            );

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

    /**
     * Two shapes, because a regreso is not a request for a new player — it
     * is a captain confirming that one he already has is coming back:
     *
     *   Cambios: Nueva solicitud de SUIZA por Campos, Andres
     *   Cambios: Confirmación de Regreso de Campos, Andres
     *
     * *** THE PLAYER NAMED IS THE ONE LEAVING, NOT THE ONE ARRIVING ***
     * The process owner reads these by WHO IS BEING TAKEN OUT: that is the
     * plaza he is being asked about, and the name he already has in his
     * head. For a `reasignacion_arquero` the same rule names the goalkeeper
     * stepping out of the arco, which is the move's whole subject — not the
     * field titular who replaces him, and not the external player joining
     * underneath.
     *
     * `saliente_player_id` is that player for all three tipos: it is the
     * plaza's vigent occupant frozen AT REQUEST TIME (see
     * `Migrations\InitialSchema`'s docblock on the column), which is the
     * goalkeeper for the arco leg and the suplente for a regreso.
     *
     * A regreso names the TITULAR instead, since he is what is being
     * confirmed. Team and player fall off rather than printing a
     * placeholder when nobody is resolvable — a shorter subject beats
     * "por sin registrar".
     *
     * @param array<string, mixed> $row
     */
    private function asunto( array $row ): string {
        $tipo = (string) $row['tipo'];

        if ( SolicitudDeCambio::TIPO_REGRESO === $tipo ) {
            $titular = $this->titularDeLaPlaza( $row );

            return null === $titular
                ? __( 'Cambios: Confirmación de Regreso', 'entre-redes-cambios' )
                : sprintf(
                    /* translators: %s: the titular coming back */
                    __( 'Cambios: Confirmación de Regreso de %s', 'entre-redes-cambios' ),
                    $titular
                );
        }

        $equipo = $this->nombrePost( (int) $row['team_id'], __( 'Equipo', 'entre-redes-cambios' ) );
        $sale   = null !== $row['saliente_player_id'] ? (int) $row['saliente_player_id'] : null;

        if ( null === $sale ) {
            return sprintf(
                /* translators: %s: team name */
                __( 'Cambios: Nueva solicitud de %s', 'entre-redes-cambios' ),
                $equipo
            );
        }

        return sprintf(
            /* translators: 1: team name, 2: the player being taken out */
            __( 'Cambios: Nueva solicitud de %1$s por %2$s', 'entre-redes-cambios' ),
            $equipo,
            $this->nombrePost( $sale, __( 'Jugador', 'entre-redes-cambios' ) )
        );
    }

    /**
     * Who filed the request. `solicitada_por` holds the captain's PLAYER id
     * — `$claims['player_id']` from the token, see
     * `Rest\SolicitudesController::crear()` — not a WP user id, so it
     * resolves through the same `get_the_title()` path as every other name
     * on this mail. Resolving it as a WP user would have printed nothing.
     *
     * Null when the column holds 0, which only happens if the token carried
     * no player id: the line is then dropped rather than printing
     * "Jugador #0". The captain may well also be one of the players named
     * below — that is ordinary and still worth stating, because the question
     * "who asked for this" is not answered anywhere else on the mail.
     *
     * @param array<string, mixed> $row
     */
    private function capitan( array $row ): ?string {
        $playerId = (int) $row['solicitada_por'];

        return $playerId > 0
            ? $this->nombrePost( $playerId, __( 'Jugador', 'entre-redes-cambios' ) )
            : null;
    }

    /** @param array<string, mixed> $row */
    private function titularDeLaPlaza( array $row ): ?string {
        $plaza = $this->plazaRepository->findPlaza( (int) $row['plaza_id'] );

        return null === $plaza
            ? null
            : $this->nombrePost( (int) $plaza['titular_player_id'], __( 'Jugador', 'entre-redes-cambios' ) );
    }

    /**
     * The alert body, as HTML.
     *
     * *** NO "ACCIÓN SUGERIDA" HERE — THE PROCESS OWNER'S OWN CALL ***
     * 0.1.25 opened with the dictamen's verdict. It was removed on request:
     * an alert that arrives already recommending an answer frames the
     * decision before the reader has looked at it, and the decision is his.
     * The tray still shows it, right next to the buttons that act on it —
     * which is where a recommendation belongs, not in a notification read on
     * a phone. The motivos are gone with it: without the verdict they are a
     * list of objections with nothing to object to.
     *
     * *** INLINE STYLES, NO <style> BLOCK ***
     * Gmail and most webmail strip `<style>` from the document. Anything
     * that must survive has to ride on the element.
     *
     * *** EVERY INTERPOLATED VALUE IS ESCAPED ***
     * Team and player names come from post titles. They are not hostile
     * input, but an apostrophe or an ampersand in a surname is ordinary and
     * would break the markup.
     *
     * @param array<string, mixed> $row
     */
    private function cuerpo( array $row ): string {
        $tipo = (string) $row['tipo'];

        $filas = $this->movimientos( $row );

        // ONE batched read for every player on the mail — the same discipline
        // Admin\BandejaPage follows, and worth keeping even for two names.
        $puntajes = $this->puntajesDe( $filas );

        $html  = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;color:#1d2327;line-height:1.5;">';
        $html .= sprintf(
            '<p style="margin:0 0 4px;font-size:18px;font-weight:600;">%s</p>',
            esc_html( $this->nombrePost( (int) $row['team_id'], __( 'Equipo', 'entre-redes-cambios' ) ) )
        );
        $html .= sprintf(
            '<p style="margin:0 0 2px;color:#646970;">%s</p>',
            esc_html( $this->contexto( $row, $tipo ) )
        );

        $capitan = $this->capitan( $row );

        if ( null !== $capitan ) {
            $html .= sprintf(
                '<p style="margin:0 0 16px;color:#646970;">%s</p>',
                esc_html( sprintf(
                    /* translators: %s: the captain who filed the request */
                    __( 'Pedido por %s', 'entre-redes-cambios' ),
                    $capitan
                ) )
            );
        }

        $explicacion = $this->explicacion( $row, $tipo );

        if ( '' !== $explicacion ) {
            $html .= sprintf( '<p style="margin:0 0 16px;">%s</p>', esc_html( $explicacion ) );
        }

        $html .= '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 16px;">';

        foreach ( $filas as $fila ) {
            $html .= sprintf(
                '<tr>'
                . '<td style="padding:6px 12px 6px 0;color:#646970;white-space:nowrap;">%s</td>'
                . '<td style="padding:6px 12px 6px 0;font-weight:600;">%s</td>'
                . '<td style="padding:6px 0;color:#646970;white-space:nowrap;">%s</td>'
                . '</tr>',
                esc_html( $fila['etiqueta'] ),
                esc_html( $this->nombreJugador( $fila['player_id'] ) ),
                esc_html( $this->puntajeLegible( $fila['player_id'], $puntajes ) )
            );
        }

        $html .= '</table>';

        $html .= sprintf(
            '<p style="margin:0;"><a href="%1$s" style="display:inline-block;background:#2271b1;color:#ffffff;'
            . 'text-decoration:none;padding:10px 18px;border-radius:4px;font-weight:600;">%2$s</a></p>',
            esc_url( admin_url( 'admin.php?page=' . BandejaPage::SLUG ) ),
            esc_html__( 'Gestionar', 'entre-redes-cambios' )
        );

        $html .= '</div>';

        return $html;
    }

    /**
     * One sentence saying what is being asked, for the tipo where the table
     * alone does not say it.
     *
     * A `sustitucion` and a `reasignacion_arquero` are self-evident from
     * their rows: somebody leaves, somebody enters. A REGRESO is not — the
     * table shows a titular and a suplente with no hint of which direction
     * the request runs, and "regreso" in the heading is a word, not an
     * explanation. So that one says it plainly, and then the row below
     * names the suplente who gives the plaza back.
     *
     * @param array<string, mixed> $row
     */
    private function explicacion( array $row, string $tipo ): string {
        if ( SolicitudDeCambio::TIPO_REGRESO !== $tipo ) {
            return '';
        }

        $titular = $this->titularDeLaPlaza( $row );

        return null === $titular
            ? __( 'El capitán pide el regreso del titular de esta plaza.', 'entre-redes-cambios' )
            : sprintf(
                /* translators: %s: the titular coming back */
                __( 'El capitán pide el regreso de %s a su plaza.', 'entre-redes-cambios' ),
                $titular
            );
    }

    /**
     * "Apertura · fecha 6 · Cambio" — the torneo a human reads, not the
     * global `fecha_id`, which means nothing to anybody outside the
     * database. `numero_en_torneo` restarts at 1 on every torneo (see
     * `Migrations\InitialSchema`), so it only makes sense WITH the label and
     * is never shown alone.
     *
     * Degrades to the plain fecha id when the fecha row cannot be read: an
     * alert with a slightly poorer heading is better than no alert.
     *
     * @param array<string, mixed> $row
     */
    private function contexto( array $row, string $tipo ): string {
        $partes = [];
        $fecha  = $this->fechaRepository->findById( (int) $row['fecha_id'] );

        $label  = is_array( $fecha ) ? trim( (string) ( $fecha['torneo_label'] ?? '' ) ) : '';
        $numero = is_array( $fecha ) ? (int) ( $fecha['numero_en_torneo'] ?? 0 ) : 0;

        if ( '' !== $label ) {
            $partes[] = $numero > 0
                ? sprintf( __( '%1$s · fecha %2$d', 'entre-redes-cambios' ), $label, $numero )
                : $label;
        } else {
            $partes[] = sprintf( __( 'Fecha %d', 'entre-redes-cambios' ), (int) $row['fecha_id'] );
        }

        $partes[] = $this->etiquetaTipo( $tipo );

        return implode( ' · ', $partes );
    }

    /**
     * Every player this request moves, labelled, in reading order. One list
     * for every tipo — a grouped reasignación simply contributes four rows
     * instead of two, which is how it stays ONE mail describing both
     * movements.
     *
     * @param array<string, mixed> $row
     * @return array<int, array{etiqueta: string, player_id: int|null}>
     */
    private function movimientos( array $row ): array {
        $tipo  = (string) $row['tipo'];
        $sale  = null !== $row['saliente_player_id'] ? (int) $row['saliente_player_id'] : null;
        $entra = null !== $row['entrante_player_id'] ? (int) $row['entrante_player_id'] : null;

        if ( SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO === $tipo ) {
            return [
                [ 'etiqueta' => __( 'Arco — sale', 'entre-redes-cambios' ), 'player_id' => $sale ],
                [ 'etiqueta' => __( 'Arco — entra', 'entre-redes-cambios' ), 'player_id' => $entra ],
                [
                    'etiqueta'  => __( 'Campo — entra', 'entre-redes-cambios' ),
                    'player_id' => null !== $row['entrante_campo_player_id'] ? (int) $row['entrante_campo_player_id'] : null,
                ],
            ];
        }

        if ( SolicitudDeCambio::TIPO_REGRESO === $tipo ) {
            $plaza = $this->plazaRepository->findPlaza( (int) $row['plaza_id'] );

            // `saliente_player_id` on a regreso is the SUPLENTE holding the
            // plaza (see Migrations\InitialSchema on that column), so he is
            // exactly who stops occupying it — the one the process owner
            // needs to know frees up. There is only ever one: a chain's
            // earlier occupants were already replaced when they left.
            return [
                [
                    'etiqueta'  => __( 'Vuelve', 'entre-redes-cambios' ),
                    'player_id' => null !== $plaza ? (int) $plaza['titular_player_id'] : null,
                ],
                [ 'etiqueta' => __( 'Se libera', 'entre-redes-cambios' ), 'player_id' => $sale ],
            ];
        }

        return [
            [ 'etiqueta' => __( 'Sale', 'entre-redes-cambios' ), 'player_id' => $sale ],
            [ 'etiqueta' => __( 'Entra', 'entre-redes-cambios' ), 'player_id' => $entra ],
        ];
    }

    /**
     * @param array<int, array{etiqueta: string, player_id: int|null}> $filas
     * @return array<int, \EntreRedes\Cambios\Plazas\JugadorMetricas>
     */
    private function puntajesDe( array $filas ): array {
        $ids = [];

        foreach ( $filas as $fila ) {
            if ( null !== $fila['player_id'] ) {
                $ids[] = $fila['player_id'];
            }
        }

        if ( empty( $ids ) ) {
            return [];
        }

        return $this->metricasReader->resolveMuchos( array_values( array_unique( $ids ) ) );
    }

    /**
     * "4.5 ptos", or an explicit "sin puntaje".
     *
     * A player with NO puntaje says so rather than printing nothing: a blank
     * cell reads as a rendering glitch, while "sin puntaje" is a fact the
     * process owner can act on — it is exactly the condition that keeps
     * somebody out of the candidate list in the first place.
     *
     * @param array<int, \EntreRedes\Cambios\Plazas\JugadorMetricas> $puntajes
     */
    private function puntajeLegible( ?int $playerId, array $puntajes ): string {
        if ( null === $playerId || ! isset( $puntajes[ $playerId ] ) ) {
            return __( 'sin puntaje', 'entre-redes-cambios' );
        }

        $puntaje = $puntajes[ $playerId ]->puntaje();

        if ( null === $puntaje ) {
            return __( 'sin puntaje', 'entre-redes-cambios' );
        }

        return sprintf(
            /* translators: %s: the score, e.g. 4.5 */
            __( '%s ptos', 'entre-redes-cambios' ),
            rtrim( rtrim( number_format( $puntaje->toDecimal(), 1, '.', '' ), '0' ), '.' ) ?: '0'
        );
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

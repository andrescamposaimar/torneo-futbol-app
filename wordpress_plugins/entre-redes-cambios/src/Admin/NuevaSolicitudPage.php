<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Admin;

use EntreRedes\Cambios\Calendario\BoundedFechaCounter;
use EntreRedes\Cambios\Calendario\FechaAbierta;
use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Capitania\CapitanRepository;
use EntreRedes\Cambios\Dictamen\BloqueoReemplazoPolicy;
use EntreRedes\Cambios\Dictamen\Reglas\EntranteSinSolicitudAbierta;
use EntreRedes\Cambios\Dictamen\Reglas\PlazaSinSolicitudAbierta;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\CandidatoEstado;
use EntreRedes\Cambios\Plazas\CandidatosResolver;
use EntreRedes\Cambios\Plazas\JugadorMetricas;
use EntreRedes\Cambios\Plazas\JugadorMetricasReader;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Solicitudes\CrearSolicitud;
use EntreRedes\Cambios\Solicitudes\Exception\CreationLockUnavailableException;
use EntreRedes\Cambios\Solicitudes\Exception\SolicitudAbiertaException;
use EntreRedes\Cambios\Solicitudes\Exception\SolicitudInvalidaException;
use EntreRedes\Cambios\Solicitudes\Exception\TitularSinPlazaDeCampoException;

/**
 * "Nueva solicitud" — the commission loads a change request ON BEHALF of a
 * captain who phoned in and cannot use the app.
 *
 * *** THE SAME PATH AS THE CAPTAIN'S, NOT A PARALLEL ONE ***
 * Creation goes through `Solicitudes\CrearSolicitud`, the very use case
 * `Rest\SolicitudesController::crear()` calls, so every rule is identical:
 *   - only the two integrity motivos REFUSE (`plaza_con_solicitud_abierta`,
 *     `entrante_con_solicitud_abierta`) — shown here as an error notice;
 *   - every other motivo stays advisory: the request is stored `pendiente`
 *     with its dictamen, and the notice says what the dictamen concluded.
 *   There is deliberately NO "force" switch. Overriding a negative dictamen
 *   is the commission's call and it already has its place: approving the
 *   request afterwards in the Bandeja.
 *
 * *** WHO THE REQUEST BELONGS TO ***
 * `solicitada_por` is the team's CURRENT captain's player id
 * (`CapitanRepository::findCapitanVigente()`): the request is the captain's —
 * that is who gets the receipt mail and whom the Bandeja names — and the WP
 * user who typed it in is recorded separately in `creada_por_wp_user`. A team
 * with no current captain is REFUSED, never filed under nobody: there would
 * be no one to receive the confirmation mail, and that mail is the only proof
 * the request was loaded.
 *
 * *** WHEN IT IS EVALUATED ***
 * At the instant of the load (`$clockFn`, the same `$ahora` semantics as the
 * REST route), against the season's open fecha. A call that comes in after
 * the window closed is therefore stored with `fuera_de_plazo` like any other
 * late request — advisory, as everywhere else.
 *
 * *** A STEPWISE, GET-DRIVEN FORM ***
 * No JavaScript: team -> tipo -> plaza (or field titular) -> entrante, each
 * step a plain GET carrying what was chosen so far, and ONLY the last step is
 * a POST (nonce-checked, PRG). The candidates are the very ones the captain
 * would see (`Plazas\CandidatosResolver`, wired like
 * `Rest\PlazasController::listarCandidatos()`), except that the non-viable
 * ones are SHOWN, marked and disabled: the person on the phone will ask why
 * somebody is not offered.
 *
 * *** WHERE THE TESTABLE CORE LIVES ***
 * `handleCrear()` ends in `wp_safe_redirect()` + `exit` (PRG) and cannot be
 * called from PHPUnit; everything it decides lives in the public
 * `ejecutarCrear()`, which returns a plain result and never redirects.
 */
class NuevaSolicitudPage {

    public const SLUG = 'cambios-nueva-solicitud';

    private const NONCE_ACTION = 'cambios_nueva_solicitud';
    private const NONCE_FIELD  = 'cambios_nueva_solicitud_nonce';
    private const ACCION       = 'crear_solicitud';

    private const PER_PAGE = 50;

    private const TIPOS = [
        SolicitudDeCambio::TIPO_SUSTITUCION,
        SolicitudDeCambio::TIPO_REGRESO,
        SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO,
    ];

    private ProcessOwnerAuthorizer $authorizer;
    private CrearSolicitud $crearSolicitud;
    private CapitanRepository $capitanRepository;
    private PlazaRepository $plazaRepository;
    private FechaRepository $fechaRepository;
    private Settings $settings;
    private CandidatosResolver $candidatosResolver;
    private BloqueoReemplazoPolicy $politica;
    private JugadorMetricasReader $metricasReader;
    private EventLog $eventLog;

    /** @var callable(): int */
    private $clockFn;

    /**
     * @param callable(): int|null $clockFn Returns the current instant as a
     *        Unix epoch — same injectable-clock discipline as
     *        `BandejaPage` and the REST controllers. Defaults to `time()`.
     */
    public function __construct(
        ProcessOwnerAuthorizer $authorizer,
        CrearSolicitud $crearSolicitud,
        CapitanRepository $capitanRepository,
        PlazaRepository $plazaRepository,
        FechaRepository $fechaRepository,
        Settings $settings,
        CandidatosResolver $candidatosResolver,
        BloqueoReemplazoPolicy $politica,
        JugadorMetricasReader $metricasReader,
        EventLog $eventLog,
        ?callable $clockFn = null
    ) {
        $this->authorizer         = $authorizer;
        $this->crearSolicitud     = $crearSolicitud;
        $this->capitanRepository  = $capitanRepository;
        $this->plazaRepository    = $plazaRepository;
        $this->fechaRepository    = $fechaRepository;
        $this->settings           = $settings;
        $this->candidatosResolver = $candidatosResolver;
        $this->politica           = $politica;
        $this->metricasReader     = $metricasReader;
        $this->eventLog           = $eventLog;
        $this->clockFn            = $clockFn ?? static fn (): int => time();
    }

    // -------------------------------------------------------------------------
    // POST dispatcher — registered on admin_init
    // -------------------------------------------------------------------------

    /**
     * Registered on admin_init. ONE authorization check gates the action,
     * before anything else is read — same shape as `BandejaPage::handlePost()`.
     */
    public function handlePost(): void {
        $action = (string) ( $_POST['cambios_action'] ?? '' );

        if ( self::ACCION !== $action ) {
            return;
        }

        if ( ! $this->authorizer->autorizado() ) {
            $this->eventLog->record( 'admin.autorizacion_denegada', [
                'pantalla' => self::SLUG,
                'accion'   => $action,
                'wp_user'  => get_current_user_id(),
            ] );

            wp_die( esc_html__( 'No tenés permiso para realizar esta acción.', 'entre-redes-cambios' ) );
        }

        $this->handleCrear();
    }

    private function handleCrear(): void {
        $this->verificarNonceOMorir( self::NONCE_ACTION, self::NONCE_FIELD );

        $resultado = $this->ejecutarDesdePost( $_POST, get_current_user_id() );

        $this->guardarNotice( $resultado['ok'] ? 'success' : 'error', $resultado['mensaje'] );

        // Success starts over (the next phone call is a different team); a
        // refusal returns to the same team so the operator can correct it.
        $teamId  = absint( $_POST['team_id'] ?? 0 );
        $destino = admin_url( 'admin.php?page=' . self::SLUG );
        if ( ! $resultado['ok'] && $teamId > 0 ) {
            $destino = admin_url( 'admin.php?page=' . self::SLUG . '&team_id=' . $teamId );
        }

        wp_safe_redirect( $destino );
        exit;
    }

    /**
     * Maps the form's fields onto `ejecutarCrear()`'s arguments. Public and
     * separate from `handleCrear()` (which exits) precisely so the mapping —
     * the one place a field swap could hide — is testable.
     *
     * The form's `entrante_player_id` is the entrante for a `sustitucion`,
     * but for `reasignacion_arquero` it is the OUTSIDE player filling the
     * field plaza, while the field titular moving into goal travels as
     * `titular_player_id`.
     *
     * @param array<string, mixed> $post
     * @return array{ok: bool, mensaje: string, solicitud_id: int|null}
     */
    public function ejecutarDesdePost( array $post, int $wpUserId ): array {
        $teamId   = absint( $post['team_id'] ?? 0 );
        $tipo     = sanitize_text_field( (string) ( $post['tipo'] ?? '' ) );
        $plazaId  = absint( $post['plaza_id'] ?? 0 );
        $titular  = absint( $post['titular_player_id'] ?? 0 );
        $entrante = absint( $post['entrante_player_id'] ?? 0 );

        $esArquero = SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO === $tipo;

        return $this->ejecutarCrear(
            $teamId,
            $tipo,
            $plazaId,
            $esArquero ? $titular : $entrante,
            $esArquero ? $entrante : 0,
            $wpUserId
        );
    }

    /**
     * The whole decision, with no redirect and no `exit`, so a test can call
     * it directly.
     *
     * @param int $entrantePlayerId For `sustitucion` the entrante; for
     *        `reasignacion_arquero` the field titular moving into goal; 0 for
     *        `regreso`.
     * @param int $entranteCampoPlayerId `reasignacion_arquero` only: the
     *        outside player filling the field plaza the titular vacates.
     * @return array{ok: bool, mensaje: string, solicitud_id: int|null}
     */
    public function ejecutarCrear(
        int $teamId,
        string $tipo,
        int $plazaId,
        int $entrantePlayerId,
        int $entranteCampoPlayerId,
        int $wpUserId
    ): array {
        $seasonId = $this->settings->seasonId();

        if ( $teamId <= 0 || ! in_array( $tipo, self::TIPOS, true ) ) {
            return $this->fallo( 'Faltan datos: hay que elegir un equipo y el tipo de solicitud.' );
        }

        $capitan = $this->capitanRepository->findCapitanVigente( $seasonId, $teamId );

        if ( null === $capitan ) {
            return $this->fallo( sprintf(
                'El equipo %s no tiene un capitán vigente esta temporada, así que no se creó la solicitud. Designá un capitán primero: sin él no habría nadie que reciba la confirmación por mail.',
                $this->nombrePost( $teamId, 'Equipo' )
            ) );
        }

        $fecha = FechaAbierta::de( $this->fechaRepository, $seasonId );

        if ( null === $fecha ) {
            return $this->fallo( 'No hay una fecha abierta en la temporada, así que no se creó la solicitud.' );
        }

        if ( SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO === $tipo ) {
            // The goal plaza is never a form field: it is the team's `es_arco`
            // plaza, so a stale or tampered form cannot name another one.
            $plazaArcoId = $this->plazaDelArco( $seasonId, $teamId );

            if ( null === $plazaArcoId ) {
                // A data problem, not an input one: every team must have
                // exactly one goal plaza (see MigrationRunner::checkEsArcoInvariant()).
                // Saying "la solicitud no es válida" would send the operator
                // to look for a mistake of their own.
                return $this->fallo( sprintf(
                    'El equipo %s no tiene cargada una plaza de arquero esta temporada, así que no se puede pedir una reasignación al arco. Es un problema de datos de los planteles: avisá a quien los administra.',
                    $this->nombrePost( $teamId, 'Equipo' )
                ) );
            }

            $plazaId = $plazaArcoId;
        }

        $capitanPlayerId = (int) $capitan['player_id'];

        try {
            $creada = $this->crearSolicitud->ejecutar(
                $seasonId,
                $teamId,
                $plazaId,
                (int) $fecha['id'],
                $tipo,
                $entrantePlayerId > 0 ? $entrantePlayerId : null,
                $entranteCampoPlayerId > 0 ? $entranteCampoPlayerId : null,
                $capitanPlayerId,
                ( $this->clockFn )(),
                $wpUserId > 0 ? $wpUserId : null
            );
        } catch ( SolicitudInvalidaException $e ) {
            return $this->fallo( 'La solicitud no es válida: ' . $e->getMessage() );
        } catch ( CreationLockUnavailableException $e ) {
            return $this->fallo( 'El sistema está procesando otra solicitud. No se creó la solicitud: volvé a intentarlo en unos minutos.' );
        } catch ( SolicitudAbiertaException $e ) {
            // A refusal, not a system failure: nothing was stored, and
            // retrying cannot help until the other request is resolved.
            return $this->fallo( match ( $e->codigo() ) {
                PlazaSinSolicitudAbierta::CODE    => 'La plaza ya tiene una solicitud abierta, así que no se creó una nueva. Hay que resolver primero la existente en la Bandeja.',
                EntranteSinSolicitudAbierta::CODE => 'El jugador elegido ya figura como entrante en una solicitud abierta de esta temporada, así que no se creó la solicitud. Hay que resolver primero la otra.',
                default                           => 'La solicitud fue rechazada por una solicitud abierta existente. No se creó nada.',
            } );
        } catch ( TitularSinPlazaDeCampoException $e ) {
            // A structural precondition of the grouped tipo, not a system
            // failure: the REST route answers its generic 500 for it, but an
            // operator on the phone needs to know WHICH player is the problem.
            return $this->fallo( sprintf(
                '%s no ocupa exactamente una plaza de campo vigente en este equipo, así que no se puede pasar al arco. No se creó la solicitud. Revisá el plantel: puede que ya se haya movido o que figure en más de una plaza.',
                $this->nombrePost( $e->titularPlayerId(), 'El jugador elegido' )
            ) );
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'admin.nueva_solicitud_fallida', [
                'season_id' => $seasonId,
                'team_id'   => $teamId,
                'plaza_id'  => $plazaId,
                'tipo'      => $tipo,
                'wp_user'   => $wpUserId,
                'excepcion' => get_class( $e ),
                'mensaje'   => $e->getMessage(),
            ] );

            return $this->fallo( 'No se pudo crear la solicitud por un error interno. El detalle quedó registrado.' );
        }

        return [
            'ok'           => true,
            'mensaje'      => $this->mensajeDeExito( (int) $creada['id'], $teamId, $capitanPlayerId, $creada['dictamen'] ),
            'solicitud_id' => (int) $creada['id'],
        ];
    }

    /**
     * @param array{procede: bool, motivos: array<int, array<string, mixed>>, fechas_faltantes_liberacion: int|null} $dictamen
     */
    private function mensajeDeExito( int $id, int $teamId, int $capitanPlayerId, array $dictamen ): string {
        $base = sprintf(
            'Solicitud #%1$d cargada a nombre de %2$s, capitán de %3$s. Quedó pendiente en la Bandeja y se le avisó por mail.',
            $id,
            $this->nombrePost( $capitanPlayerId, 'el capitán' ),
            $this->nombrePost( $teamId, 'Equipo' )
        );

        if ( ! empty( $dictamen['procede'] ) ) {
            return $base . ' Dictamen: procede.';
        }

        $motivos = array_map(
            static fn ( array $m ): string => (string) ( $m['mensaje'] ?? $m['codigo'] ?? '' ),
            $dictamen['motivos']
        );

        return $base . ' Dictamen: no procede — ' . implode( '; ', array_filter( $motivos ) )
            . '. La solicitud se guardó igual: la comisión puede aprobarla de todos modos desde la Bandeja.';
    }

    /** @return array{ok: bool, mensaje: string, solicitud_id: int|null} */
    private function fallo( string $mensaje ): array {
        return [ 'ok' => false, 'mensaje' => $mensaje, 'solicitud_id' => null ];
    }

    /**
     * A thin, named seam around `check_admin_referer()` — same reason as
     * `BandejaPage::verificarNonceOMorir()`.
     */
    private function verificarNonceOMorir( string $action, string $field ): void {
        check_admin_referer( $action, $field );
    }

    private function guardarNotice( string $tipo, string $mensaje ): void {
        set_transient( $this->noticeKey(), [ 'tipo' => $tipo, 'mensaje' => $mensaje ], 60 );
    }

    private function noticeKey(): string {
        return 'cambios_nueva_solicitud_notice_' . get_current_user_id();
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(): void {
        if ( ! $this->authorizer->autorizado() ) {
            wp_die( esc_html__( 'No tenés permiso para acceder a esta página.', 'entre-redes-cambios' ) );
        }

        $notice = get_transient( $this->noticeKey() );
        if ( false !== $notice ) {
            delete_transient( $this->noticeKey() );
        }

        $seasonId  = $this->settings->seasonId();
        $capitanes = $this->capitanRepository->listCapitanesVigentes( $seasonId );
        $fecha     = FechaAbierta::de( $this->fechaRepository, $seasonId );

        $teamId = absint( $_GET['team_id'] ?? 0 );
        $tipo   = sanitize_text_field( (string) ( $_GET['tipo'] ?? '' ) );

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php if ( is_array( $notice ) ) : ?>
            <div class="notice notice-<?php echo esc_attr( (string) $notice['tipo'] ); ?> is-dismissible">
                <p>
                    <?php echo esc_html( (string) $notice['mensaje'] ); ?>
                    <?php if ( 'success' === $notice['tipo'] ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BandejaPage::SLUG ) ); ?>"><?php esc_html_e( 'Ver en la Bandeja', 'entre-redes-cambios' ); ?></a>
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <p>
                <?php esc_html_e( 'Cargá una solicitud en nombre de un capitán que avisó por teléfono. Se rige por las mismas reglas que si la hiciera él desde la app, y queda a su nombre.', 'entre-redes-cambios' ); ?>
            </p>

            <?php if ( null === $fecha ) : ?>
            <div class="notice notice-warning"><p><?php esc_html_e( 'No hay una fecha abierta en la temporada: no se pueden cargar solicitudes.', 'entre-redes-cambios' ); ?></p></div>
        </div>
                <?php return; ?>
            <?php endif; ?>

            <p><strong><?php esc_html_e( 'Fecha abierta:', 'entre-redes-cambios' ); ?></strong>
                <?php echo esc_html( sprintf( '%s — fecha %d (%s).', (string) $fecha['torneo_label'], (int) $fecha['numero_en_torneo'], (string) $fecha['play_date'] ) ); ?>
                <?php esc_html_e( 'El plazo se evalúa en el momento de la carga.', 'entre-redes-cambios' ); ?>
            </p>

            <?php
            if ( $teamId <= 0 || ! isset( $capitanes[ $teamId ] ) ) {
                if ( $teamId > 0 ) {
                    echo '<div class="notice notice-error"><p>' . esc_html__( 'Ese equipo no tiene un capitán vigente esta temporada.', 'entre-redes-cambios' ) . '</p></div>';
                }

                $this->renderPasoEquipo( $capitanes );
            } else {
                $this->renderResumenEquipo( $teamId, $capitanes[ $teamId ] );

                if ( ! in_array( $tipo, self::TIPOS, true ) ) {
                    $this->renderPasoTipo( $teamId );
                } else {
                    $this->renderPasosDelTipo( $seasonId, $teamId, $tipo );
                }
            }
            ?>
        </div>
        <?php
    }

    /** @param array<int, int> $capitanes team_id => player_id */
    private function renderPasoEquipo( array $capitanes ): void {
        $equipos = [];
        foreach ( array_keys( $capitanes ) as $teamId ) {
            $equipos[ $teamId ] = $this->nombrePost( $teamId, 'Equipo #' . $teamId );
        }
        asort( $equipos, SORT_NATURAL | SORT_FLAG_CASE );

        ?>
        <h2><?php esc_html_e( '1. ¿De qué equipo llaman?', 'entre-redes-cambios' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Solo aparecen los equipos con un capitán designado esta temporada: la solicitud queda a su nombre.', 'entre-redes-cambios' ); ?></p>
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
            <select name="team_id" required>
                <option value=""><?php esc_html_e( '— Elegir equipo —', 'entre-redes-cambios' ); ?></option>
                <?php foreach ( $equipos as $teamId => $nombre ) : ?>
                <option value="<?php echo esc_attr( (string) $teamId ); ?>">
                    <?php echo esc_html( sprintf( '%s — capitán: %s', $nombre, $this->nombrePost( $capitanes[ $teamId ], 'sin nombre' ) ) ); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php submit_button( __( 'Continuar', 'entre-redes-cambios' ), 'primary', '', false ); ?>
        </form>
        <?php
    }

    private function renderResumenEquipo( int $teamId, int $capitanPlayerId ): void {
        ?>
        <h2><?php echo esc_html( $this->nombrePost( $teamId, 'Equipo #' . $teamId ) ); ?></h2>
        <p>
            <?php esc_html_e( 'Quedará como solicitante el capitán:', 'entre-redes-cambios' ); ?>
            <strong><?php echo esc_html( $this->nombrePost( $capitanPlayerId, 'Jugador #' . $capitanPlayerId ) ); ?></strong>.
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>"><?php esc_html_e( 'Cambiar equipo', 'entre-redes-cambios' ); ?></a>
        </p>
        <?php
    }

    private function renderPasoTipo( int $teamId ): void {
        $opciones = [
            SolicitudDeCambio::TIPO_SUSTITUCION          => [ 'Sustitución', 'Un jugador deja su plaza y entra otro.' ],
            SolicitudDeCambio::TIPO_REGRESO              => [ 'Regreso', 'El titular vuelve a su plaza y se libera el suplente que la ocupa.' ],
            SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO => [ 'Reasignación al arco', 'Un titular de campo pasa al arco y entra un jugador de afuera a su plaza de campo.' ],
        ];

        ?>
        <h3><?php esc_html_e( '2. ¿Qué piden?', 'entre-redes-cambios' ); ?></h3>
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
            <input type="hidden" name="team_id" value="<?php echo esc_attr( (string) $teamId ); ?>">
            <?php foreach ( $opciones as $valor => [ $titulo, $explicacion ] ) : ?>
            <p>
                <label>
                    <input type="radio" name="tipo" value="<?php echo esc_attr( $valor ); ?>" required>
                    <strong><?php echo esc_html( $titulo ); ?></strong> — <?php echo esc_html( $explicacion ); ?>
                </label>
            </p>
            <?php endforeach; ?>
            <?php submit_button( __( 'Continuar', 'entre-redes-cambios' ), 'primary', '', false ); ?>
        </form>
        <?php
    }

    private function renderPasosDelTipo( int $seasonId, int $teamId, string $tipo ): void {
        $plazas = $this->plazaRepository->listPlazasByEquipo( $seasonId, $teamId );

        // plaza_id => player_id vigente en la plaza (null when nobody holds it)
        $ocupantes = [];
        foreach ( $plazas as $plaza ) {
            $vigente                         = $this->plazaRepository->findOcupacionVigente( (int) $plaza['id'] );
            $ocupantes[ (int) $plaza['id'] ] = null !== $vigente ? (int) $vigente['player_id'] : null;
        }

        $metricas = $this->metricasReader->resolveMuchos( array_values( array_unique( array_filter( array_merge(
            array_map( static fn ( array $p ): int => (int) $p['titular_player_id'], $plazas ),
            array_values( $ocupantes )
        ) ) ) ) );

        $plazaId = absint( $_GET['plaza_id'] ?? 0 );
        $titular = absint( $_GET['titular_player_id'] ?? 0 );

        $this->renderEncabezadoTipo( $teamId, $tipo );

        if ( SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO === $tipo ) {
            $plazaCampoId = 0;
            foreach ( $plazas as $plaza ) {
                if ( ! (bool) $plaza['es_arco'] && $titular > 0 && $ocupantes[ (int) $plaza['id'] ] === $titular ) {
                    $plazaCampoId = (int) $plaza['id'];
                }
            }

            if ( $plazaCampoId <= 0 ) {
                $this->renderElegirTitularDeCampo( $teamId, $tipo, $plazas, $ocupantes, $metricas );

                return;
            }

            $plazaArcoId = $this->plazaDelArco( $seasonId, $teamId );
            $plazaCampo  = $this->plazaRepository->findPlaza( $plazaCampoId );

            if ( null === $plazaArcoId || null === $plazaCampo ) {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'El equipo no tiene plaza de arquero o la plaza elegida no existe.', 'entre-redes-cambios' ) . '</p></div>';

                return;
            }

            $arqueroId = $ocupantes[ $plazaArcoId ] ?? null;

            ?>
            <p>
                <?php
                echo esc_html( sprintf(
                    'Sale del arco: %1$s. Pasa al arco: %2$s. Entra a la plaza de campo que deja: elegir abajo.',
                    null !== $arqueroId ? $this->jugadorConPuntaje( $arqueroId, $metricas ) : 'nadie (plaza sin ocupante)',
                    $this->jugadorConPuntaje( $titular, $metricas )
                ) );
                ?>
            </p>
            <?php
            $this->renderPasoEntrante( $teamId, $tipo, $plazaCampo, 0, $titular );

            return;
        }

        if ( $plazaId <= 0 || ! array_key_exists( $plazaId, $ocupantes ) ) {
            $this->renderElegirPlaza( $teamId, $tipo, $plazas, $ocupantes, $metricas );

            return;
        }

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        if ( null === $plaza ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'La plaza elegida no existe.', 'entre-redes-cambios' ) . '</p></div>';

            return;
        }

        $ocupante = $ocupantes[ $plazaId ];

        if ( SolicitudDeCambio::TIPO_REGRESO === $tipo ) {
            ?>
            <p>
                <?php
                echo esc_html( sprintf(
                    'Vuelve el titular %1$s a su plaza. Se libera: %2$s.',
                    $this->jugadorConPuntaje( (int) $plaza['titular_player_id'], $metricas ),
                    null !== $ocupante ? $this->jugadorConPuntaje( $ocupante, $metricas ) : 'nadie'
                ) );
                ?>
            </p>
            <?php
            $this->renderFormularioFinal( $teamId, $tipo, $plazaId, 0, 0, null );

            return;
        }

        ?>
        <p>
            <?php
            echo esc_html( sprintf(
                'Sale: %s. Entra: elegir abajo.',
                null !== $ocupante ? $this->jugadorConPuntaje( $ocupante, $metricas ) : 'nadie (plaza sin ocupante)'
            ) );
            ?>
        </p>
        <?php
        $this->renderPasoEntrante( $teamId, $tipo, $plaza, $plazaId, 0 );
    }

    private function renderEncabezadoTipo( int $teamId, string $tipo ): void {
        $titulos = [
            SolicitudDeCambio::TIPO_SUSTITUCION          => 'Sustitución',
            SolicitudDeCambio::TIPO_REGRESO              => 'Regreso',
            SolicitudDeCambio::TIPO_REASIGNACION_ARQUERO => 'Reasignación al arco',
        ];

        ?>
        <h3>
            <?php echo esc_html( $titulos[ $tipo ] ); ?>
            <small><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&team_id=' . $teamId ) ); ?>"><?php esc_html_e( 'Cambiar tipo', 'entre-redes-cambios' ); ?></a></small>
        </h3>
        <?php
    }

    /**
     * @param array<int, array<string, mixed>> $plazas
     * @param array<int, int|null>             $ocupantes
     * @param array<int, JugadorMetricas>      $metricas
     */
    private function renderElegirPlaza( int $teamId, string $tipo, array $plazas, array $ocupantes, array $metricas ): void {
        $esRegreso = SolicitudDeCambio::TIPO_REGRESO === $tipo;

        // A regreso only makes sense on a plaza whose titular is NOT the one
        // playing it: somebody else holds it and has to give it back.
        $elegibles = array_values( array_filter(
            $plazas,
            static fn ( array $p ): bool => ! $esRegreso || ( $ocupantes[ (int) $p['id'] ] ?? null ) !== (int) $p['titular_player_id']
        ) );

        ?>
        <h4><?php esc_html_e( '3. ¿Qué plaza?', 'entre-redes-cambios' ); ?></h4>
        <?php if ( [] === $elegibles ) : ?>
            <p><?php esc_html_e( 'Ningún titular está fuera de su plaza: no hay regresos para cargar.', 'entre-redes-cambios' ); ?></p>
            <?php return; ?>
        <?php endif; ?>
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
            <input type="hidden" name="team_id" value="<?php echo esc_attr( (string) $teamId ); ?>">
            <input type="hidden" name="tipo" value="<?php echo esc_attr( $tipo ); ?>">
            <table class="widefat striped" style="max-width:900px">
                <thead><tr>
                    <th></th>
                    <th><?php esc_html_e( 'Titular', 'entre-redes-cambios' ); ?></th>
                    <th><?php esc_html_e( 'Juega ahora', 'entre-redes-cambios' ); ?></th>
                    <th><?php esc_html_e( 'Techo', 'entre-redes-cambios' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $elegibles as $plaza ) : ?>
                    <?php $ocupante = $ocupantes[ (int) $plaza['id'] ] ?? null; ?>
                    <tr>
                        <td><input type="radio" name="plaza_id" value="<?php echo esc_attr( (string) $plaza['id'] ); ?>" required></td>
                        <td>
                            <?php echo esc_html( $this->jugadorConPuntaje( (int) $plaza['titular_player_id'], $metricas ) ); ?>
                            <?php if ( (bool) $plaza['es_arco'] ) : ?><em>(<?php esc_html_e( 'arco', 'entre-redes-cambios' ); ?>)</em><?php endif; ?>
                        </td>
                        <td><?php echo esc_html( null !== $ocupante ? $this->jugadorConPuntaje( $ocupante, $metricas ) : '—' ); ?></td>
                        <td><?php echo esc_html( (string) Puntaje::fromHalfPoints( (int) $plaza['puntaje_techo'] ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button( __( 'Continuar', 'entre-redes-cambios' ), 'primary', '', true ); ?>
        </form>
        <?php
    }

    /**
     * @param array<int, array<string, mixed>> $plazas
     * @param array<int, int|null>             $ocupantes
     * @param array<int, JugadorMetricas>      $metricas
     */
    private function renderElegirTitularDeCampo( int $teamId, string $tipo, array $plazas, array $ocupantes, array $metricas ): void {
        $deCampo = array_values( array_filter(
            $plazas,
            static fn ( array $p ): bool => ! (bool) $p['es_arco'] && null !== ( $ocupantes[ (int) $p['id'] ] ?? null )
        ) );

        ?>
        <h4><?php esc_html_e( '3. ¿Qué jugador de campo pasa al arco?', 'entre-redes-cambios' ); ?></h4>
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
            <input type="hidden" name="team_id" value="<?php echo esc_attr( (string) $teamId ); ?>">
            <input type="hidden" name="tipo" value="<?php echo esc_attr( $tipo ); ?>">
            <?php foreach ( $deCampo as $plaza ) : ?>
                <?php $jugador = (int) $ocupantes[ (int) $plaza['id'] ]; ?>
                <p>
                    <label>
                        <input type="radio" name="titular_player_id" value="<?php echo esc_attr( (string) $jugador ); ?>" required>
                        <?php echo esc_html( $this->jugadorConPuntaje( $jugador, $metricas ) ); ?>
                    </label>
                </p>
            <?php endforeach; ?>
            <?php submit_button( __( 'Continuar', 'entre-redes-cambios' ), 'primary', '', true ); ?>
        </form>
        <?php
    }

    /**
     * Step 4 for the tipos that bring somebody in: the candidate list (the
     * captain's own, but with the non-viable ones SHOWN and disabled), then
     * the POST form that creates the request.
     *
     * @param array<string, mixed> $plazaDeEntrada The plaza the entrante fills
     *        (the field plaza for `reasignacion_arquero`).
     */
    private function renderPasoEntrante( int $teamId, string $tipo, array $plazaDeEntrada, int $plazaIdForm, int $titularPlayerId ): void {
        $seasonId = $this->settings->seasonId();
        $search   = sanitize_text_field( (string) ( $_GET['s'] ?? '' ) );
        $page     = max( 1, absint( $_GET['pagina'] ?? 1 ) );

        $contador  = ( new BoundedFechaCounter( $this->fechaRepository, $this->eventLog ) )->boundedCountResolvedFechasSinceFn( $seasonId );
        $resultado = $this->candidatosResolver->buscarPaginado( $plazaDeEntrada, null, null, $this->politica, $contador, $page, self::PER_PAGE, $search, [] );

        /** @var CandidatoEstado[] $candidatos */
        $candidatos = $resultado['candidatos'];
        $total      = (int) $resultado['total'];

        $metricas = $this->metricasReader->resolveMuchos( array_map(
            static fn ( CandidatoEstado $c ): int => $c->playerId(),
            $candidatos
        ) );

        $baseQuery = [
            'page'    => self::SLUG,
            'team_id' => $teamId,
            'tipo'    => $tipo,
        ];
        if ( $plazaIdForm > 0 ) {
            $baseQuery['plaza_id'] = $plazaIdForm;
        }
        if ( $titularPlayerId > 0 ) {
            $baseQuery['titular_player_id'] = $titularPlayerId;
        }

        ?>
        <h4><?php esc_html_e( '4. ¿Quién entra?', 'entre-redes-cambios' ); ?></h4>
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
            <?php foreach ( $baseQuery as $nombre => $valor ) : ?>
            <input type="hidden" name="<?php echo esc_attr( (string) $nombre ); ?>" value="<?php echo esc_attr( (string) $valor ); ?>">
            <?php endforeach; ?>
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Buscar por nombre', 'entre-redes-cambios' ); ?>">
            <?php submit_button( __( 'Buscar', 'entre-redes-cambios' ), 'secondary', '', false ); ?>
        </form>
        <p class="description">
            <?php
            echo esc_html( sprintf(
                'Techo de la plaza: %s. Los jugadores marcados no pueden entrar; se muestran para poder explicar por qué.',
                (string) Puntaje::fromHalfPoints( (int) $plazaDeEntrada['puntaje_techo'] )
            ) );
            ?>
        </p>
        <?php
        $this->renderFormularioFinal( $teamId, $tipo, $plazaIdForm, $titularPlayerId, $total, [
            'candidatos' => $candidatos,
            'metricas'   => $metricas,
            'baseQuery'  => $baseQuery + ( '' !== $search ? [ 's' => $search ] : [] ),
            'pagina'     => $page,
        ] );
    }

    /**
     * The POST form — the only state-changing thing on this page.
     *
     * @param array{candidatos: CandidatoEstado[], metricas: array<int, JugadorMetricas>, baseQuery: array<string, mixed>, pagina: int}|null $lista
     */
    private function renderFormularioFinal( int $teamId, string $tipo, int $plazaId, int $titularPlayerId, int $total, ?array $lista ): void {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
            <input type="hidden" name="cambios_action" value="<?php echo esc_attr( self::ACCION ); ?>">
            <input type="hidden" name="team_id" value="<?php echo esc_attr( (string) $teamId ); ?>">
            <input type="hidden" name="tipo" value="<?php echo esc_attr( $tipo ); ?>">
            <input type="hidden" name="plaza_id" value="<?php echo esc_attr( (string) $plazaId ); ?>">
            <input type="hidden" name="titular_player_id" value="<?php echo esc_attr( (string) $titularPlayerId ); ?>">

            <?php if ( null !== $lista ) : ?>
                <?php if ( [] === $lista['candidatos'] ) : ?>
                    <p><?php esc_html_e( 'No hay jugadores para mostrar con ese filtro.', 'entre-redes-cambios' ); ?></p>
                <?php else : ?>
                <table class="widefat striped" style="max-width:900px">
                    <thead><tr>
                        <th></th>
                        <th><?php esc_html_e( 'Jugador', 'entre-redes-cambios' ); ?></th>
                        <th><?php esc_html_e( 'Puntaje', 'entre-redes-cambios' ); ?></th>
                        <th><?php esc_html_e( 'Estado', 'entre-redes-cambios' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $lista['candidatos'] as $candidato ) : ?>
                        <?php $id = $candidato->playerId(); ?>
                        <tr<?php echo $candidato->viable() ? '' : ' style="opacity:.6"'; ?>>
                            <td>
                                <input type="radio" name="entrante_player_id" value="<?php echo esc_attr( (string) $id ); ?>" required<?php echo $candidato->viable() ? '' : ' disabled'; ?>>
                            </td>
                            <td><?php echo esc_html( $this->nombrePost( $id, 'Jugador #' . $id ) ); ?><?php echo $candidato->esPadre() ? ' ' . esc_html__( '(padre)', 'entre-redes-cambios' ) : ''; ?></td>
                            <td><?php echo esc_html( $this->puntajeLegible( $id, $lista['metricas'] ) ); ?></td>
                            <td><?php echo esc_html( $candidato->viable() ? __( 'Puede entrar', 'entre-redes-cambios' ) : $this->etiquetaMotivo( (string) $candidato->motivoNoViable() ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <?php $this->renderPaginacion( $lista['baseQuery'], $lista['pagina'], $total ); ?>
            <?php endif; ?>

            <p class="description">
                <?php esc_html_e( 'Al cargarla se evalúa el dictamen, se guarda como pendiente y se avisa por mail a la comisión y al capitán.', 'entre-redes-cambios' ); ?>
            </p>
            <?php submit_button( __( 'Cargar solicitud', 'entre-redes-cambios' ), 'primary', 'submit', true ); ?>
        </form>
        <?php
    }

    /** @param array<string, mixed> $baseQuery */
    private function renderPaginacion( array $baseQuery, int $pagina, int $total ): void {
        $paginas = (int) max( 1, ceil( $total / self::PER_PAGE ) );

        if ( $paginas <= 1 ) {
            return;
        }

        $link = static fn ( int $n ): string => esc_url( admin_url( 'admin.php?' . http_build_query( $baseQuery + [ 'pagina' => $n ] ) ) );

        ?>
        <p>
            <?php if ( $pagina > 1 ) : ?>
                <a href="<?php echo $link( $pagina - 1 ); ?>"><?php esc_html_e( '« Anterior', 'entre-redes-cambios' ); ?></a>
            <?php endif; ?>
            <?php echo esc_html( sprintf( 'Página %d de %d', $pagina, $paginas ) ); ?>
            <?php if ( $pagina < $paginas ) : ?>
                <a href="<?php echo $link( $pagina + 1 ); ?>"><?php esc_html_e( 'Siguiente »', 'entre-redes-cambios' ); ?></a>
            <?php endif; ?>
        </p>
        <?php
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function plazaDelArco( int $seasonId, int $teamId ): ?int {
        foreach ( $this->plazaRepository->listPlazasByEquipo( $seasonId, $teamId ) as $plaza ) {
            if ( (bool) $plaza['es_arco'] ) {
                return (int) $plaza['id'];
            }
        }

        return null;
    }

    private function etiquetaMotivo( string $codigo ): string {
        return match ( $codigo ) {
            'ocupa_otra_plaza_vigente'      => __( 'No puede entrar: ya ocupa otra plaza', 'entre-redes-cambios' ),
            'bloqueado_por_cierre_truncado' => __( 'No puede entrar: bloqueado por un cierre anterior', 'entre-redes-cambios' ),
            'puntaje_excede_techo'          => __( 'No puede entrar: supera el techo de la plaza', 'entre-redes-cambios' ),
            'puntaje_indeterminado'         => __( 'No puede entrar: sin puntaje', 'entre-redes-cambios' ),
            default                         => sprintf( __( 'No puede entrar (%s)', 'entre-redes-cambios' ), $codigo ),
        };
    }

    /** @param array<int, JugadorMetricas> $metricas */
    private function jugadorConPuntaje( int $playerId, array $metricas ): string {
        $nombre  = $this->nombrePost( $playerId, 'Jugador #' . $playerId );
        $puntaje = $this->puntajeLegible( $playerId, $metricas );

        return '' !== $puntaje ? sprintf( '%s [%s]', $nombre, $puntaje ) : $nombre;
    }

    /**
     * An empty string — never a fabricated 0 — when the puntaje is unknown.
     *
     * @param array<int, JugadorMetricas> $metricas
     */
    private function puntajeLegible( int $playerId, array $metricas ): string {
        $puntaje = isset( $metricas[ $playerId ] ) ? $metricas[ $playerId ]->puntaje() : null;

        return null !== $puntaje ? (string) $puntaje : '';
    }

    private function nombrePost( int $postId, string $fallback ): string {
        $titulo = $postId > 0 ? trim( (string) get_the_title( $postId ) ) : '';

        return '' !== $titulo ? $titulo : $fallback;
    }
}

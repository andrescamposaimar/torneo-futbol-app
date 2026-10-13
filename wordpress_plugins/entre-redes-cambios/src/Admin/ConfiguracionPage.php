<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Admin;

use EntreRedes\Cambios\Calendario\PlazosValidator;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Calendario\SettingsWriter;
use EntreRedes\Cambios\Observability\EventLog;

/**
 * The third screen under "Cambios" (slug: cambios-configuracion) — the one
 * that makes the policy rows in `cambios_settings` editable without a
 * database client (0.1.23).
 *
 * *** WHAT THIS SCREEN IS NOT ***
 * It does not INVENT any setting. Every row it writes was already read by
 * `Calendario\Settings` and already honoured by the ruleset; until now the
 * only way to change one was a hand-written `UPDATE`, which is exactly how a
 * window got opened with a `-14` during a dry run and had to be remembered
 * back afterwards. So this ships with ZERO behaviour change: the same values,
 * reachable.
 *
 * *** THE INVARIANT THIS SCREEN DEFENDS ***
 * `Calendario\PlazosCalculator::isWithinSolicitudWindow()` is
 * `now >= apertura && now <= cierre_solicitudes`, and nothing else in the
 * codebase checks that the first is earlier than the second. Swapping two
 * numbers here would produce a window that never opens, with no exception
 * and no event — every captain just finds the button disabled. The whole
 * save is therefore refused unless `Calendario\PlazosValidator` accepts the
 * four offsets AS A SET, and the operator gets every problem at once rather
 * than one typo per round-trip. Nothing partial is ever written: a half
 * calendar is worse than the old one, because the operator believes they
 * changed it.
 *
 * *** SECURITY, THE SAME WAY BandejaPage DOES IT ***
 * Unlike `HistoricoPage`, this screen WRITES, so it carries the full set:
 * `ProcessOwnerAuthorizer::autorizado()` in BOTH `render()` and the POST
 * dispatcher (one check for every action, never one per action),
 * `check_admin_referer()` before anything touches the writer, PRG
 * (`wp_safe_redirect` + `exit`) so a refresh cannot re-submit, and every
 * printed value escaped.
 *
 * `ejecutarGuardar()` holds the logic with no `exit` anywhere so tests can
 * invoke it directly and assert on its return value — the same split, and
 * for the same reason, as `BandejaPage::ejecutarAprobar()`.
 */
class ConfiguracionPage {

    public const SLUG = 'cambios-configuracion';

    private const NONCE        = 'cambios_guardar_configuracion';
    private const NONCE_FIELD  = 'cambios_configuracion_nonce';

    /** The four plazo rows, in the chronological order the form shows them. */
    private const PLAZOS = [
        'apertura_solicitudes' => [
            'setting' => 'plazo_apertura_solicitudes',
            'label'   => 'Apertura de solicitudes',
            'ayuda'   => 'Desde cuándo un capitán puede pedir un cambio.',
        ],
        'cierre_regresos' => [
            'setting' => 'plazo_cierre_regresos',
            'label'   => 'Cierre de regresos',
            'ayuda'   => 'Hasta cuándo se puede pedir que vuelva un titular.',
        ],
        'cierre_solicitudes' => [
            'setting' => 'plazo_cierre_solicitudes',
            'label'   => 'Cierre de cambios',
            'ayuda'   => 'Hasta cuándo se puede pedir un cambio.',
        ],
        'publicacion' => [
            'setting' => 'plazo_publicacion',
            'label'   => 'Publicación',
            'ayuda'   => 'Cuándo se anuncia el lote aprobado.',
        ],
    ];

    private ProcessOwnerAuthorizer $authorizer;
    private Settings $settings;
    private SettingsWriter $writer;
    private EventLog $eventLog;

    /** @var callable(): int */
    private $clockFn;

    public function __construct(
        ProcessOwnerAuthorizer $authorizer,
        Settings $settings,
        SettingsWriter $writer,
        EventLog $eventLog,
        ?callable $clockFn = null
    ) {
        $this->authorizer = $authorizer;
        $this->settings   = $settings;
        $this->writer     = $writer;
        $this->eventLog   = $eventLog;
        $this->clockFn    = $clockFn ?? static fn (): int => time();
    }

    // -------------------------------------------------------------------------
    // POST
    // -------------------------------------------------------------------------

    public function handlePost(): void {
        $action = (string) ( $_POST['cambios_config_action'] ?? '' );

        if ( '' === $action ) {
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

        if ( 'guardar' !== $action ) {
            return;
        }

        check_admin_referer( self::NONCE, self::NONCE_FIELD );

        $resultado = $this->ejecutarGuardar( $this->leerFormulario() );

        set_transient( 'cambios_config_notice_' . get_current_user_id(), $resultado, 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
        exit;
    }

    /**
     * Core logic: validate the whole set, then write. No exit, no redirect —
     * directly testable.
     *
     * @param array<string, mixed> $entrada As leerFormulario() shapes it.
     * @return array{ok: bool, errores: string[], escritas: string[], fallidas: string[]}
     */
    private function ejecutarGuardar( array $entrada ): array {
        /** @var array<string, array{days: mixed, time: mixed}> $offsets */
        $offsets = $entrada['offsets'];

        $errores = PlazosValidator::validar( $offsets );

        // Validated HERE rather than inside PlazosValidator: that class is
        // about the four plazos as a chain, and the mínimo is a different
        // kind of number that happens to live on the same form. It rides on
        // the same all-or-nothing save, though — see class docblock.
        $minimo = $entrada['minimo_fechas'];

        if ( ! is_int( $minimo ) || $minimo < 1 || $minimo > 10 ) {
            $errores[] = sprintf(
                /* translators: %s: the submitted value */
                __( 'El mínimo de fechas tiene que ser un número entero entre 1 y 10 (recibido: "%s").', 'entre-redes-cambios' ),
                is_scalar( $minimo ) ? (string) $minimo : gettype( $minimo )
            );
        }

        if ( ! empty( $errores ) ) {
            // NOTHING is written — not even the rows that were individually
            // fine. See class docblock.
            $this->eventLog->record( 'settings.guardado_rechazado', [
                'pantalla' => self::SLUG,
                'errores'  => $errores,
            ] );

            return [ 'ok' => false, 'errores' => $errores, 'escritas' => [], 'fallidas' => [] ];
        }

        $valores = [];

        foreach ( self::PLAZOS as $clave => $meta ) {
            $valores[ $meta['setting'] ] = SettingsWriter::encodeOffset(
                (int) $offsets[ $clave ]['days'],
                (string) $offsets[ $clave ]['time']
            );
        }

        $valores['minimo_fechas_cambio'] = (string) (int) $entrada['minimo_fechas'];
        $valores['exencion_arco_activa'] = $entrada['exencion_arco'] ? '1' : '0';
        $valores['prioridad_padres_activa'] = $entrada['prioridad_padres'] ? '1' : '0';

        $escrito = $this->writer->guardarMuchos( $valores, gmdate( 'Y-m-d H:i:s', ( $this->clockFn )() ) );

        return [
            'ok'       => empty( $escrito['fallidas'] ),
            'errores'  => [],
            'escritas' => $escrito['escritas'],
            'fallidas' => $escrito['fallidas'],
        ];
    }

    /**
     * Reads the form into the shape `ejecutarGuardar()` expects.
     *
     * `days` is cast to int and `time` trimmed, but NOTHING is corrected
     * here: a bad value must reach `PlazosValidator` and come back as a
     * message the operator reads, never be silently rounded into something
     * valid. A missing `days` field becomes the string `''`, which the
     * validator rejects as "not an integer" — deliberately not `0`, which
     * would be a legitimate value and would read as an intentional choice.
     *
     * The two checkboxes are the ordinary HTML story: absent means
     * unchecked, so `isset()` IS the value.
     *
     * @return array{offsets: array<string, array{days: mixed, time: mixed}>, exencion_arco: bool, prioridad_padres: bool}
     */
    private function leerFormulario(): array {
        $offsets = [];

        foreach ( array_keys( self::PLAZOS ) as $clave ) {
            $diasCrudo = $_POST[ 'dias_' . $clave ] ?? '';
            $horaCruda = $_POST[ 'hora_' . $clave ] ?? '';

            $dias = is_scalar( $diasCrudo ) ? trim( (string) $diasCrudo ) : '';
            $hora = is_scalar( $horaCruda ) ? trim( sanitize_text_field( wp_unslash( (string) $horaCruda ) ) ) : '';

            $offsets[ $clave ] = [
                // Only a string that IS an integer becomes one; anything else
                // stays a string so the validator can name it.
                'days' => ( '' !== $dias && 1 === preg_match( '/^-?[0-9]+$/', $dias ) ) ? (int) $dias : $dias,
                'time' => $hora,
            ];
        }

        $minimoCrudo = $_POST['minimo_fechas_cambio'] ?? '';
        $minimo      = is_scalar( $minimoCrudo ) ? trim( (string) $minimoCrudo ) : '';

        return [
            'offsets'          => $offsets,
            // Same discipline as `days`: only a string that IS an integer
            // becomes one, so garbage reaches the validator as garbage and
            // comes back as a sentence instead of being rounded into
            // something plausible.
            'minimo_fechas'    => ( '' !== $minimo && 1 === preg_match( '/^-?[0-9]+$/', $minimo ) ) ? (int) $minimo : $minimo,
            'exencion_arco'    => isset( $_POST['exencion_arco_activa'] ),
            'prioridad_padres' => isset( $_POST['prioridad_padres_activa'] ),
        ];
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(): void {
        if ( ! $this->authorizer->autorizado() ) {
            wp_die( esc_html__( 'No tenés permiso para acceder a esta página.', 'entre-redes-cambios' ) );
        }

        $noticeKey = 'cambios_config_notice_' . get_current_user_id();
        $notice    = get_transient( $noticeKey );
        if ( false !== $notice ) {
            delete_transient( $noticeKey );
        }

        $offsets = $this->settings->plazosOffsets();

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php $this->renderNotice( is_array( $notice ) ? $notice : null ); ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>">
                <?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
                <input type="hidden" name="cambios_config_action" value="guardar">

                <h2><?php esc_html_e( 'Plazos', 'entre-redes-cambios' ); ?></h2>
                <p class="description">
                    <?php
                    esc_html_e(
                        'Los plazos se cuentan desde el día del PRÓXIMO partido, no del anterior: en días '
                        . '(negativo = antes del partido) y hora del día. Así, si una fecha se posterga, todos '
                        . 'los plazos se reacomodan solos. Tienen que quedar en este orden; si se cruzan, la '
                        . 'ventana de cambios nunca se abre.',
                        'entre-redes-cambios'
                    );
                    ?>
                </p>

                <table class="form-table" role="presentation">
                    <tbody>
                    <?php foreach ( self::PLAZOS as $clave => $meta ) : ?>
                        <tr>
                            <th scope="row">
                                <label for="dias_<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $meta['label'] ); ?></label>
                            </th>
                            <td>
                                <input
                                    type="number"
                                    id="dias_<?php echo esc_attr( $clave ); ?>"
                                    name="dias_<?php echo esc_attr( $clave ); ?>"
                                    value="<?php echo esc_attr( (string) ( $offsets[ $clave ]['days'] ?? 0 ) ); ?>"
                                    step="1"
                                    min="-90"
                                    max="0"
                                    class="small-text">
                                <label for="hora_<?php echo esc_attr( $clave ); ?>"><?php esc_html_e( 'días, a las', 'entre-redes-cambios' ); ?></label>
                                <input
                                    type="text"
                                    id="hora_<?php echo esc_attr( $clave ); ?>"
                                    name="hora_<?php echo esc_attr( $clave ); ?>"
                                    value="<?php echo esc_attr( (string) ( $offsets[ $clave ]['time'] ?? '00:00:00' ) ); ?>"
                                    pattern="[0-9]{2}:[0-9]{2}:[0-9]{2}"
                                    placeholder="23:59:59"
                                    class="small-text">
                                <p class="description"><?php echo esc_html( $meta['ayuda'] ); ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <h2><?php esc_html_e( 'Reglas', 'entre-redes-cambios' ); ?></h2>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="minimo_fechas_cambio"><?php esc_html_e( 'Mínimo de fechas', 'entre-redes-cambios' ); ?></label>
                            </th>
                            <td>
                                <input
                                    type="number"
                                    id="minimo_fechas_cambio"
                                    name="minimo_fechas_cambio"
                                    value="<?php echo esc_attr( (string) $this->settings->minimoFechasCambio() ); ?>"
                                    step="1"
                                    min="1"
                                    max="10"
                                    class="small-text">
                                <p class="description">
                                    <?php
                                    esc_html_e(
                                        'Cuántas fechas jugadas tiene que cumplir un cambio antes de que el titular pueda volver. '
                                        . 'El reglamento dice 3 hoy. OJO: no se congela por cambio — se recalcula siempre, así que '
                                        . 'subirlo vuelve a bloquear suplentes que ya estaban liberados y bajarlo libera varios de '
                                        . 'golpe. Conviene tocarlo entre fechas, no un jueves.',
                                        'entre-redes-cambios'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Exención del arco', 'entre-redes-cambios' ); ?></th>
                            <td>
                                <label>
                                    <input
                                        type="checkbox"
                                        name="exencion_arco_activa"
                                        value="1"
                                        <?php checked( $this->settings->exencionArcoActiva() ); ?>>
                                    <?php esc_html_e( 'El titular de campo que pasa al arco no paga el techo de la plaza de arco', 'entre-redes-cambios' ); ?>
                                </label>
                                <p class="description">
                                    <?php
                                    esc_html_e(
                                        'Apagado, ese movimiento paga el techo que deriva del puntaje del arquero que sale. '
                                        . 'El movimiento de campo —el jugador que entra a la plaza que quedó libre— paga su '
                                        . 'techo siempre, prendido o apagado.',
                                        'entre-redes-cambios'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Prioridad de padres', 'entre-redes-cambios' ); ?></th>
                            <td>
                                <label>
                                    <input
                                        type="checkbox"
                                        name="prioridad_padres_activa"
                                        value="1"
                                        <?php checked( $this->settings->prioridadPadresActiva() ); ?>>
                                    <?php esc_html_e( 'Exigir que se agoten los padres disponibles antes de aceptar un invitado', 'entre-redes-cambios' ); ?>
                                </label>
                                <p class="description">
                                    <?php
                                    esc_html_e(
                                        'Hoy esta preferencia del reglamento no se exige. Prenderla la convierte en un '
                                        . 'requisito: un invitado queda rechazado mientras haya un padre viable para esa plaza.',
                                        'entre-redes-cambios'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button( __( 'Guardar configuración', 'entre-redes-cambios' ) ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * @param array{ok: bool, errores: string[], escritas: string[], fallidas: string[]}|null $notice
     */
    private function renderNotice( ?array $notice ): void {
        if ( null === $notice ) {
            return;
        }

        if ( ! empty( $notice['errores'] ) ) {
            echo '<div class="notice notice-error"><p><strong>'
                . esc_html__( 'No se guardó nada. Revisá esto:', 'entre-redes-cambios' )
                . '</strong></p><ul style="list-style:disc;margin-left:20px;">';

            foreach ( $notice['errores'] as $error ) {
                echo '<li>' . esc_html( (string) $error ) . '</li>';
            }

            echo '</ul></div>';

            return;
        }

        if ( ! empty( $notice['fallidas'] ) ) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        /* translators: %s: comma-separated setting keys */
                        __( 'Algunos valores no se pudieron guardar: %s. El error quedó registrado.', 'entre-redes-cambios' ),
                        implode( ', ', array_map( 'strval', $notice['fallidas'] ) )
                    )
                )
            );

            return;
        }

        if ( empty( $notice['escritas'] ) ) {
            printf(
                '<div class="notice notice-info"><p>%s</p></div>',
                esc_html__( 'No había nada para cambiar.', 'entre-redes-cambios' )
            );

            return;
        }

        printf(
            '<div class="notice notice-success"><p>%s</p></div>',
            esc_html(
                sprintf(
                    /* translators: %d: how many settings changed */
                    _n( 'Se guardó %d valor.', 'Se guardaron %d valores.', count( $notice['escritas'] ), 'entre-redes-cambios' ),
                    count( $notice['escritas'] )
                )
            )
        );
    }
}

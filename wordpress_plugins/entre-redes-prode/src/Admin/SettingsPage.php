<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Admin;

use EntreRedes\Prode\Fecha\BackfillMatchMetaService;
use EntreRedes\Prode\Fecha\SeedFechaService;

/**
 * Renders and handles POST for the Configuración admin subpage (slug: prode-settings).
 *
 * Design notes (D3):
 *   - render() outputs the form on GET requests.
 *   - handlePost() is registered on admin_init and handles POST via PRG.
 *   - WP_List_Table guard (D2) is NOT needed here — no list table on this page.
 *
 * Security (ADR-P014, CC-01..05):
 *   - manage_options checked in BOTH render() and handlePost().
 *   - Nonce verified before any DB access.
 *   - All output escaped with esc_html() / esc_attr().
 *   - Crypto options (prode_rsa_*, prode_audit_dni_pepper) never referenced.
 */
class SettingsPage {

    private const NONCE_SETTINGS  = 'prode_settings_save';
    private const NONCE_FIELD     = 'prode_settings_nonce';
    private const NONCE_SEED      = 'prode_seed_fecha';
    private const NONCE_SEED_FIELD = 'prode_seed_nonce';
    private const NONCE_REPAIR         = 'prode_repair_names';
    private const NONCE_REPAIR_FIELD   = 'prode_repair_nonce';
    private const NONCE_BACKFILL       = 'prode_backfill_match_meta';
    private const NONCE_BACKFILL_FIELD = 'prode_backfill_nonce';

    private const CRON_HOOKS = [
        'prode_create_new_fecha_cron',
        'prode_evaluate_matches_cron',
        'prode_recompute_rankings_cron',
        'prode_notify_lock_approaching_cron',
    ];

    private const SETTING_LABELS = [
        'lock_hours_before'               => 'Horas de cierre antes del primer partido',
        'lock_warning_hours_before'       => 'Horas de advertencia antes del cierre',
        'fecha_window_days'               => 'Días de ventana por fecha',
        'prode_season_id'                 => 'ID de temporada activa',
        'prode_ranking_from_fecha_id'     => 'Ranking general desde la fecha N (0 = toda la temporada)',
        'evaluator_cron_interval_minutes' => 'Intervalo del evaluador (minutos)',
    ];

    public function __construct(
        private SettingsRepository $settingsRepo,
        private SeedFechaService $seedService,
        private RepairDisplayNamesService $repairService,
        private BackfillMatchMetaService $backfillService
    ) {}

    // -------------------------------------------------------------------------
    // POST handler — registered on admin_init (D3)
    // -------------------------------------------------------------------------

    /**
     * Registered on admin_init. Routes to the appropriate sub-handler based on
     * which action the form submitted.
     */
    public function handlePost(): void {
        $action = $_POST['prode_action'] ?? '';

        if ( $action === 'save_settings' ) {
            $this->handleSaveSettings();
        } elseif ( $action === 'seed_fecha' ) {
            $this->handleSeedFecha();
        } elseif ( $action === 'repair_names' ) {
            $this->handleRepairNames();
        } elseif ( $action === 'backfill_match_meta' ) {
            $this->handleBackfillMatchMeta();
        }
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    /**
     * Render callback for the prode-settings submenu page.
     */
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tenés permiso para acceder a esta página.', 'entre-redes-prode' ) );
        }

        $settingsMeta  = $this->settingsRepo->getSettingsWithMeta();
        $providerOpts  = $this->settingsRepo->getProviderOptions();

        // Recover submitted values after a validation failure (stored in transient).
        $transientKey  = 'prode_settings_submitted_' . get_current_user_id();
        $submitted     = get_transient( $transientKey );
        if ( $submitted !== false ) {
            delete_transient( $transientKey );
        }

        // Error/success notices passed via query arg.
        $notice    = '';
        $noticeType = 'error';
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( isset( $_GET['prode_settings_notice'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification
            $noticeKey = sanitize_text_field( (string) $_GET['prode_settings_notice'] );
            if ( $noticeKey === 'saved' ) {
                $notice    = __( 'Configuración guardada correctamente.', 'entre-redes-prode' );
                $noticeType = 'success';
            }
        }

        // Validation errors stored in transient.
        $errorsKey  = 'prode_settings_errors_' . get_current_user_id();
        $errors     = get_transient( $errorsKey );
        if ( $errors !== false ) {
            delete_transient( $errorsKey );
        } else {
            $errors = [];
        }

        // Seed-fecha notices.
        $seedNotice     = '';
        $seedNoticeType = 'info';
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( isset( $_GET['prode_seed_notice'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification
            $seedKey = sanitize_text_field( (string) $_GET['prode_seed_notice'] );
            if ( $seedKey === 'created' ) {
                // phpcs:ignore WordPress.Security.NonceVerification
                $seedFechaId    = (int) ( $_GET['fecha_id'] ?? 0 );
                // phpcs:ignore WordPress.Security.NonceVerification
                $seedMatchCount = (int) ( $_GET['match_count'] ?? 0 );
                $seedNotice     = sprintf(
                    /* translators: 1: fecha ID, 2: match count */
                    __( 'Fecha creada correctamente (ID: %1$d, %2$d partidos).', 'entre-redes-prode' ),
                    $seedFechaId,
                    $seedMatchCount
                );
                $seedNoticeType = 'success';
            } elseif ( $seedKey === 'reused' ) {
                // phpcs:ignore WordPress.Security.NonceVerification
                $seedFechaId = (int) ( $_GET['fecha_id'] ?? 0 );
                $seedNotice  = sprintf(
                    /* translators: fecha ID */
                    __( 'Ya existe una fecha activa (ID: %d). No se creó una nueva.', 'entre-redes-prode' ),
                    $seedFechaId
                );
                $seedNoticeType = 'info';
            } elseif ( $seedKey === 'skipped' ) {
                $seedNotice     = __( 'No se encontraron partidos para la próxima fecha.', 'entre-redes-prode' );
                $seedNoticeType = 'warning';
            } elseif ( $seedKey === 'error' ) {
                $seedNotice     = __( 'Error al crear la fecha. Revisá los logs del servidor.', 'entre-redes-prode' );
                $seedNoticeType = 'error';
            }
        }

        // Repair-display-names notices.
        $repairNotice     = '';
        $repairNoticeType = 'info';
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( isset( $_GET['prode_repair_notice'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification
            $repairKey = sanitize_text_field( (string) $_GET['prode_repair_notice'] );
            if ( $repairKey === 'done' ) {
                // phpcs:ignore WordPress.Security.NonceVerification
                $repairCount   = (int) ( $_GET['repaired'] ?? 0 );
                // phpcs:ignore WordPress.Security.NonceVerification
                $repairScanned = (int) ( $_GET['scanned'] ?? 0 );
                if ( $repairCount > 0 ) {
                    $repairNotice = sprintf(
                        /* translators: 1: repaired count, 2: scanned count */
                        __( 'Se repararon %1$d nombres (de %2$d candidatos revisados).', 'entre-redes-prode' ),
                        $repairCount,
                        $repairScanned
                    );
                    $repairNoticeType = 'success';
                } else {
                    $repairNotice     = __( 'No se encontraron nombres para reparar.', 'entre-redes-prode' );
                    $repairNoticeType = 'info';
                }
            } elseif ( $repairKey === 'error' ) {
                $repairNotice     = __( 'Error al reparar los nombres. Revisá los logs del servidor.', 'entre-redes-prode' );
                $repairNoticeType = 'error';
            }
        }

        // Backfill match meta notices.
        $backfillNotice     = '';
        $backfillNoticeType = 'info';
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( isset( $_GET['prode_backfill_notice'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification
            $backfillKey = sanitize_text_field( (string) $_GET['prode_backfill_notice'] );
            if ( $backfillKey === 'done' ) {
                // phpcs:ignore WordPress.Security.NonceVerification
                $backfillCount = (int) ( $_GET['backfilled'] ?? 0 );
                if ( $backfillCount > 0 ) {
                    $backfillNotice = sprintf(
                        /* translators: number of match rows backfilled */
                        __( 'Se actualizaron %d filas de partidos con nombres de equipos.', 'entre-redes-prode' ),
                        $backfillCount
                    );
                    $backfillNoticeType = 'success';
                } else {
                    $backfillNotice     = __( 'Todas las filas ya tenían nombres de equipos. No se realizaron cambios.', 'entre-redes-prode' );
                    $backfillNoticeType = 'info';
                }
            } elseif ( $backfillKey === 'error' ) {
                $backfillNotice     = __( 'Error al actualizar los nombres de equipos. Revisá los logs del servidor.', 'entre-redes-prode' );
                $backfillNoticeType = 'error';
            }
        }

        $adminUrl = admin_url( 'admin.php?page=prode-settings' );

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php if ( $errors ) : ?>
            <div class="notice notice-error">
                <p><strong><?php esc_html_e( 'No se pudo guardar la configuración. Corregí los siguientes errores:', 'entre-redes-prode' ); ?></strong></p>
                <ul>
                    <?php foreach ( $errors as $err ) : ?>
                    <li><?php echo esc_html( $err ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php elseif ( $notice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $noticeType ); ?> is-dismissible">
                <p><?php echo esc_html( $notice ); ?></p>
            </div>
            <?php endif; ?>

            <?php if ( $seedNotice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $seedNoticeType ); ?> is-dismissible">
                <p><?php echo esc_html( $seedNotice ); ?></p>
            </div>
            <?php endif; ?>

            <?php if ( $repairNotice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $repairNoticeType ); ?> is-dismissible">
                <p><?php echo esc_html( $repairNotice ); ?></p>
            </div>
            <?php endif; ?>

            <?php if ( $backfillNotice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $backfillNoticeType ); ?> is-dismissible">
                <p><?php echo esc_html( $backfillNotice ); ?></p>
            </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( $adminUrl ); ?>">
                <?php wp_nonce_field( self::NONCE_SETTINGS, self::NONCE_FIELD ); ?>
                <input type="hidden" name="prode_action" value="save_settings">

                <h2><?php esc_html_e( 'Configuración del sistema', 'entre-redes-prode' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>

                    <?php foreach ( self::SETTING_LABELS as $key => $label ) :
                        $meta  = $settingsMeta[ $key ] ?? [];
                        $value = $submitted !== false ? ( $submitted[ $key ] ?? '' ) : ( $meta['setting_value'] ?? '' );
                    ?>
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr( $key ); ?>">
                                <?php echo esc_html( __( $label, 'entre-redes-prode' ) ); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="<?php echo esc_attr( $key ); ?>"
                                name="<?php echo esc_attr( $key ); ?>"
                                value="<?php echo esc_attr( (string) $value ); ?>"
                                class="regular-text"
                                min="<?php echo esc_attr( $key === 'prode_season_id' || $key === 'fecha_window_days' || $key === 'evaluator_cron_interval_minutes' ? '1' : '0' ); ?>"
                            >
                            <?php if ( ! empty( $meta['updated_at'] ) ) : ?>
                            <p class="description">
                                <?php
                                echo esc_html(
                                    sprintf(
                                        /* translators: 1: date-time, 2: WP user ID */
                                        __( 'Último cambio: %1$s (usuario WP %2$s)', 'entre-redes-prode' ),
                                        $meta['updated_at'],
                                        $meta['updated_by']
                                    )
                                );
                                ?>
                            </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php
                    // --- Google Client ID (CONF-02, CONF-03) ---
                    $googleVal      = $submitted !== false ? ( $submitted['prode_google_client_id'] ?? $providerOpts['google_client_id'] ) : $providerOpts['google_client_id'];
                    $googleConstant = $providerOpts['google_constant'];
                    ?>
                    <tr>
                        <th scope="row">
                            <label for="prode_google_client_id">
                                <?php esc_html_e( 'Google Client ID', 'entre-redes-prode' ); ?>
                            </label>
                        </th>
                        <td>
                            <?php if ( $googleConstant ) : ?>
                            <input type="text" id="prode_google_client_id" value="<?php echo esc_attr( (string) PRODE_GOOGLE_CLIENT_ID ); ?>" class="regular-text" disabled>
                            <p class="description"><?php esc_html_e( 'El valor está configurado mediante una constante PHP y no puede editarse desde aquí.', 'entre-redes-prode' ); ?></p>
                            <?php else : ?>
                            <input type="text" id="prode_google_client_id" name="prode_google_client_id" value="<?php echo esc_attr( (string) $googleVal ); ?>" class="regular-text">
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php
                    // --- Apple Audience (CONF-02, CONF-03) ---
                    $appleVal      = $submitted !== false ? ( $submitted['prode_apple_audience'] ?? $providerOpts['apple_audience'] ) : $providerOpts['apple_audience'];
                    $appleConstant = $providerOpts['apple_constant'];
                    ?>
                    <tr>
                        <th scope="row">
                            <label for="prode_apple_audience">
                                <?php esc_html_e( 'Audience de Apple', 'entre-redes-prode' ); ?>
                            </label>
                        </th>
                        <td>
                            <?php if ( $appleConstant ) : ?>
                            <input type="text" id="prode_apple_audience" value="<?php echo esc_attr( (string) PRODE_APPLE_AUDIENCE ); ?>" class="regular-text" disabled>
                            <p class="description"><?php esc_html_e( 'El valor está configurado mediante una constante PHP y no puede editarse desde aquí.', 'entre-redes-prode' ); ?></p>
                            <?php else : ?>
                            <input type="text" id="prode_apple_audience" name="prode_apple_audience" value="<?php echo esc_attr( (string) $appleVal ); ?>" class="regular-text">
                            <?php endif; ?>
                        </td>
                    </tr>

                    </tbody>
                </table>

                <?php submit_button( __( 'Guardar configuración', 'entre-redes-prode' ) ); ?>
            </form>

            <hr>

            <h2><?php esc_html_e( 'Información del sistema', 'entre-redes-prode' ); ?></h2>
            <table class="form-table" role="presentation">
                <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e( 'PRODE_TENANT_ID', 'entre-redes-prode' ); ?></th>
                    <td><?php echo esc_html( defined( 'PRODE_TENANT_ID' ) ? (string) PRODE_TENANT_ID : __( '(no definido)', 'entre-redes-prode' ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Versión de base de datos', 'entre-redes-prode' ); ?></th>
                    <td><?php echo esc_html( (string) get_option( 'prode_db_version', __( 'no disponible', 'entre-redes-prode' ) ) ); ?></td>
                </tr>
                </tbody>
            </table>

            <hr>

            <h2><?php esc_html_e( 'Estado de tareas programadas', 'entre-redes-prode' ); ?></h2>
            <table class="form-table" role="presentation">
                <tbody>
                <?php foreach ( self::CRON_HOOKS as $hook ) : ?>
                <tr>
                    <th scope="row"><code><?php echo esc_html( $hook ); ?></code></th>
                    <td>
                        <?php
                        $next = wp_next_scheduled( $hook );
                        if ( $next ) {
                            echo esc_html( gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' );
                        } else {
                            esc_html_e( 'No programado', 'entre-redes-prode' );
                        }
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <hr>

            <h2><?php esc_html_e( 'Operaciones', 'entre-redes-prode' ); ?></h2>
            <form method="post" action="<?php echo esc_url( $adminUrl ); ?>">
                <?php wp_nonce_field( self::NONCE_SEED, self::NONCE_SEED_FIELD ); ?>
                <input type="hidden" name="prode_action" value="seed_fecha">
                <p><?php esc_html_e( 'Crea la próxima fecha del prode basándose en los partidos programados de la temporada activa.', 'entre-redes-prode' ); ?></p>
                <?php submit_button( __( 'Crear fecha próxima', 'entre-redes-prode' ), 'secondary', 'submit', false ); ?>
            </form>

            <form method="post" action="<?php echo esc_url( $adminUrl ); ?>" style="margin-top:1.5em;">
                <?php wp_nonce_field( self::NONCE_REPAIR, self::NONCE_REPAIR_FIELD ); ?>
                <input type="hidden" name="prode_action" value="repair_names">
                <p><?php esc_html_e( 'Repara los nombres de usuarios que quedaron guardados como su email (por ejemplo, las cuentas de Apple que muestran una dirección @privaterelay.appleid.com). Toma el nombre real del padrón de jugadores según el DNI vinculado.', 'entre-redes-prode' ); ?></p>
                <?php submit_button( __( 'Reparar nombres', 'entre-redes-prode' ), 'secondary', 'submit', false ); ?>
            </form>

            <form method="post" action="<?php echo esc_url( $adminUrl ); ?>" style="margin-top:1.5em;">
                <?php wp_nonce_field( self::NONCE_BACKFILL, self::NONCE_BACKFILL_FIELD ); ?>
                <input type="hidden" name="prode_action" value="backfill_match_meta">
                <p><?php esc_html_e( 'Rellena los nombres de equipos (home_team / away_team) en los partidos del prode que todavía muestran "—" en la página de predicciones del administrador. Útil para fechas antiguas que se crearon antes de la versión 0.5.2.', 'entre-redes-prode' ); ?></p>
                <?php submit_button( __( 'Rellenar nombres de equipos', 'entre-redes-prode' ), 'secondary', 'submit', false ); ?>
            </form>

        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Private handlers
    // -------------------------------------------------------------------------

    private function handleSaveSettings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tenés permiso para realizar esta acción.', 'entre-redes-prode' ) );
        }

        check_admin_referer( self::NONCE_SETTINGS, self::NONCE_FIELD );

        [ 'clean' => $clean, 'errors' => $errors ] = SettingsValidator::validate( $_POST );

        $redirectBase = admin_url( 'admin.php?page=prode-settings' );

        if ( $errors ) {
            $errorsKey = 'prode_settings_errors_' . get_current_user_id();
            set_transient( $errorsKey, $errors, 60 );

            // Store submitted values so they survive the redirect.
            $submittedKey = 'prode_settings_submitted_' . get_current_user_id();
            set_transient( $submittedKey, $_POST, 60 );

            wp_safe_redirect( $redirectBase );
            exit;
        }

        $actorWpId = get_current_user_id();

        // Write prode_settings rows (5 fields, upsert for EDGE-06).
        $settingKeys = [
            'lock_hours_before',
            'lock_warning_hours_before',
            'fecha_window_days',
            'prode_season_id',
            'evaluator_cron_interval_minutes',
        ];

        foreach ( $settingKeys as $key ) {
            if ( array_key_exists( $key, $clean ) ) {
                $this->settingsRepo->upsertSetting( $key, (string) $clean[ $key ], $actorWpId );
            }
        }

        // Write provider options (skips constant-controlled ones — SettingsRepository::updateProviderOption D5).
        $providerKeys = [ 'prode_google_client_id', 'prode_apple_audience' ];
        foreach ( $providerKeys as $key ) {
            if ( array_key_exists( $key, $clean ) ) {
                $this->settingsRepo->updateProviderOption( $key, (string) $clean[ $key ] );
            }
        }

        wp_safe_redirect( add_query_arg( 'prode_settings_notice', 'saved', $redirectBase ) );
        exit;
    }

    private function handleSeedFecha(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tenés permiso para realizar esta acción.', 'entre-redes-prode' ) );
        }

        check_admin_referer( self::NONCE_SEED, self::NONCE_SEED_FIELD );

        $redirectBase = admin_url( 'admin.php?page=prode-settings' );

        try {
            $result = $this->seedService->execute();
        } catch ( \Throwable $e ) {
            wp_safe_redirect( add_query_arg( 'prode_seed_notice', 'error', $redirectBase ) );
            exit;
        }

        if ( $result['skipped'] ) {
            wp_safe_redirect( add_query_arg( 'prode_seed_notice', 'skipped', $redirectBase ) );
            exit;
        }

        if ( $result['reused'] ) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'prode_seed_notice' => 'reused',
                        'fecha_id'          => $result['fecha_id'],
                    ],
                    $redirectBase
                )
            );
            exit;
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'prode_seed_notice' => 'created',
                    'fecha_id'          => $result['fecha_id'],
                    'match_count'       => $result['match_count'],
                ],
                $redirectBase
            )
        );
        exit;
    }

    private function handleRepairNames(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tenés permiso para realizar esta acción.', 'entre-redes-prode' ) );
        }

        check_admin_referer( self::NONCE_REPAIR, self::NONCE_REPAIR_FIELD );

        $redirectBase = admin_url( 'admin.php?page=prode-settings' );

        try {
            $result = $this->repairService->run();
        } catch ( \Throwable $e ) {
            wp_safe_redirect( add_query_arg( 'prode_repair_notice', 'error', $redirectBase ) );
            exit;
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'prode_repair_notice' => 'done',
                    'repaired'            => $result['repaired'],
                    'scanned'             => $result['scanned'],
                ],
                $redirectBase
            )
        );
        exit;
    }

    private function handleBackfillMatchMeta(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tenés permiso para realizar esta acción.', 'entre-redes-prode' ) );
        }

        check_admin_referer( self::NONCE_BACKFILL, self::NONCE_BACKFILL_FIELD );

        $redirectBase = admin_url( 'admin.php?page=prode-settings' );

        try {
            $backfilled = $this->backfillService->run();
        } catch ( \Throwable $e ) {
            wp_safe_redirect( add_query_arg( 'prode_backfill_notice', 'error', $redirectBase ) );
            exit;
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'prode_backfill_notice' => 'done',
                    'backfilled'            => $backfilled,
                ],
                $redirectBase
            )
        );
        exit;
    }
}

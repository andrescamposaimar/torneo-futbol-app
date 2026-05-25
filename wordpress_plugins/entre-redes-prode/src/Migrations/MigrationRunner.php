<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Migrations;

/**
 * Version-aware migration runner.
 *
 * Compares the stored `prode_db_version` WP option against the current plugin
 * version constant. When they differ, runs InitialSchema::up() (which is safe
 * to call multiple times — dbDelta is idempotent).
 *
 * Also handles the one-time activation tasks that must not run on every
 * `plugins_loaded`:
 *   - RS256 key pair generation (if not already present).
 *   - DNI audit pepper generation (if not already present).
 *   - Writing PRODE_TENANT_ID into prode_settings.
 *   - Scheduling cron jobs.
 */
class MigrationRunner {

    private const DB_VERSION_OPTION = 'prode_db_version';

    public static function run(): void {
        $installed = get_option( self::DB_VERSION_OPTION, '0' );
        $current   = ENTRE_REDES_PRODE_VERSION;

        // Always run dbDelta on activation (it is safe to do so; noop if schema
        // already matches). On upgrades this picks up new columns/indexes.
        InitialSchema::up();

        if ( version_compare( (string) $installed, $current, '<' ) ) {
            self::generateRsaKeyPair();
            self::generateDniPepper();
            self::writeTenantId();
            self::scheduleCrons();

            update_option( self::DB_VERSION_OPTION, $current );
        }
    }

    // -------------------------------------------------------------------------
    // Key and pepper generation
    // -------------------------------------------------------------------------

    /**
     * Generates an RSA 2048-bit key pair on first activation.
     * Private key PEM is stored XOR-obfuscated with wp_salt().
     * Public key PEM is stored as-is (it is public by design).
     *
     * Subsequent activations are a no-op if the keys already exist.
     */
    private static function generateRsaKeyPair(): void {
        if ( get_option( 'prode_rsa_private_key' ) ) {
            return; // Already generated.
        }

        if ( ! function_exists( 'openssl_pkey_new' ) ) {
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-error"><p>';
                esc_html_e(
                    'Entre Redes Prode: OpenSSL extension is required but not available. JWT signing will not work.',
                    'entre-redes-prode'
                );
                echo '</p></div>';
            } );
            return;
        }

        $key = openssl_pkey_new( [
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ] );

        if ( ! $key ) {
            return;
        }

        openssl_pkey_export( $key, $private_pem );
        $details    = openssl_pkey_get_details( $key );
        $public_pem = $details['key'] ?? '';

        // Obfuscate the private key at rest with a salt-based XOR.
        // This is not encryption, but it prevents casual plaintext reads from
        // the options table or a DB dump.
        $obfuscated = self::xorWithSalt( $private_pem );

        update_option( 'prode_rsa_private_key', base64_encode( $obfuscated ), false );
        update_option( 'prode_rsa_public_key', $public_pem, false );

        // Store the key ID (kid) for JWKS.
        update_option( 'prode_rsa_key_id', wp_generate_uuid4(), false );
    }

    /**
     * Generates a 32-byte (64 hex chars) random pepper for DNI hashing.
     * Stored in WP options. Subsequent activations are a no-op.
     */
    private static function generateDniPepper(): void {
        if ( get_option( 'prode_audit_dni_pepper' ) ) {
            return;
        }

        try {
            $pepper = bin2hex( random_bytes( 32 ) );
        } catch ( \Exception $e ) {
            $pepper = wp_generate_password( 64, true, true );
        }

        update_option( 'prode_audit_dni_pepper', $pepper, false );
    }

    /**
     * Writes the PRODE_TENANT_ID constant into prode_settings so the DB value
     * can be cross-checked at runtime without relying solely on wp-config.php.
     */
    private static function writeTenantId(): void {
        if ( ! defined( 'PRODE_TENANT_ID' ) ) {
            return;
        }

        global $wpdb;
        $p   = $wpdb->prefix;
        $now = current_time( 'mysql' );

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "INSERT INTO {$p}prode_settings (setting_key, setting_value, updated_at)
                 VALUES ('tenant_id', %s, %s)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)",
                (string) PRODE_TENANT_ID,
                $now
            )
        );
    }

    // -------------------------------------------------------------------------
    // Cron scheduling
    // -------------------------------------------------------------------------

    private static function scheduleCrons(): void {
        $crons = [
            'prode_evaluate_matches_cron'       => 'every_5_minutes',
            'prode_notify_lock_approaching_cron' => 'every_15_minutes',
            'prode_create_new_fecha_cron'        => 'daily',
        ];

        // Register custom intervals if not already registered.
        add_filter( 'cron_schedules', function ( array $schedules ) {
            if ( ! isset( $schedules['every_5_minutes'] ) ) {
                $schedules['every_5_minutes'] = [
                    'interval' => 5 * MINUTE_IN_SECONDS,
                    'display'  => __( 'Every 5 minutes', 'entre-redes-prode' ),
                ];
            }
            if ( ! isset( $schedules['every_15_minutes'] ) ) {
                $schedules['every_15_minutes'] = [
                    'interval' => 15 * MINUTE_IN_SECONDS,
                    'display'  => __( 'Every 15 minutes', 'entre-redes-prode' ),
                ];
            }
            return $schedules;
        } );

        foreach ( $crons as $hook => $recurrence ) {
            if ( ! wp_next_scheduled( $hook ) ) {
                wp_schedule_event( time(), $recurrence, $hook );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function xorWithSalt( string $data ): string {
        $salt   = wp_salt( 'auth' );
        $result = '';
        $len    = strlen( $salt );
        for ( $i = 0, $iMax = strlen( $data ); $i < $iMax; $i++ ) {
            $result .= $data[ $i ] ^ $salt[ $i % $len ];
        }
        return $result;
    }
}

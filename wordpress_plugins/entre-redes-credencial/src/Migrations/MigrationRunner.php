<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Migrations;

use EntreRedes\Credencial\Observability\EventLog;

/**
 * Version-aware migration runner — structure mirrored from
 * entre-redes-cambios/src/Migrations/MigrationRunner.php.
 *
 * Compares the stored `credencial_db_version` WP option against the current
 * plugin version constant. dbDelta-backed InitialSchema::up() is always safe
 * to re-run, so it runs on every activation regardless of the version gate.
 * The version gate exists only to fence off future one-time upgrade tasks
 * (none in slice 1a).
 *
 * *** RUNTIME LIMITS CHECK (design D9b) ***
 * The photo upload pipeline (a later slice — Photo\PhotoReencoder) decodes a
 * base64 image up to 3 MB, then re-encodes it (exif rotate, fit 1080, JPEG
 * q85), which is memory- and request-size-hungry. A hosting environment with
 * a low `memory_limit`, `upload_max_filesize`, or `post_max_size` would make
 * every upload fail with an opaque fatal instead of this plugin's own
 * precise `insufficient_memory` / `image_too_large` codes. checkRuntimeLimits()
 * runs once per activation, after migrations, and surfaces this loudly (an
 * EventLog event plus an admin_notice) instead of leaving it to be
 * discovered as a confusing 500 in production — same tolerant pattern as
 * MigrationRunner::checkStorageEngine() in entre-redes-cambios: it NEVER
 * fails activation over a limit it does not control.
 */
class MigrationRunner {

    private const DB_VERSION_OPTION = 'credencial_db_version';

    /**
     * Conservative floors for the upload pipeline described above. Not
     * prescribed by the design doc verbatim — chosen so a 3 MB decoded photo
     * (≈4 MB base64-encoded, plus JSON envelope overhead) fits comfortably
     * under `post_max_size` / `upload_max_filesize`, and so PHP has enough
     * headroom to hold the decoded bytes, the re-encoded JPEG, and GD/Imagick
     * working memory simultaneously without exhausting `memory_limit`.
     */
    private const MIN_MEMORY_LIMIT_BYTES = 128 * 1024 * 1024; // 128M
    private const MIN_UPLOAD_BYTES       = 6 * 1024 * 1024;   // 6M

    /**
     * Extra (slice 2b): the upload pipeline's OWN dependencies beyond ini
     * limits — PhotoValidator needs `fileinfo` (finfo) and `exif` (the
     * dimension/orientation check runs BEFORE any GD decode); GdPhotoReencoder
     * needs `gd` for the decode/rotate/re-encode itself. Verified present on
     * `gd` in production (bundled 2.1.0); `exif`/`fileinfo` were not
     * independently confirmed there — see task 4.7.
     */
    private const REQUIRED_EXTENSIONS = [ 'gd', 'exif', 'fileinfo' ];

    /**
     * The reserved one-time-upgrade slot for design rev 9's migration
     * (decision `credencial/foto-desde-featured-image`): the photo identity
     * mechanism changed from a sha256 hash to the WordPress attachment id, so
     * the retired `_credencial_sha256` meta must be purged once. Gated on
     * this LITERAL version string, never on ENTRE_REDES_CREDENCIAL_VERSION —
     * a future 0.3.0 bump must not re-run this specific cleanup.
     */
    private const PHOTO_IDENTITY_MIGRATION_VERSION = '0.2.0';

    public static function run( EventLog $eventLog ): void {
        $installed = get_option( self::DB_VERSION_OPTION, '0' );
        $current   = ENTRE_REDES_CREDENCIAL_VERSION;

        // Always run dbDelta on activation — safe to re-run, no-op if the
        // schema already matches. On upgrades this picks up new columns.
        InitialSchema::up();

        if ( version_compare( (string) $installed, self::PHOTO_IDENTITY_MIGRATION_VERSION, '<' ) ) {
            // One-time: the sha256-based photo identity is retired (design
            // rev 9) — nothing reads or writes this meta any more. Idempotent
            // regardless of how many posts have it, or whether it exists at
            // all (a no-op delete is not an error).
            delete_post_meta_by_key( '_credencial_sha256' );
        }

        if ( version_compare( (string) $installed, $current, '<' ) ) {
            update_option( self::DB_VERSION_OPTION, $current );
        }

        self::generateCodeSecret();
        self::checkRuntimeLimits( $eventLog );
    }

    /**
     * Generates and persists `credencial_code_secret` — the HMAC key design
     * D4's Code\RotatingCode::seedFor() derives every credential's
     * `code_seed` from — exactly once. Subsequent activations are a no-op,
     * same "generate once, never rotate silently" pattern as
     * entre-redes-prode's own MigrationRunner::generateDniPepper(): rotating
     * this secret would invalidate every already-cached client-side seed at
     * once, which is an operational decision, never an accidental side
     * effect of reactivating the plugin.
     */
    private static function generateCodeSecret(): void {
        if ( get_option( 'credencial_code_secret' ) ) {
            return;
        }

        try {
            $secret = bin2hex( random_bytes( 32 ) );
        } catch ( \Exception $e ) {
            $secret = wp_generate_password( 64, true, true );
        }

        update_option( 'credencial_code_secret', $secret, false );
    }

    /**
     * Reads `memory_limit`, `upload_max_filesize`, and `post_max_size` via
     * the injected reader (defaults to the real `ini_get()`) and reports any
     * that fall below this plugin's own minimums.
     *
     * TOLERANT AND NEVER THROWS, same discipline as
     * entre-redes-cambios::checkStorageEngine(): this is a diagnostic, never
     * a gate. An unlimited setting (`-1`, PHP's own convention for
     * `memory_limit`) is always treated as satisfying every floor.
     *
     * Public (not private) so task 4.7 — "run checkRuntimeLimits() against
     * the real hosting environment post-deploy" — can invoke it directly
     * from WP-CLI or an admin action, not only from activation.
     *
     * @param null|callable(string): string $iniGetFn Defaults to a thin
     *        wrapper around the real `ini_get()`. Tests inject a fake reader
     *        so every branch (low memory, low upload size, unlimited) is
     *        exercised deterministically regardless of the PHP process
     *        actually running the suite.
     * @param null|callable(string): bool $extensionLoadedFn Defaults to a
     *        thin wrapper around the real `extension_loaded()`. Tests inject
     *        a fake reader so a missing `gd`/`exif`/`fileinfo` can be
     *        exercised deterministically regardless of what this environment
     *        actually has installed.
     *
     * @return string[] The offending settings/extensions, human-readable —
     *         empty when every limit clears its floor and every required
     *         extension is loaded.
     */
    public static function checkRuntimeLimits( EventLog $eventLog, ?callable $iniGetFn = null, ?callable $extensionLoadedFn = null ): array {
        $read = $iniGetFn ?? static function ( string $key ): string {
            $value = ini_get( $key );
            return false === $value ? '' : $value;
        };

        $hasExtension = $extensionLoadedFn ?? static fn ( string $extension ): bool => extension_loaded( $extension );

        $problems = [];

        $memoryLimit = self::parseIniBytes( $read( 'memory_limit' ) );
        if ( -1 !== $memoryLimit && $memoryLimit < self::MIN_MEMORY_LIMIT_BYTES ) {
            $problems[] = sprintf(
                'memory_limit=%s (mínimo recomendado 128M)',
                $read( 'memory_limit' )
            );
        }

        $uploadMaxFilesize = self::parseIniBytes( $read( 'upload_max_filesize' ) );
        if ( -1 !== $uploadMaxFilesize && $uploadMaxFilesize < self::MIN_UPLOAD_BYTES ) {
            $problems[] = sprintf(
                'upload_max_filesize=%s (mínimo recomendado 6M)',
                $read( 'upload_max_filesize' )
            );
        }

        $postMaxSize = self::parseIniBytes( $read( 'post_max_size' ) );
        if ( -1 !== $postMaxSize && $postMaxSize < self::MIN_UPLOAD_BYTES ) {
            $problems[] = sprintf(
                'post_max_size=%s (mínimo recomendado 6M)',
                $read( 'post_max_size' )
            );
        }

        foreach ( self::REQUIRED_EXTENSIONS as $extension ) {
            if ( ! $hasExtension( $extension ) ) {
                $problems[] = sprintf(
                    'extensión PHP faltante: %s (requerida por la subida de fotos)',
                    $extension
                );
            }
        }

        if ( empty( $problems ) ) {
            return [];
        }

        $eventLog->record( 'runtime.limits_low', [ 'problems' => $problems ] );

        add_action( 'admin_notices', static function () use ( $problems ): void {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html(
                    'entre-redes-credencial: los siguientes límites del hosting están por debajo de lo recomendado '
                    . 'para la subida de fotos: ' . implode( ', ', $problems ) . '. '
                    . 'La subida puede fallar con un error genérico en lugar del código preciso que este plugin '
                    . 'define. Contactar al hosting para ajustar estos valores antes de habilitar la funcionalidad.'
                )
            );
        } );

        return $problems;
    }

    /**
     * Parses a php.ini shorthand size ('128M', '2G', '512K', '-1', '') into
     * bytes. Returns -1 for "unlimited" (PHP's own convention for
     * memory_limit), 0 for an empty/unparseable value.
     */
    private static function parseIniBytes( string $value ): int {
        $value = trim( $value );

        if ( '' === $value ) {
            return 0;
        }

        if ( '-1' === $value ) {
            return -1;
        }

        if ( ! preg_match( '/^(\d+)\s*([KMG]?)$/i', $value, $matches ) ) {
            return 0;
        }

        $number = (int) $matches[1];
        $unit   = strtoupper( $matches[2] );

        return match ( $unit ) {
            'G'     => $number * 1024 * 1024 * 1024,
            'M'     => $number * 1024 * 1024,
            'K'     => $number * 1024,
            default => $number,
        };
    }
}

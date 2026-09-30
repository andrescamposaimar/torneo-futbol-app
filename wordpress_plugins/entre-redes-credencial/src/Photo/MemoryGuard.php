<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Photo;

use EntreRedes\Credencial\Photo\Exception\InsufficientMemoryException;

/**
 * Third stage of the upload pipeline (design D9): estimates the memory a
 * FULL GD decode of these already-known dimensions would need, and refuses
 * BEFORE attempting it if that would not fit under `memory_limit` — turning
 * what would otherwise be an opaque PHP fatal (allowed memory size
 * exhausted) into this plugin's own precise 500 `insufficient_memory` +
 * logged event (Rest\PhotoUploadController).
 *
 * Same injectable-reader convention as
 * Migrations\MigrationRunner::checkRuntimeLimits(): tests fake both
 * `ini_get('memory_limit')` and `memory_get_usage()` so every branch (low
 * limit, large image, unlimited) is exercised deterministically regardless
 * of how much memory the process actually running the suite happens to have.
 */
final class MemoryGuard {

    /**
     * GD decodes into a truecolor bitmap — 4 bytes per pixel (RGBA) — plus
     * working buffers for the orientation-rotate and fit-resize steps that
     * follow in the SAME request (Photo\GdPhotoReencoder). This safety
     * factor is a deliberately conservative estimate, not a measured
     * constant: correctness here means "never let a request through that
     * will actually exhaust memory", so overestimating costs nothing while
     * underestimating defeats the whole guard.
     */
    private const BYTES_PER_PIXEL = 4;
    private const SAFETY_FACTOR   = 2.5;

    /**
     * @param null|callable(): string $memoryLimitFn Defaults to a thin
     *        wrapper around the real `ini_get('memory_limit')`.
     * @param null|callable(): int $memoryUsageFn Defaults to a thin wrapper
     *        around the real `memory_get_usage(true)`.
     *
     * @throws InsufficientMemoryException When the estimated decode would not
     *         fit in the memory remaining under the limit.
     */
    public function ensureEnoughMemoryFor(
        int $width,
        int $height,
        ?callable $memoryLimitFn = null,
        ?callable $memoryUsageFn = null
    ): void {
        $limitFn = $memoryLimitFn ?? static fn (): string => (string) ini_get( 'memory_limit' );
        $usageFn = $memoryUsageFn ?? static fn (): int => memory_get_usage( true );

        $limitBytes = self::parseIniBytes( $limitFn() );

        if ( -1 === $limitBytes ) {
            return; // "-1" is PHP's own convention for "unlimited".
        }

        $estimatedBytes = (int) ( $width * $height * self::BYTES_PER_PIXEL * self::SAFETY_FACTOR );
        $availableBytes = $limitBytes - $usageFn();

        if ( $estimatedBytes > $availableBytes ) {
            throw new InsufficientMemoryException();
        }
    }

    /** Parses a php.ini shorthand size ('128M', '2G', '512K', '-1', '') into bytes. */
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

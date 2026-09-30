<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Observability;

/**
 * Production EventLog: fires a WordPress action for anyone to listen to, and
 * additionally writes to `error_log()` when debugging is active. Copied and
 * adapted from entre-redes-cambios/src/Observability/WpEventLog.php — see
 * that class's docblock for the full reasoning behind the injected debug
 * predicate and the never-propagates guarantee; both are unchanged here.
 */
class WpEventLog implements EventLog {

    /** @var callable(): bool */
    private $isDebugActive;

    /**
     * @param null|callable(): bool $isDebugActive Defaults to
     *        `defined('WP_DEBUG') && WP_DEBUG` — the real WordPress debug
     *        flag. Override only from tests.
     */
    public function __construct( ?callable $isDebugActive = null ) {
        $this->isDebugActive = $isDebugActive ?? static function (): bool {
            return defined( 'WP_DEBUG' ) && WP_DEBUG;
        };
    }

    /**
     * NEVER throws — see class docblock. A failure here is swallowed and
     * reported through `error_log()` alone, as a last resort, rather than
     * allowed to take down the caller's already-successful write.
     */
    public function record( string $evento, array $contexto ): void {
        try {
            do_action( 'entre_redes_credencial_event', $evento, $contexto );

            if ( ( $this->isDebugActive )() ) {
                error_log( sprintf( '[entre-redes-credencial] %s %s', $evento, json_encode( $contexto ) ) );
            }
        } catch ( \Throwable $e ) {
            // Deliberately NOT re-entering do_action() — that is the thing
            // that just failed. error_log() is the one channel this class
            // never depends on succeeding, so it is the only safe fallback.
            error_log( sprintf(
                '[entre-redes-credencial] EventLog listener failed while recording %s: %s',
                $evento,
                $e->getMessage()
            ) );
        }
    }
}

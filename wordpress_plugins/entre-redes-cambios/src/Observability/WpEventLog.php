<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Observability;

/**
 * Production EventLog: fires a WordPress action for anyone to listen to, and
 * additionally writes to `error_log()` when debugging is active.
 *
 * `do_action( 'entre_redes_cambios_event', $evento, $contexto )` runs
 * unconditionally, on every record() call — this is what lets a future admin
 * screen, a Slack notifier, or anything else hook into these events without
 * this class knowing about any of them.
 *
 * The `error_log()` write is intentionally gated behind debug mode, and
 * intentionally ONLY `error_log()` — no custom file, no custom format. This
 * plugin ships to a shared hosting WordPress install; the operator's own
 * `WP_DEBUG`/`WP_DEBUG_LOG` configuration already decides where PHP's error
 * log ends up, and duplicating that decision here would just be a second,
 * competing place to configure logging.
 *
 * WHY THE DEBUG CHECK IS INJECTED, NOT A HARDCODED `defined('WP_DEBUG') &&
 * WP_DEBUG`: PHP constants cannot be redefined or unset once set, and this
 * plugin's own PHPUnit suite (see tests/wp-shim.php) runs every test in one
 * process — there is no way to exercise both "WP_DEBUG on" and "WP_DEBUG
 * off" behavior in the same run if the check reads the constant directly.
 * The constructor therefore accepts an optional `$isDebugActive` predicate —
 * following this codebase's "dependencies injected" convention — that
 * defaults to reading the real constant in production, but lets
 * WpEventLogTest supply a fake predicate for each branch deterministically.
 * This changes nothing about WHERE the log line goes; it only makes the
 * decision of WHETHER to write it observable in tests.
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

    public function record( string $evento, array $contexto ): void {
        do_action( 'entre_redes_cambios_event', $evento, $contexto );

        if ( ( $this->isDebugActive )() ) {
            error_log( sprintf( '[entre-redes-cambios] %s %s', $evento, json_encode( $contexto ) ) );
        }
    }
}

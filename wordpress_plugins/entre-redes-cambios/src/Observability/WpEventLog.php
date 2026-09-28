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
 *
 * *** record() NEVER PROPAGATES — LOGGING IS BEST-EFFORT, ON PURPOSE ***
 * `do_action()` runs every listener any OTHER plugin registered on
 * `entre_redes_cambios_event` — code this plugin does not control and
 * cannot audit. If one of those listeners throws (a bug in an unrelated
 * plugin, a Slack-notifier integration whose HTTP call fails), that
 * exception would otherwise surface to WHOEVER called `record()` — which,
 * for every write in this plugin, is AFTER the write already committed. A
 * caller would then see an exception from an operation that already
 * succeeded, and — worse, in `Dictamen\DictamenPipeline::evaluate()` — a
 * `record()` call inside a `catch` block that itself throws would REPLACE
 * the original exception being diagnosed, destroying the one piece of
 * information that method exists to preserve.
 *
 * A log that can take down the operation it is merely recording is worse
 * than no log at all — an operator who reads "the change failed" and
 * retries a real, already-applied change on a Friday night is a strictly
 * worse outcome than a missing log line. `record()` therefore wraps its
 * entire body in `try`/`catch (\Throwable)`; if anything throws — the
 * `do_action()` call, or the `error_log()` write itself — this method
 * writes a last-resort line straight to `error_log()` (never back through
 * `do_action()`, which is the thing that just failed) and returns normally,
 * exactly as if nothing had gone wrong from the CALLER's point of view.
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
     * NEVER throws — see class docblock, "record() NEVER PROPAGATES". A
     * failure here is swallowed and reported through `error_log()` alone, as
     * a last resort, rather than allowed to take down the caller's already-
     * successful write.
     */
    public function record( string $evento, array $contexto ): void {
        try {
            do_action( 'entre_redes_cambios_event', $evento, $contexto );

            if ( ( $this->isDebugActive )() ) {
                error_log( sprintf( '[entre-redes-cambios] %s %s', $evento, json_encode( $contexto ) ) );
            }
        } catch ( \Throwable $e ) {
            // Deliberately NOT re-entering do_action() — that is the thing
            // that just failed. error_log() is the one channel this class
            // never depends on succeeding, so it is the only safe fallback.
            error_log( sprintf(
                '[entre-redes-cambios] EventLog listener failed while recording %s: %s',
                $evento,
                $e->getMessage()
            ) );
        }
    }
}

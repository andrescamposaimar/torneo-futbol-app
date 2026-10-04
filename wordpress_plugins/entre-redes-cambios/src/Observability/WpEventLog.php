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
 *
 * *** THE `entre_redes_cambios_ultimo_error` OPTION — A DIAGNOSTIC AID, NOT
 * PERMANENT TELEMETRY ***
 * This plugin ships to shared hosting where the operator has NO access to
 * any PHP error log (none under `public_html`, none exposed in the hosting
 * panel) and `error_log()` above only fires when `WP_DEBUG` is active in
 * production — which it normally is not. Without this, a 500 from this
 * plugin is completely opaque: the operator sees `error_interno` on the
 * device and has nothing else to look at. `record()` therefore ALSO
 * persists the most recent FAILURE event (one whose `$evento` contains
 * `'fallid'` — this plugin's own naming convention for a failure code, e.g.
 * `lectura.fallida`, `escritura.fallida`, every `rest.*_fallida`; see this
 * class's own docblock intro for the convention) into a single WordPress
 * option, `entre_redes_cambios_ultimo_error`, with autoload OFF (it is read
 * once, rarely, by an operator — never on every page load).
 *
 * Deliberately excludes events like `rest.autorizacion_denegada` (an
 * expected 403, not an unexpected failure) — matching on `'fallid'` in the
 * event CODE, rather than on the presence of an `excepcion`/`mensaje` key in
 * `$contexto`, keeps that distinction: an authorization denial also carries
 * `excepcion` (see `Rest\PlazasController`'s catch blocks) but is routed
 * through a DIFFERENT event code precisely because it is not a failure this
 * diagnostic aid needs to surface.
 *
 * ONLY the latest is kept (a plain overwrite, via `update_option()`) — never
 * appended — so this can never grow unbounded; it is a single snapshot, not
 * a log table. The stored payload is deliberately narrow: the event code,
 * the exception class (from `$contexto['excepcion']` when present), a
 * message (from `$contexto['mensaje']`, falling back to
 * `$contexto['last_error']` for the `Support\ChecksReads` / `OpensTransactions`
 * convention, truncated to a sane length in case a wpdb error message ever
 * embeds a long SQL string), and a UTC timestamp. It NEVER stores the rest
 * of `$contexto` — no JWT, no bearer token, no request headers, no player
 * personal data, nothing beyond those four fields, regardless of what a
 * future call site happens to pass.
 *
 * To read it: `SELECT option_value FROM wp_options WHERE option_name =
 * 'entre_redes_cambios_ultimo_error'` via phpMyAdmin — the one tool this
 * operator does have.
 *
 * This persistence ALSO runs inside `record()`'s own `try`/`catch`, for the
 * exact same reason the `do_action()`/`error_log()` calls do: a failure
 * writing this option (a WordPress bug, a read-only `wp_options` row) must
 * never propagate back to the caller either.
 */
class WpEventLog implements EventLog {

    private const OPTION_ULTIMO_ERROR = 'entre_redes_cambios_ultimo_error';

    /**
     * Sane ceiling for the stored message — a wpdb `last_error` or exception
     * message is normally a short sentence, but this guards against an
     * unusually long one (e.g. one that happens to embed a SQL fragment)
     * bloating the option.
     */
    private const MAX_MENSAJE_LENGTH = 500;

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

            $this->recordUltimoErrorSiAplica( $evento, $contexto );
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

    /**
     * Persists $evento/$contexto into the `entre_redes_cambios_ultimo_error`
     * option when, and only when, $evento looks like a failure — see class
     * docblock, "THE entre_redes_cambios_ultimo_error OPTION", for the exact
     * matching rule and why `?seccion=padron_completo`-style denials are
     * deliberately excluded. A no-op for every other event — this is the
     * majority of calls (business events like `plaza.abierta`), so this
     * check is a cheap `str_contains()` before anything else runs.
     *
     * @param array<string, mixed> $contexto
     */
    private function recordUltimoErrorSiAplica( string $evento, array $contexto ): void {
        if ( ! str_contains( $evento, 'fallid' ) ) {
            return;
        }

        $clase = isset( $contexto['excepcion'] ) && is_string( $contexto['excepcion'] )
            ? $contexto['excepcion']
            : null;

        $mensajeCrudo = $contexto['mensaje'] ?? ( $contexto['last_error'] ?? '' );
        $mensaje      = is_string( $mensajeCrudo ) ? $mensajeCrudo : '';

        update_option(
            self::OPTION_ULTIMO_ERROR,
            [
                'evento'    => $evento,
                'clase'     => $clase,
                'mensaje'   => mb_substr( $mensaje, 0, self::MAX_MENSAJE_LENGTH ),
                'timestamp' => gmdate( 'Y-m-d H:i:s' ),
            ],
            false
        );
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Calendario;

use EntreRedes\Cambios\Observability\EventLog;

/**
 * The ONE writer of `cambios_settings` (0.1.23). Deliberately a separate
 * class from `Settings`, which its own docblock declares "a plain
 * cambios_settings READER": that class's value is a per-setting audit of
 * which direction is safe when a read fails, and mixing writes into it would
 * blur a contract several rules depend on.
 *
 * *** EVERY WRITE IS AUDITED, BECAUSE THESE ROWS DECIDE WHO MAY DO WHAT ***
 * `plazo_*` decides whether the request window is open at all;
 * `exencion_arco_activa` relaxes a techo check. A silent change to either
 * looks exactly like a bug in the rules to everyone downstream, so every
 * write records an event carrying the OLD and NEW values — the only way to
 * answer "since when is this like that" later. A FAILED write records a
 * `fallid`-named event, this plugin's convention for reaching the durable
 * `entre_redes_cambios_ultimo_error` option (see `Observability\WpEventLog`),
 * because on this shared host there is no PHP log to fall back on.
 *
 * *** WHY IT REPORTS FAILURE INSTEAD OF THROWING ***
 * `guardarMuchos()` answers with the keys it could not write rather than
 * throwing, so the settings screen can tell the operator exactly which rows
 * landed. A thrown exception would leave them with a red screen and no idea
 * how much of their change took effect — and these rows are written one by
 * one, so a partial result is a real state, not a theoretical one.
 */
final class SettingsWriter {

    private \wpdb $wpdb;
    private EventLog $eventLog;

    public function __construct( \wpdb $wpdb, EventLog $eventLog ) {
        $this->wpdb     = $wpdb;
        $this->eventLog = $eventLog;
    }

    /**
     * Writes several settings, reporting which ones failed.
     *
     * Rows whose stored value already equals the incoming one are SKIPPED,
     * not rewritten: it keeps `settings.modificado` meaning "somebody
     * actually changed this" instead of "somebody opened the form and
     * pressed Guardar", which is what makes the audit trail worth reading.
     *
     * @param array<string, string> $valores setting_key => setting_value
     * @param string                $now     UTC 'Y-m-d H:i:s'
     * @return array{escritas: string[], fallidas: string[]}
     */
    public function guardarMuchos( array $valores, string $now ): array {
        $escritas = [];
        $fallidas = [];

        foreach ( $valores as $clave => $valor ) {
            $anterior = $this->leerCrudo( $clave );

            if ( null !== $anterior && $anterior === $valor ) {
                continue;
            }

            if ( $this->guardarUno( $clave, $valor, $now, null !== $anterior ) ) {
                $escritas[] = $clave;

                $this->eventLog->record( 'settings.modificado', [
                    'setting_key' => $clave,
                    'anterior'    => $anterior,
                    'nuevo'       => $valor,
                    'wp_user'     => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
                ] );

                continue;
            }

            $fallidas[] = $clave;

            $this->eventLog->record( 'settings.escritura_fallida', [
                'setting_key' => $clave,
                'nuevo'       => $valor,
                'last_error'  => (string) ( $this->wpdb->last_error ?? '' ),
            ] );
        }

        return [ 'escritas' => $escritas, 'fallidas' => $fallidas ];
    }

    /**
     * UPDATE when the row is there, INSERT when it is not — deliberately NOT
     * `ON DUPLICATE KEY UPDATE`.
     *
     * *** WHY NOT THE ONE-STATEMENT UPSERT ***
     * That syntax is MySQL-only, and the SQLite test shim rewrites
     * `INSERT IGNORE` and `START TRANSACTION` but NOT `ON DUPLICATE KEY`
     * (see `tests/wp-shim.php`'s `translateForSqlite()`). The statement would
     * therefore throw under PDO, be swallowed into `false`, and every test
     * would exercise the failure branch while production exercised the happy
     * one — the shim telling a comfortable lie about code that was never
     * really tested. A row added in a later version may genuinely have no
     * row yet (the seed uses `INSERT IGNORE`), which is why the INSERT arm is
     * needed at all, and why the caller passes what it already learned from
     * its own read instead of this method guessing again.
     *
     * Two admins saving at the same instant could both read "absent" and
     * both INSERT; the loser hits the `setting_key` primary key and is
     * reported as failed, which is the honest outcome for a single-operator
     * screen — not a lock worth holding.
     *
     * @param bool $existe Whether a row for $clave is already stored, as the
     *        caller's own `leerCrudo()` found it.
     */
    private function guardarUno( string $clave, string $valor, string $now, bool $existe ): bool {
        $p = $this->wpdb->prefix;

        $sql = $existe
            ? $this->wpdb->prepare(
                "UPDATE {$p}cambios_settings SET setting_value = %s, updated_at = %s WHERE setting_key = %s",
                $valor,
                $now,
                $clave
            )
            : $this->wpdb->prepare(
                "INSERT INTO {$p}cambios_settings (setting_key, setting_value, updated_at) VALUES (%s, %s, %s)",
                $clave,
                $valor,
                $now
            );

        $resultado = $this->wpdb->query( $sql );

        return false !== $resultado && '' === (string) ( $this->wpdb->last_error ?? '' );
    }

    /**
     * The stored string, or null when there is genuinely NO ROW. Unlike
     * `Settings`'s readers this does NOT fall back to a seeded default: the
     * caller is comparing against what is physically there, and a default
     * standing in for an absent row would make a first-time write look like
     * a no-op and skip it.
     *
     * *** WHY get_row() AND NOT get_var() ***
     * `wpdb::get_var()` returns `null` for an EMPTY STRING, not just for a
     * missing row — straight from core:
     *
     *     return ( isset( $values[ $x ] ) && '' !== $values[ $x ] ) ? $values[ $x ] : null;
     *
     * so through `get_var()` "stored but empty" and "no such row" are the
     * same answer. This method's `null` decides UPDATE vs INSERT, and reading
     * an empty value as "no row" sent an INSERT at a row that existed,
     * straight into the primary key. It shipped in 0.1.25 and broke exactly
     * one setting — `notificacion_nueva_destino`, the only one seeded with
     * an empty value — with "Algunos valores no se pudieron guardar".
     *
     * `get_row()` has no such quirk: it hands back the row as it is, so an
     * empty `setting_value` comes back as `''` and the absence of a row is
     * the only thing that produces `null`.
     *
     * The test shim used to return `''` where core returns `null`, which is
     * why 1059 tests passed over this. It now mirrors core.
     */
    private function leerCrudo( string $clave ): ?string {
        $p = $this->wpdb->prefix;

        $fila = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT setting_value FROM {$p}cambios_settings WHERE setting_key = %s LIMIT 1", $clave ),
            ARRAY_A
        );

        if ( ! is_array( $fila ) || ! array_key_exists( 'setting_value', $fila ) ) {
            return null;
        }

        return (string) $fila['setting_value'];
    }

    /**
     * The canonical JSON an offset is stored as — one place, so the screen
     * cannot invent a second encoding that `Settings::readOffset()` then
     * fails to parse.
     *
     * @return string e.g. `{"days":-6,"time":"00:00:00"}`
     */
    public static function encodeOffset( int $days, string $time ): string {
        return (string) wp_json_encode( [ 'days' => $days, 'time' => $time ] );
    }
}

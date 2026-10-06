<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Calendario;

use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\EventLog;

/**
 * Typed accessor for Cambios operator settings stored in cambios_settings.
 *
 * Reads the key/value table for each getter and falls back to
 * InitialSchema::SEED_DEFAULTS when the row is absent — never a separate
 * hardcoded literal, so the fallback and the seed can never silently drift
 * apart (mirrors entre-redes-prode's Fecha\Settings).
 *
 * *** A FAILED READ IS NOT THE SAME FACT AS "THE ROW IS ABSENT" ***
 * `readString()`'s underlying `$wpdb->get_var()` returns `null` both when no
 * row matches `setting_key` AND when the query itself fails at the wpdb
 * level (a transient DB hiccup) — those are different facts, and `readInt()`
 * / `readBool()` / `readOffset()` all build on `readString()`, so every
 * getter in this class inherits whichever behaviour it chooses here.
 * `readStringResult()` is what tells the two apart (via `$wpdb->last_error`,
 * same check as `Support\ChecksReads::assertReadSucceeded()`) and logs a
 * genuine failure as `settings.lectura_fallida` — a code containing
 * `fallid`, this plugin's own convention (see `Observability\WpEventLog`'s
 * class docblock) for reaching the durable `entre_redes_cambios_ultimo_error`
 * option, the operator's only diagnostic channel on this shared host.
 *
 * *** WHICH DIRECTION IS SAFE IS DECIDED PER SETTING, NEVER BLANKET ***
 * Collapsing a genuine failure into `$default` is fine ONLY when `$default`
 * is already the conservative reading for that setting. Audited here,
 * getter by getter:
 *
 *   - `timezone()` / `seasonId()` / the four `plazo*Offset()` methods /
 *     `listaEsperaTeamIdOverride()`: pure configuration, not a fairness gate
 *     — a failed read falling back to the seeded default is the SAME value
 *     a working read would almost always return anyway (these rows are
 *     seeded once and rarely touched), so no special handling beyond the
 *     logging `readStringResult()` already does for every call.
 *   - `prioridadPadresActiva()`: default `false` (OFF, no extra gate) is
 *     already the safe/inert direction — a failed read collapsing to it
 *     changes nothing about what the ruleset enforces. No special handling
 *     needed; this is the getter `exencionArcoActiva()` below is contrasted
 *     against.
 *   - `exencionArcoActiva()`: the ONLY getter whose seeded default ('1', ON)
 *     is the UNSAFE direction — collapsing a failure into it would silently
 *     GRANT the techo exemption during a DB hiccup. This getter therefore
 *     does NOT delegate to the generic `readBool()` — see its own docblock.
 */
class Settings {

    private \wpdb $wpdb;
    private EventLog $eventLog;

    public function __construct( \wpdb $wpdb, EventLog $eventLog ) {
        $this->wpdb     = $wpdb;
        $this->eventLog = $eventLog;
    }

    /**
     * IANA timezone identifier used to compute civil plazos.
     * Default: 'America/Argentina/Buenos_Aires'.
     */
    public function timezone(): string {
        return $this->readString( 'timezone', (string) InitialSchema::SEED_DEFAULTS['timezone'] );
    }

    /**
     * The SportsPress season term id the calendar is seeded for.
     * Default: 359.
     */
    public function seasonId(): int {
        return $this->readInt( 'season_id', (int) InitialSchema::SEED_DEFAULTS['season_id'] );
    }

    /** @return array{days:int, time:string} */
    public function aperturaSolicitudesOffset(): array {
        return $this->readOffset( 'plazo_apertura_solicitudes' );
    }

    /** @return array{days:int, time:string} */
    public function cierreRegresosOffset(): array {
        return $this->readOffset( 'plazo_cierre_regresos' );
    }

    /** @return array{days:int, time:string} */
    public function cierreSolicitudesOffset(): array {
        return $this->readOffset( 'plazo_cierre_solicitudes' );
    }

    /** @return array{days:int, time:string} */
    public function publicacionOffset(): array {
        return $this->readOffset( 'plazo_publicacion' );
    }

    /**
     * All four offsets, keyed exactly as PlazosCalculator::compute() expects.
     *
     * @return array<string, array{days:int, time:string}>
     */
    public function plazosOffsets(): array {
        return [
            'apertura_solicitudes' => $this->aperturaSolicitudesOffset(),
            'cierre_regresos'      => $this->cierreRegresosOffset(),
            'cierre_solicitudes'   => $this->cierreSolicitudesOffset(),
            'publicacion'          => $this->publicacionOffset(),
        ];
    }

    /**
     * Whether the reglamento's "prioridad para padres" is an ENFORCED gate
     * (a non-padre entrante is ineligible whenever a viable padre exists for
     * the plaza) rather than the soft, unenforced preference it is today —
     * see Dictamen\Reglas\PrioridadDePadresRespetada's own docblock for the
     * full rule. Default: `false` (OFF) — see
     * Migrations\InitialSchema::SEED_DEFAULTS, 'prioridad_padres_activa'.
     */
    public function prioridadPadresActiva(): bool {
        return $this->readBool( 'prioridad_padres_activa', (string) InitialSchema::SEED_DEFAULTS['prioridad_padres_activa'] );
    }

    /**
     * Whether "la exención del arco" is ON — the goal plaza's techo does not
     * apply to the field titular moving into it as movement 1 of a grouped
     * goalkeeper reassignment (see `Dictamen\Reglas\PuntajeDentroDelTecho`
     * and `Dictamen\Reglas\EntranteDisponible`'s own docblocks, and
     * `Solicitudes\SolicitudRepository`'s class docblock for the full
     * business shape). Default: `true` (ON) — see
     * `Migrations\InitialSchema::SEED_DEFAULTS`, 'exencion_arco_activa'.
     * Unlike `prioridadPadresActiva()` above, this policy ships ON by
     * default: the process owner's own request was for a SWITCH to turn it
     * OFF when needed, not an opt-in gate for a soft preference nobody was
     * enforcing yet.
     *
     * *** FAILS CLOSED, NOT TO THE SEEDED DEFAULT ***
     * Every other boolean/typed getter in this class is content to collapse
     * a genuine read failure into its seeded default — see class docblock,
     * "WHICH DIRECTION IS SAFE IS DECIDED PER SETTING". This one CANNOT: its
     * default is `'1'` (ON), and ON is exactly what relaxes
     * `Reglas\PuntajeDentroDelTecho` / `Reglas\EntranteDisponible`'s checks
     * for movement 1 of a grouped goalkeeper reassignment. Resolving a
     * transient DB failure to ON would silently grant that relaxation —
     * the exact "a hiccup reads as permission" bug this method exists to
     * refuse. On a genuine failure this returns `false` (OFF) instead,
     * which — per `DictamenPipeline::evaluateGrupo()` — means a grouped
     * request is refused (via the ordinary techo) until the read works
     * again: noisy and safe, never silent and permissive. An ABSENT row
     * (no failure, just nothing seeded) still resolves to the seeded
     * default exactly like every other getter.
     */
    public function exencionArcoActiva(): bool {
        $resultado = $this->readStringResult(
            'exencion_arco_activa',
            (string) InitialSchema::SEED_DEFAULTS['exencion_arco_activa']
        );

        if ( $resultado['failed'] ) {
            return false;
        }

        return '1' === $resultado['value'];
    }

    /**
     * The operator-configured `sp_team` post id for THIS season's "lista de
     * espera" pseudo-team, ONLY when explicitly set to a positive value —
     * `null` otherwise (an absent row, or the seeded `'0'`, see
     * Migrations\InitialSchema::SEED_DEFAULTS), which tells
     * Plazas\ListaEsperaResolver to fall back to its own dynamic, slug-based
     * lookup instead of trusting a `0` as a real post id. This accessor never
     * performs that lookup itself — Settings stays a plain `cambios_settings`
     * reader, same as every other getter in this class; see
     * Plazas\ListaEsperaResolver's own docblock for the two-step resolution
     * order (this override first, the dynamic lookup second).
     */
    public function listaEsperaTeamIdOverride(): ?int {
        $value = $this->readInt( 'lista_espera_team_id', (int) InitialSchema::SEED_DEFAULTS['lista_espera_team_id'] );

        return $value > 0 ? $value : null;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** @return array{days:int, time:string} */
    private function readOffset( string $key ): array {
        $defaultJson = (string) InitialSchema::SEED_DEFAULTS[ $key ];
        $raw         = $this->readString( $key, $defaultJson );

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) || ! isset( $decoded['days'], $decoded['time'] ) ) {
            $decoded = json_decode( $defaultJson, true );
        }

        return [
            'days' => (int) $decoded['days'],
            'time' => (string) $decoded['time'],
        ];
    }

    private function readString( string $key, string $default ): string {
        return $this->readStringResult( $key, $default )['value'];
    }

    /**
     * Does the actual `cambios_settings` read and tells "absent row" apart
     * from "the query failed" — see class docblock, "A FAILED READ IS NOT
     * THE SAME FACT AS 'THE ROW IS ABSENT'". `$wpdb->get_var()` returns
     * `null` for both, so `$wpdb->last_error` (set by the query that JUST
     * ran, same discipline as `Support\ChecksReads::assertReadSucceeded()`)
     * is what distinguishes them.
     *
     * A genuine failure is logged as `settings.lectura_fallida` — contains
     * `fallid`, so it reaches `entre_redes_cambios_ultimo_error` (see
     * `Observability\WpEventLog`'s class docblock) — and `value` is still
     * `$default`, so every CALLER that does not special-case `failed` keeps
     * behaving exactly as before this method existed. Only
     * `exencionArcoActiva()` inspects `failed` itself, because its default
     * is the unsafe direction — see that method's own docblock.
     *
     * @return array{value: string, failed: bool}
     */
    private function readStringResult( string $key, string $default ): array {
        $p     = $this->wpdb->prefix;
        $value = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT setting_value FROM {$p}cambios_settings WHERE setting_key = %s",
                $key
            )
        );

        $lastError = (string) ( $this->wpdb->last_error ?? '' );
        $failed    = null === $value && '' !== $lastError;

        if ( $failed ) {
            $this->eventLog->record( 'settings.lectura_fallida', [
                'setting_key' => $key,
                'last_error'  => $lastError,
            ] );
        }

        return [
            'value'  => null === $value ? $default : (string) $value,
            'failed' => $failed,
        ];
    }

    private function readInt( string $key, int $default ): int {
        return (int) $this->readString( $key, (string) $default );
    }

    /**
     * `'1'` is true, anything else (including an absent row, via $default)
     * is false — the same "stored as a string, typed at the accessor"
     * discipline as readInt(), never a second storage representation.
     */
    private function readBool( string $key, string $default ): bool {
        return '1' === $this->readString( $key, $default );
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Support\ChecksReads;

/**
 * Reads `sp_metrics` postmeta (raw `$wpdb`, never `get_post_meta()` — same
 * discipline as every other query this plugin runs directly against a
 * WordPress core table, see PlazaRepository's class docblock) and turns it
 * into a JugadorMetricas value object.
 *
 * Extracted out of Dictamen\DictamenContextAssembler (slice 4b), which used
 * to read only `puntaje`/`Puntaje` inline, so this SAME parsing is now the
 * one place both DictamenContextAssembler (one named entrante) and
 * Plazas\CandidatosResolver (every season-registered candidate) go through —
 * neither may re-derive its own reading of `sp_metrics`.
 *
 * *** WHAT "ES PADRE" MEANS, VERIFIED AGAINST REAL DATA, NOT ASSUMED ***
 * The `caracter` field is FREE TEXT, not an enum — a historical SQL dump
 * showed dozens of inconsistent spellings and several UNRELATED categories
 * (`Docente Activo`, `Personal Maestranza`, `Socio Fundador`, even a stray
 * `Defensor` — a football position value leaked into the wrong meta) mixed in
 * with genuine "padre" values. But the CURRENT, live roster — checked across
 * every `wpallexport` CSV export from February 2025 through March 2026 — uses
 * EXACTLY two values: the literal string `Padre Activo`, or empty. The
 * historical variety is dead data, not something this classifier needs to
 * get right forever.
 *
 * `esPadreDesdeCaracter()` therefore matches "starts with 'padre'"
 * (case-insensitive, trimmed) rather than an exact string: it matches the
 * live convention (`Padre Activo`) AND every historical "padre*" variant
 * (`Padre de Alumno`, `Padre exAlumno`, `padre activo`, …) without having to
 * enumerate them, while correctly EXCLUDING every non-padre category found in
 * the same dump (`Invitado`, `Docente`, `ExAlumno` with no "Padre" prefix,
 * `Reemplazo`, empty). The one genuinely ambiguous historical value —
 * `ExAlumno`/`Exalumno` with NO "Padre" prefix — is deliberately read as NOT
 * a padre: this feature only ever ADVANTAGES a padre over a non-padre, so
 * misreading an actual padre as a non-padre costs that player no advantage
 * they were not already getting (today, with the policy off, nobody gets
 * one); misreading a non-padre AS a padre would incorrectly count them
 * towards "hay un padre viable" and block someone else. Fail toward
 * under-counting padres, never over-counting.
 *
 * No `Caracter` (capital C) dual-key problem was found in either the API code
 * or the historical dump — unlike `puntaje`/`Puntaje`, `caracter` has never
 * been read under a second casing anywhere in this codebase.
 *
 * *** DETERMINISM WHEN MORE THAN ONE `sp_metrics` ROW EXISTS FOR A PLAYER ***
 * WordPress does NOT enforce uniqueness on `(post_id, meta_key)` in
 * `postmeta` — a player can legitimately end up with two `sp_metrics` rows
 * (a re-import, a stray manual edit). Without an explicit ordering,
 * `resolve()`'s old `LIMIT 1` and `resolveMuchos()`'s old row-order iteration
 * could each pick a DIFFERENT one of the two rows for the SAME player,
 * silently breaking the single-source-of-truth invariant this whole feature
 * rests on (`Plazas\CandidatosResolver`'s own class docblock, "WHY THIS MUST
 * BE THE ONLY IMPLEMENTATION"). THE CHOSEN RULE: the row with the HIGHEST
 * `meta_id` wins — i.e. the MOST RECENTLY WRITTEN row for that player.
 * `resolve()` implements this directly (`ORDER BY meta_id DESC LIMIT 1`);
 * `resolveMuchos()` implements the same selection by ordering
 * `ORDER BY meta_id ASC` and letting the LAST matching row in that ascending
 * iteration overwrite `$resultado[$playerId]` — the last row processed is
 * therefore always the one with the highest `meta_id`. Both routes converge
 * on the identical row for the identical player; see
 * JugadorMetricasReaderTest's "two rows for one player" test.
 *
 * *** READ FAILURES MUST NEVER READ AS "NOBODY HAS METRICS" *** `resolve()`
 * feeds `Dictamen\DictamenContextAssembler`'s entrante lookup, and
 * `resolveMuchos()` feeds `Plazas\CandidatosResolver`'s whole candidate pool
 * — a failed read misread as "no sp_metrics row" would under-count padres
 * (or misread the entrante's own puntaje) instead of failing loud. Same
 * discipline as `PlazaRepository`, via `Support\ChecksReads`. See that
 * trait's docblock for the full contract; `resolveMuchos()` uses it
 * directly, and `resolve()` applies the equivalent `last_error` check
 * inline, since `$wpdb->get_var()` returns a scalar, not the `array|null`
 * shape the trait guards.
 */
final class JugadorMetricasReader {

    use ChecksReads;

    private const META_KEY_METRICS = 'sp_metrics';

    private \wpdb $wpdb;
    private EventLog $eventLog;

    /**
     * @param EventLog $eventLog MANDATORY, no null-object fallback — same
     *        discipline as every other class in this plugin that reads
     *        directly against `$wpdb`.
     */
    public function __construct( \wpdb $wpdb, EventLog $eventLog ) {
        $this->wpdb     = $wpdb;
        $this->eventLog = $eventLog;
    }

    /**
     * @throws \InvalidArgumentException When the stored puntaje value is
     *         present but is not one of the 9 valid puntajes — propagated
     *         from Puntaje::fromDecimal(), same as before this extraction.
     * @throws \RuntimeException When the query fails at the wpdb level. A
     *         genuinely absent row (`$raw === null` with `$wpdb->last_error`
     *         empty) is NOT a failure — it keeps its existing meaning,
     *         "no sp_metrics row for this player" — see class docblock.
     */
    public function resolve( int $playerId ): JugadorMetricas {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$p}postmeta
                  WHERE post_id = %d AND meta_key = %s
                  ORDER BY meta_id DESC
                  LIMIT 1",
                $playerId,
                self::META_KEY_METRICS
            )
        );

        $lastError = (string) ( $wpdb->last_error ?? '' );

        if ( '' !== $lastError ) {
            $this->eventLog->record( 'lectura.fallida', [
                'operacion'  => 'resolve',
                'player_id'  => $playerId,
                'last_error' => $wpdb->last_error,
            ] );

            throw new \RuntimeException(
                "JugadorMetricasReader::resolve(): the query failed at the wpdb level ({$lastError})."
            );
        }

        return $this->fromRawMetaValue( $raw );
    }

    /**
     * Batched variant of resolve() — ONE query for every id in $playerIds,
     * instead of one query per player. Used by Plazas\CandidatosResolver,
     * which resolves this for an entire season's roster at once; see that
     * class's docblock for why "one query per candidate" would otherwise be
     * the dominant cost of a policy this feature keeps OFF by default
     * specifically to avoid paying it needlessly.
     *
     * @param array<int, int> $playerIds
     * @return array<int, JugadorMetricas> Keyed by player_id. A player with no
     *         `sp_metrics` row simply gets JugadorMetricas::vacio() — the map
     *         is total over $playerIds, never partial.
     * @throws \InvalidArgumentException Same as resolve(), for whichever
     *         player's stored puntaje is invalid.
     * @throws \RuntimeException When the query fails at the wpdb level — see
     *         class docblock, "READ FAILURES MUST NEVER READ AS 'NOBODY HAS
     *         METRICS'".
     */
    public function resolveMuchos( array $playerIds ): array {
        $resultado = [];

        foreach ( $playerIds as $playerId ) {
            $resultado[ $playerId ] = JugadorMetricas::vacio();
        }

        if ( empty( $playerIds ) ) {
            return $resultado;
        }

        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $placeholders = implode( ', ', array_fill( 0, count( $playerIds ), '%d' ) );

        // ORDER BY meta_id ASC — NOT arbitrary, see class docblock,
        // "DETERMINISM WHEN MORE THAN ONE sp_metrics ROW EXISTS": the loop
        // below overwrites $resultado[$playerId] on every matching row, so
        // ascending order makes the LAST overwrite always the row with the
        // highest meta_id — the same row resolve()'s own `ORDER BY meta_id
        // DESC LIMIT 1` picks for that same player.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$p}postmeta
                  WHERE meta_key = %s AND post_id IN ({$placeholders})
                  ORDER BY meta_id ASC",
                array_merge( [ self::META_KEY_METRICS ], $playerIds )
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'resolveMuchos', [ 'player_ids_count' => count( $playerIds ) ] );

        foreach ( $rows as $row ) {
            $playerId               = (int) $row['post_id'];
            $resultado[ $playerId ] = $this->fromRawMetaValue( (string) $row['meta_value'] );
        }

        return $resultado;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function fromRawMetaValue( ?string $raw ): JugadorMetricas {
        $metrics = $this->decodeMetrics( $raw );

        if ( null === $metrics ) {
            return JugadorMetricas::vacio();
        }

        $puntajeValue  = $metrics['puntaje'] ?? ( $metrics['Puntaje'] ?? null );
        $caracterValue = $metrics['caracter'] ?? null;

        $puntaje = null;
        if ( null !== $puntajeValue && '' !== $puntajeValue ) {
            $puntaje = Puntaje::fromDecimal( is_string( $puntajeValue ) ? $puntajeValue : (string) $puntajeValue );
        }

        return JugadorMetricas::desde( $puntaje, self::esPadreDesdeCaracter( is_string( $caracterValue ) ? $caracterValue : null ) );
    }

    /**
     * See class docblock, "WHAT 'ES PADRE' MEANS, VERIFIED AGAINST REAL
     * DATA, NOT ASSUMED".
     */
    private static function esPadreDesdeCaracter( ?string $caracter ): bool {
        if ( null === $caracter ) {
            return false;
        }

        $normalizado = strtolower( trim( $caracter ) );

        return '' !== $normalizado && str_starts_with( $normalizado, 'padre' );
    }

    /**
     * Reimplements just enough of `maybe_unserialize()` to read `sp_metrics`
     * without depending on WordPress at all — identical to the private
     * helper this was extracted from in DictamenContextAssembler.
     *
     * @return array<string, mixed>|null
     */
    private function decodeMetrics( ?string $raw ): ?array {
        if ( null === $raw || '' === $raw ) {
            return null;
        }

        $decoded = @unserialize( $raw, [ 'allowed_classes' => false ] );

        return is_array( $decoded ) ? $decoded : null;
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Support\ChecksReads;

/**
 * Reads TWO independent postmeta values (raw `$wpdb`, never `get_post_meta()`
 * / `get_field()` — same discipline as every other query this plugin runs
 * directly against a WordPress core table, see PlazaRepository's class
 * docblock) and turns them into a single JugadorMetricas value object:
 *
 *   - `puntaje` comes from the serialized `sp_metrics` postmeta blob (a
 *     SportsPress field), read exactly as before.
 *   - `caracter` (padre vs. guest) comes from a DEDICATED ACF field, ALSO
 *     named `caracter` and ALSO stored in `postmeta` — ACF's own storage
 *     convention for a simple text field is a plain, un-serialized value
 *     under `meta_key` = the field name — but as a SEPARATE row, NOT nested
 *     inside the `sp_metrics` blob. This is what produces the `acf.caracter`
 *     value on `wp-json/wp/v2/sp_player/{id}`.
 *
 * `puntaje` and `caracter` therefore live in two different rows for the same
 * player, read by two different queries. See "WHY caracter MOVED OUT OF
 * sp_metrics" below for why, and "HARD SWITCH, NO FALLBACK" for why the old
 * `sp_metrics['caracter']` is never consulted again.
 *
 * Extracted out of Dictamen\DictamenContextAssembler (slice 4b), which used
 * to read only `puntaje`/`Puntaje` inline, so this SAME parsing is now the
 * one place both DictamenContextAssembler (one named entrante) and
 * Plazas\CandidatosResolver (every season-registered candidate) go through —
 * neither may re-derive its own reading of either field.
 *
 * *** WHY `caracter` MOVED OUT OF `sp_metrics` ***
 * `sp_metrics['caracter']` was the legacy, free-text home for this
 * distinction — messy and inconsistent (`Padre Activo`, `Padre Exalumno`,
 * `Padre EXALUMNO`, `Docente Activo`, `ExAlumno`, blanks — different
 * vocabulary entirely). The tournament now keeps this in a dedicated ACF
 * field, populated deliberately across the whole roster. Verified against
 * live production data (`wp-json/wp/v2/sp_player/{id}`, `acf.caracter`,
 * 1105 players): `Padre Alumno` 570, `Padre Ex-Alumno` 268, `Invitado` 129,
 * empty 114, `Personal Colegio` 22, `Socio Fundador` 2 — a clean, consistent
 * vocabulary, 90% populated. That is the field this class now reads.
 *
 * *** HARD SWITCH, NO FALLBACK ***
 * There is NO fallback to `sp_metrics['caracter']` when the ACF field is
 * empty, and there must never be one. Checked against real data: of the 114
 * players whose ACF `caracter` is empty, a 60-player sample showed 100% ALSO
 * empty in the legacy `sp_metrics` field — a fallback would recover nobody,
 * it would only reintroduce the old messy vocabulary as a silent second
 * source of truth. Reading `sp_metrics['caracter']` anywhere in this class
 * would be a bug, not a defensive measure.
 *
 * *** WHAT "ES PADRE" MEANS, VERIFIED AGAINST REAL DATA, NOT ASSUMED ***
 * Against the live ACF vocabulary above: `Padre Alumno` and `Padre Ex-Alumno`
 * are padres; `Invitado`, `Personal Colegio`, `Socio Fundador`, and blank are
 * not. `esPadreDesdeCaracter()` matches "starts with 'padre'"
 * (case-insensitive, trimmed) rather than an exact string, so it is
 * resilient to the exact spelling/hyphenation ACF happens to hold
 * (`Padre Alumno` vs. `Padre Ex-Alumno`) without enumerating every value.
 * Blank is deliberately read as NOT a padre: this feature only ever
 * ADVANTAGES a padre over a non-padre, so misreading an actual padre as a
 * non-padre costs that player no advantage they were not already getting
 * (today, with the policy off, nobody gets one); misreading a non-padre AS a
 * padre would incorrectly count them towards "hay un padre viable" and block
 * someone else. Fail toward under-counting padres, never over-counting.
 *
 * No `Caracter` (capital C) dual-key problem exists for the ACF field — it
 * is read under a single, exact meta_key (`caracter`), unlike
 * `puntaje`/`Puntaje` in `sp_metrics`, which genuinely has two live castings.
 *
 * *** DETERMINISM WHEN MORE THAN ONE ROW EXISTS FOR A PLAYER ***
 * WordPress does NOT enforce uniqueness on `(post_id, meta_key)` in
 * `postmeta` — a player can legitimately end up with two rows under the SAME
 * meta_key (a re-import, a stray manual edit, an ACF re-save). This applies
 * INDEPENDENTLY to `sp_metrics` (puntaje) and to `caracter` (padre/guest) —
 * two unrelated rows, each needing its own deterministic pick. Without an
 * explicit ordering, `resolve()`'s old `LIMIT 1` and `resolveMuchos()`'s old
 * row-order iteration could each pick a DIFFERENT one of the two rows for
 * the SAME player, silently breaking the single-source-of-truth invariant
 * this whole feature rests on (`Plazas\CandidatosResolver`'s own class
 * docblock, "WHY THIS MUST BE THE ONLY IMPLEMENTATION"). THE CHOSEN RULE,
 * applied separately to each meta_key: the row with the HIGHEST `meta_id`
 * wins — i.e. the MOST RECENTLY WRITTEN row for that player. `resolve()`
 * implements this directly (`ORDER BY meta_id DESC LIMIT 1`, once per
 * meta_key); `resolveMuchos()` implements the same selection by ordering
 * `ORDER BY meta_id ASC` (once per meta_key) and letting the LAST matching
 * row in that ascending iteration overwrite the running result for that
 * player — the last row processed is therefore always the one with the
 * highest `meta_id`. Both routes converge on the identical row for the
 * identical player and meta_key; see JugadorMetricasReaderTest's "two rows
 * for one player" tests.
 *
 * *** READ FAILURES MUST NEVER READ AS "NOBODY HAS METRICS" *** `resolve()`
 * feeds `Dictamen\DictamenContextAssembler`'s entrante lookup, and
 * `resolveMuchos()` feeds `Plazas\CandidatosResolver`'s whole candidate pool
 * — a failed read misread as "no row" would under-count padres (or misread
 * the entrante's own puntaje) instead of failing loud. This applies to BOTH
 * queries now (`sp_metrics` and `caracter`), same discipline as
 * `PlazaRepository`, via `Support\ChecksReads`. See that trait's docblock
 * for the full contract; `resolveMuchos()` uses it directly for both
 * queries, and `resolve()` applies the equivalent `last_error` check inline
 * for both queries, since `$wpdb->get_var()` returns a scalar, not the
 * `array|null` shape the trait guards.
 */
final class JugadorMetricasReader {

    use ChecksReads;

    private const META_KEY_METRICS  = 'sp_metrics';
    private const META_KEY_CARACTER = 'caracter';

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
     * @throws \RuntimeException When either query fails at the wpdb level. A
     *         genuinely absent row (`$raw === null` with `$wpdb->last_error`
     *         empty) is NOT a failure — it keeps its existing meaning, "no
     *         row for this player under this meta_key" — see class docblock.
     */
    public function resolve( int $playerId ): JugadorMetricas {
        $rawMetrics  = $this->fetchLatestMetaValue( $playerId, self::META_KEY_METRICS, 'resolve' );
        $rawCaracter = $this->fetchLatestMetaValue( $playerId, self::META_KEY_CARACTER, 'resolve' );

        return JugadorMetricas::desde(
            $this->extractPuntaje( $rawMetrics ),
            self::esPadreDesdeCaracter( $rawCaracter )
        );
    }

    /**
     * Batched variant of resolve() — ONE query for every id in $playerIds per
     * meta_key (two queries total, not one per player). Used by
     * Plazas\CandidatosResolver, which resolves this for an entire season's
     * roster at once; see that class's docblock for why "one query per
     * candidate" would otherwise be the dominant cost of a policy this
     * feature keeps OFF by default specifically to avoid paying it
     * needlessly.
     *
     * @param array<int, int> $playerIds
     * @return array<int, JugadorMetricas> Keyed by player_id. A player with
     *         no `sp_metrics` row and/or no `caracter` row simply gets the
     *         corresponding fact left at its empty default — the map is
     *         total over $playerIds, never partial.
     * @throws \InvalidArgumentException Same as resolve(), for whichever
     *         player's stored puntaje is invalid.
     * @throws \RuntimeException When either query fails at the wpdb level —
     *         see class docblock, "READ FAILURES MUST NEVER READ AS 'NOBODY
     *         HAS METRICS'".
     */
    public function resolveMuchos( array $playerIds ): array {
        if ( empty( $playerIds ) ) {
            return [];
        }

        $puntajes = array_fill_keys( $playerIds, null );
        $esPadres = array_fill_keys( $playerIds, false );

        foreach ( $this->fetchLatestMetaValuesFor( $playerIds, self::META_KEY_METRICS, 'resolveMuchos' ) as $playerId => $rawMetrics ) {
            $puntajes[ $playerId ] = $this->extractPuntaje( $rawMetrics );
        }

        foreach ( $this->fetchLatestMetaValuesFor( $playerIds, self::META_KEY_CARACTER, 'resolveMuchos' ) as $playerId => $rawCaracter ) {
            $esPadres[ $playerId ] = self::esPadreDesdeCaracter( $rawCaracter );
        }

        $resultado = [];

        foreach ( $playerIds as $playerId ) {
            $resultado[ $playerId ] = JugadorMetricas::desde( $puntajes[ $playerId ], $esPadres[ $playerId ] );
        }

        return $resultado;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Single-row read of the LATEST value (highest meta_id) for one
     * `$metaKey` on one player — the shared shape behind resolve()'s two
     * reads (`sp_metrics` and `caracter`). See class docblock, "DETERMINISM
     * WHEN MORE THAN ONE ROW EXISTS FOR A PLAYER" and "READ FAILURES MUST
     * NEVER READ AS 'NOBODY HAS METRICS'".
     *
     * @throws \RuntimeException When the query fails at the wpdb level.
     */
    private function fetchLatestMetaValue( int $playerId, string $metaKey, string $operacion ): ?string {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$p}postmeta
                  WHERE post_id = %d AND meta_key = %s
                  ORDER BY meta_id DESC
                  LIMIT 1",
                $playerId,
                $metaKey
            )
        );

        $lastError = (string) ( $wpdb->last_error ?? '' );

        if ( '' !== $lastError ) {
            $this->eventLog->record( 'lectura.fallida', [
                'operacion'  => $operacion,
                'player_id'  => $playerId,
                'meta_key'   => $metaKey,
                'last_error' => $wpdb->last_error,
            ] );

            throw new \RuntimeException(
                "JugadorMetricasReader::{$operacion}(): the query for meta_key '{$metaKey}' failed at the wpdb level ({$lastError})."
            );
        }

        return $raw;
    }

    /**
     * Batched read of the LATEST value (highest meta_id) for one `$metaKey`,
     * across every id in $playerIds — the shared shape behind
     * resolveMuchos()'s two reads. See
     * `fetchLatestMetaValue()`'s docblock for the same determinism and
     * failure-handling contract, applied here per meta_key rather than per
     * player.
     *
     * @param array<int, int> $playerIds
     * @return array<int, string> Keyed by player_id — ONLY the players that
     *         actually have a row under $metaKey are present; a player with
     *         none is simply absent from this map (callers must default the
     *         corresponding fact themselves, same as `resolveMuchos()` does).
     * @throws \RuntimeException When the query fails at the wpdb level.
     */
    private function fetchLatestMetaValuesFor( array $playerIds, string $metaKey, string $operacion ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $placeholders = implode( ', ', array_fill( 0, count( $playerIds ), '%d' ) );

        // ORDER BY meta_id ASC — NOT arbitrary, see class docblock,
        // "DETERMINISM WHEN MORE THAN ONE ROW EXISTS FOR A PLAYER": the loop
        // below overwrites $resultado[$playerId] on every matching row, so
        // ascending order makes the LAST overwrite always the row with the
        // highest meta_id — the same row fetchLatestMetaValue()'s own
        // `ORDER BY meta_id DESC LIMIT 1` picks for that same player and
        // meta_key.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$p}postmeta
                  WHERE meta_key = %s AND post_id IN ({$placeholders})
                  ORDER BY meta_id ASC",
                array_merge( [ $metaKey ], $playerIds )
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, $operacion, [ 'player_ids_count' => count( $playerIds ), 'meta_key' => $metaKey ] );

        $resultado = [];

        foreach ( $rows as $row ) {
            $resultado[ (int) $row['post_id'] ] = (string) $row['meta_value'];
        }

        return $resultado;
    }

    /**
     * @return Puntaje|null Null when $rawMetrics decodes to nothing usable,
     *         or omits both `puntaje` and `Puntaje`, or the value is empty —
     *         same behaviour as before caracter was split out of this blob.
     * @throws \InvalidArgumentException Propagated from Puntaje::fromDecimal()
     *         when the stored value is present but invalid.
     */
    private function extractPuntaje( ?string $rawMetrics ): ?Puntaje {
        $metrics = $this->decodeMetrics( $rawMetrics );

        if ( null === $metrics ) {
            return null;
        }

        $puntajeValue = $metrics['puntaje'] ?? ( $metrics['Puntaje'] ?? null );

        if ( null === $puntajeValue || '' === $puntajeValue ) {
            return null;
        }

        return Puntaje::fromDecimal( is_string( $puntajeValue ) ? $puntajeValue : (string) $puntajeValue );
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

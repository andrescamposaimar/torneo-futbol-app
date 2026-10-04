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
 * *** fetchLatestMetaValuesFor() CHUNKS $playerIds, NEVER ONE UNBOUNDED `IN
 * (...)` *** `Plazas\CandidatosResolver::buscarPaginado()` calls
 * `resolveMuchos()` over the WHOLE candidate population BEFORE pagination
 * (see that method's own docblock, "WHY PAGINATION HAPPENS HERE, BEFORE THE
 * N+1, NOT AFTER") — for `?seccion=padron_completo` that population is
 * roughly a THOUSAND player ids, which would otherwise build a single SQL
 * statement with ~1000 `%d` placeholders. `fetchLatestMetaValuesFor()`
 * batches $playerIds into chunks of `self::ID_CHUNK_SIZE` (200) and runs one
 * query per chunk, merging the results — chosen conservatively: 200 keeps
 * every chunk's placeholder count comfortably under the old SQLite
 * `SQLITE_LIMIT_VARIABLE_NUMBER` default of 999 this plugin's own test shim
 * is bound by (headroom for `meta_key` and any future extra bound param),
 * keeps the per-chunk result set (post_id + meta_value, where `sp_metrics`
 * is a serialized blob that can be a few hundred bytes per player) small
 * enough to stay well under shared-hosting `max_allowed_packet` defaults even
 * in the worst case, and keeps the query COUNT per request small (≈5-6
 * chunks for a ~1000-player padrón, times 2 meta_keys = ≈10-12 queries) —
 * nowhere near "one query per candidate".
 *
 * Chunking is safe here specifically because it partitions the INPUT ID
 * LIST, not the OUTPUT ROWS: every row returned by a given chunk's query
 * necessarily has a `post_id` that IS one of that chunk's ids (the `WHERE
 * post_id IN (...)` clause guarantees it), so a single player's rows can
 * NEVER be split across two chunks — a player belongs to exactly one chunk,
 * full stop. This means the "DETERMINISM WHEN MORE THAN ONE ROW EXISTS FOR A
 * PLAYER" rule above (last row in `ORDER BY meta_id ASC` wins) is completely
 * unaffected by chunking: it is applied identically, per chunk, to that
 * chunk's own rows, and the per-player winner is decided within that single
 * chunk exactly as it would be if the whole id list had been queried at
 * once. Merging each chunk's keyed map into the running result is therefore
 * a plain union over disjoint key sets — never an overwrite between chunks.
 *
 * A query failure in ANY chunk — including one that is not the first — must
 * still throw, per "READ FAILURES MUST NEVER READ AS 'NOBODY HAS METRICS'"
 * below; results already merged from earlier, successful chunks are
 * discarded along with the exception, never returned as a silently partial
 * map. See JugadorMetricasReaderTest's chunking tests.
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
 *
 * *** A STORED PUNTAJE OF ZERO MEANS "SIN CALIFICAR", NOT "RATED ZERO" ***
 * Production incident (`entre_redes_cambios_ultimo_error`, 2026-10-04):
 * `GET /cambios/plazas/candidatos?seccion=padron_completo` returned a 500
 * because `extractPuntaje()` fed the stored string `"0"` straight into
 * `Puntaje::fromDecimal()`, which correctly rejects it —
 * `Puntaje::fromHalfPoints()`'s valid range is 2..10 half-points (1..5
 * points), and 0 is not in it. Verified against the full production
 * `sp_player` padrón (1105 published players, `_fields=id,metrics`): 7
 * players store `puntaje = "0"` (ids 4823, 4831, 4832, 4838, 21519, 21521,
 * and one more) — none registered in season 2026, which is why only
 * `?seccion=padron_completo` (every published player, not just this
 * season's roster) ever reached one. The app's own
 * `lib/utils/puntaje_utils.dart`'s `formatearPuntaje()` already documents `0`
 * as "sin calificar" (not yet rated), never as a legitimate rating of zero —
 * this reader now agrees: a stored value that normalizes to numeric zero is
 * treated exactly like a missing or blank puntaje, i.e. `extractPuntaje()`
 * returns `null`, never a thrown exception.
 *
 * *** AN OTHERWISE-INVALID STORED VALUE DEGRADES TO NULL TOO, BUT IS LOGGED
 * *** Downstream, a `null` puntaje is already a safe, first-class state —
 * `Plazas\CandidatosResolver::partitionPorTecho()` turns it into a
 * `CandidatoEstado` with `motivoNoViable = 'puntaje_indeterminado'`: not
 * viable, cannot be selected, but does not abort anyone else's read. A
 * single corrupt row (a stray non-numeric string, a value outside the 9
 * discrete puntajes) therefore gets the SAME treatment as a genuinely absent
 * puntaje rather than being allowed to throw an `\InvalidArgumentException`
 * out of `extractPuntaje()` — which, before this fix, is exactly what turned
 * ONE bad row into a 500 for the WHOLE page (`resolveMuchos()` has no
 * per-player try/catch of its own; the exception simply propagated out of
 * `Plazas\CandidatosResolver::buscarPaginado()` into
 * `Rest\PlazasController::listarCandidatos()`'s top-level `\Throwable` catch).
 * Swallowing it silently would hide real data corruption, so it is recorded
 * via `$this->eventLog` as `metrics.puntaje_invalido` (player id and the raw
 * stored value) before `extractPuntaje()` returns null — visible, but no
 * longer fatal to anyone else's request. `Puntaje::fromHalfPoints()` /
 * `::fromDecimal()` themselves are UNCHANGED and still throw: callers that
 * genuinely require a valid puntaje (`Plazas\Alta\TitularesListImporter`,
 * `Dictamen\Reglas\PuntajeDentroDelTecho`) still get that strictness: this
 * reader is the boundary where untrusted stored data enters the system, and
 * is the only place that catches it.
 */
final class JugadorMetricasReader {

    use ChecksReads;

    private const META_KEY_METRICS  = 'sp_metrics';
    private const META_KEY_CARACTER = 'caracter';

    /**
     * Max player ids per `fetchLatestMetaValuesFor()` query — see class
     * docblock, "fetchLatestMetaValuesFor() CHUNKS $playerIds, NEVER ONE
     * UNBOUNDED `IN (...)`", for why 200.
     */
    private const ID_CHUNK_SIZE = 200;

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
     * @throws \RuntimeException When either query fails at the wpdb level. A
     *         genuinely absent row (`$raw === null` with `$wpdb->last_error`
     *         empty) is NOT a failure — it keeps its existing meaning, "no
     *         row for this player under this meta_key" — see class docblock.
     *         A stored puntaje of zero, or one that is not one of the 9
     *         valid puntajes, is NOT a failure either — it resolves to a
     *         null puntaje (logged, for the invalid case) rather than
     *         throwing — see class docblock, "A STORED PUNTAJE OF ZERO MEANS
     *         'SIN CALIFICAR'" and "AN OTHERWISE-INVALID STORED VALUE
     *         DEGRADES TO NULL TOO, BUT IS LOGGED".
     */
    public function resolve( int $playerId ): JugadorMetricas {
        $rawMetrics  = $this->fetchLatestMetaValue( $playerId, self::META_KEY_METRICS, 'resolve' );
        $rawCaracter = $this->fetchLatestMetaValue( $playerId, self::META_KEY_CARACTER, 'resolve' );

        return JugadorMetricas::desde(
            $this->extractPuntaje( $rawMetrics, $playerId ),
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
     *         total over $playerIds, never partial. A player whose stored
     *         puntaje is zero, or otherwise not one of the 9 valid puntajes,
     *         ALSO resolves with a null puntaje rather than aborting the
     *         whole batch — see class docblock, "A STORED PUNTAJE OF ZERO
     *         MEANS 'SIN CALIFICAR'" and "AN OTHERWISE-INVALID STORED VALUE
     *         DEGRADES TO NULL TOO, BUT IS LOGGED". This is exactly what lets
     *         one corrupt row never take down `Plazas\CandidatosResolver`'s
     *         whole candidate pool.
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
            $puntajes[ $playerId ] = $this->extractPuntaje( $rawMetrics, $playerId );
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
     * player, and the class docblock, "fetchLatestMetaValuesFor() CHUNKS
     * $playerIds, NEVER ONE UNBOUNDED `IN (...)`", for why this runs one
     * query per `self::ID_CHUNK_SIZE`-sized chunk of $playerIds rather than
     * one query for the whole array.
     *
     * @param array<int, int> $playerIds
     * @return array<int, string> Keyed by player_id — ONLY the players that
     *         actually have a row under $metaKey are present; a player with
     *         none is simply absent from this map (callers must default the
     *         corresponding fact themselves, same as `resolveMuchos()` does).
     * @throws \RuntimeException When the query fails at the wpdb level, for
     *         ANY chunk — including one that is not the first. Results
     *         already merged from earlier, successful chunks are discarded
     *         along with the exception; this method never returns a partial
     *         map on failure (see class docblock, "READ FAILURES MUST NEVER
     *         READ AS 'NOBODY HAS METRICS'").
     */
    private function fetchLatestMetaValuesFor( array $playerIds, string $metaKey, string $operacion ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $resultado = [];

        // Chunking the INPUT id list — never the output rows — is what makes
        // this safe: a chunk's `WHERE post_id IN (...)` guarantees every row
        // it returns belongs to THAT chunk's own ids, so one player's rows
        // can never straddle two chunks. See class docblock,
        // "fetchLatestMetaValuesFor() CHUNKS $playerIds...", for the full
        // reasoning, including why this leaves the meta_id-ordering
        // determinism rule below completely unaffected.
        foreach ( array_chunk( $playerIds, self::ID_CHUNK_SIZE ) as $chunk ) {
            $placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

            // ORDER BY meta_id ASC — NOT arbitrary, see class docblock,
            // "DETERMINISM WHEN MORE THAN ONE ROW EXISTS FOR A PLAYER": the
            // loop below overwrites $resultado[$playerId] on every matching
            // row, so ascending order makes the LAST overwrite always the
            // row with the highest meta_id — the same row
            // fetchLatestMetaValue()'s own `ORDER BY meta_id DESC LIMIT 1`
            // picks for that same player and meta_key. This holds per chunk
            // exactly as it held for the whole list before chunking existed,
            // because a player's rows are entirely contained within the one
            // chunk that carries their id.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_id, meta_value FROM {$p}postmeta
                      WHERE meta_key = %s AND post_id IN ({$placeholders})
                      ORDER BY meta_id ASC",
                    array_merge( [ $metaKey ], $chunk )
                ),
                ARRAY_A
            );

            // Thrown here, per chunk — a failure on, say, the 4th of 6
            // chunks must still fail the WHOLE read, never return the first
            // 3 chunks' worth of players as "resolved" and silently drop the
            // rest. $resultado accumulated so far is simply discarded along
            // with this exception, exactly as it would be if the method had
            // never chunked at all.
            $this->assertReadSucceeded( $rows, $operacion, [
                'player_ids_count' => count( $chunk ),
                'meta_key'         => $metaKey,
            ] );

            foreach ( $rows as $row ) {
                $resultado[ (int) $row['post_id'] ] = (string) $row['meta_value'];
            }
        }

        return $resultado;
    }

    /**
     * @return Puntaje|null Null when $rawMetrics decodes to nothing usable,
     *         or omits both `puntaje` and `Puntaje`, or the value is empty,
     *         or normalizes to numeric zero ("sin calificar", never "rated
     *         zero" — see class docblock, "A STORED PUNTAJE OF ZERO MEANS
     *         'SIN CALIFICAR'"), or is not one of the 9 valid puntajes (see
     *         class docblock, "AN OTHERWISE-INVALID STORED VALUE DEGRADES TO
     *         NULL TOO, BUT IS LOGGED" — the invalid case is recorded via
     *         `$this->eventLog` as `metrics.puntaje_invalido` before this
     *         returns null). Never throws: this method is the boundary where
     *         untrusted stored data enters the system, so it degrades
     *         instead of propagating `Puntaje::fromDecimal()`'s own
     *         strictness — see that method's docblock for why IT still
     *         throws for every other caller.
     */
    private function extractPuntaje( ?string $rawMetrics, int $playerId ): ?Puntaje {
        $metrics = $this->decodeMetrics( $rawMetrics );

        if ( null === $metrics ) {
            return null;
        }

        $puntajeValue = $metrics['puntaje'] ?? ( $metrics['Puntaje'] ?? null );

        if ( null === $puntajeValue || '' === $puntajeValue ) {
            return null;
        }

        $raw = is_string( $puntajeValue ) ? $puntajeValue : (string) $puntajeValue;

        // Normalize BEFORE validity enters play, the same way
        // Puntaje::fromDecimal() itself normalizes a comma decimal separator
        // — this is what lets '0', '0.0' and '0,0' all read as the SAME "sin
        // calificar" case, rather than string-comparing against the literal
        // '0' alone (see class docblock for the production incident this
        // closes).
        $normalized = str_replace( ',', '.', $raw );

        if ( is_numeric( $normalized ) && 0.0 === (float) $normalized ) {
            return null;
        }

        try {
            return Puntaje::fromDecimal( $raw );
        } catch ( \InvalidArgumentException $e ) {
            // A genuinely corrupt row must stay VISIBLE, not vanish — see
            // class docblock, "AN OTHERWISE-INVALID STORED VALUE DEGRADES TO
            // NULL TOO, BUT IS LOGGED".
            $this->eventLog->record( 'metrics.puntaje_invalido', [
                'player_id' => $playerId,
                'raw_value' => $raw,
            ] );

            return null;
        }
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

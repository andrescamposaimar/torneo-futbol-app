<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Calendario;

/**
 * Fetches and normalizes partidos from the public Entre Redes REST API
 * (`/ligas`, `/partidos`, `/partidos-programados`) into the exact shape
 * `SeedTemporadaService`'s `$fetcherFn` closure is expected to return.
 *
 * The HTTP call itself is injected as `$httpGetFn` — a callable that takes a
 * full URL and returns the JSON body already decoded into an array — so this
 * class never touches `wp_remote_get()` or the network directly and is fully
 * testable with a stub closure. In production, `$httpGetFn` wraps
 * `wp_remote_get()` + `wp_remote_retrieve_body()` + `json_decode(..., true)`;
 * `tools/dry-run-calendario.php` wraps `file_get_contents()` instead, since it
 * runs outside WordPress entirely.
 *
 * *** THE tiene_resultado LIMITATION — READ BEFORE RELYING ON THIS IN PRODUCTION ***
 * `/partidos` (the `publish` endpoint) NEVER returns a null score: verified
 * against the live API on 2026-09-26, 0 of 270 items had a null
 * `goles_local`/`goles_visitante`. That means this REST endpoint cannot
 * distinguish "0-0, actually played" from "post is published but nobody
 * loaded a result yet". This client therefore assumes every `publish` partido
 * HAS a result (`tiene_resultado = true` unconditionally for every item
 * returned by fetchPartidos()), which is a REAL limitation, not just a TODO
 * comment: a published-but-resultless partido will be reported as resolved
 * when it is not.
 *
 * The DEFINITIVE production fetcher should NOT be this REST client. It should
 * run inside WordPress, read `sp_results` postmeta directly, and apply the
 * canonical rule already implemented for this exact purpose,
 * `entre_redes_partido_tiene_resultado()` (>= 2 equipos con goles cargados),
 * which DOES distinguish a genuine 0-0 from an empty result. This class
 * exists for two purposes only: (1) `tools/dry-run-calendario.php`, which
 * needs to exercise the full seed pipeline without a WordPress environment,
 * and (2) as a reference implementation of the pagination/normalization
 * mechanics for whoever builds the in-WordPress fetcher.
 *
 * See LigaResolver's class docblock for the two-dash trap this class avoids
 * by delegating all liga name -> torneo_label / liga_id resolution to it.
 */
final class PartidosApiClient {

    private const PER_PAGE = 100;

    /** @var callable */
    private $httpGetFn;

    private string $baseUrl;

    private int $publishCount            = 0;
    private int $futureCount             = 0;
    private int $skippedPublishCount     = 0;
    private int $skippedProgramadosCount = 0;

    /** @var array<string, int> liga name -> how many partidos were skipped for it */
    private array $skippedLigas = [];

    /**
     * @param callable $httpGetFn `function( string $url ): array` — returns
     *        the response body already JSON-decoded into an array, and MUST
     *        THROW on any transport failure (non-200, timeout, unparseable
     *        body). It must never swallow a failure and return [].
     *
     *        That rule is the whole point: an earlier version of this contract
     *        asked the closure to return [] on failure, which made a 500, a
     *        timeout and an HTML error page indistinguishable from the end of
     *        the pagination. See fetchPage() for what that cost.
     * @param string $baseUrl e.g. 'https://entreredespadres.com.ar/wp-json/entre-redes/v1'
     */
    public function __construct( callable $httpGetFn, string $baseUrl ) {
        $this->httpGetFn = $httpGetFn;
        $this->baseUrl   = rtrim( $baseUrl, '/' );
    }

    /**
     * Calls `/ligas` (no `temporada` filter — see LigaResolver's docblock for
     * why matching by name already scopes each liga to its season without
     * needing one) and builds the name-based index the rest of this class
     * relies on to resolve `liga_id` and `torneo_label` from a partido's
     * `liga` name string.
     *
     * @return array<string, array{id:int, torneo_label:string}>
     */
    public function fetchLigasIndex(): array {
        $body = ( $this->httpGetFn )( $this->buildUrl( '/ligas', [] ) );

        // An empty index is never legitimate: the tournament always has ligas.
        // Accepting one would be the quietest failure in the system — every
        // partido would resolve to an unknown liga, every one would be skipped,
        // and seed() would receive an empty list and cheerfully do nothing,
        // looking exactly like a week with no new fixture.
        if ( ! is_array( $body ) || [] === $body ) {
            throw new \RuntimeException(
                'Unexpected response from /ligas: expected a non-empty array of ligas.'
            );
        }

        return LigaResolver::index( $body );
    }

    /**
     * Paginates `/partidos?temporada=&per_page=100` (only `publish` — already
     * played — partidos, per the API) until `total_pages` is exhausted, and
     * normalizes every item to the seeder's shape.
     *
     * A partido whose `liga` name is not present in `$ligasIndex` is skipped
     * (not thrown) and counted — see `stats()` — because a brand-new liga
     * appearing mid-season (zonas are re-derived between phases) must not
     * abort the whole fetch; it just means `fetchLigasIndex()` needs
     * re-running once that liga is registered.
     *
     * @param array<string, array{id:int, torneo_label:string}> $ligasIndex
     * @return array<int, array{match_id:int, liga_id:int, zona:string, kickoff:string, tiene_resultado:bool}>
     */
    public function fetchPartidos( int $seasonId, array $ligasIndex ): array {
        $this->publishCount        = 0;
        $this->skippedPublishCount = 0;

        $result = [];
        $page   = 1;
        $totalPages = 1;

        do {
            $pageData = $this->fetchPage( $this->buildUrl( '/partidos', [
                'temporada' => $seasonId,
                'per_page'  => self::PER_PAGE,
                'page'      => $page,
            ] ) );

            foreach ( $pageData['items'] as $item ) {
                $normalized = $this->normalizePartido( (array) $item, $ligasIndex, true );

                if ( null === $normalized ) {
                    $this->skippedPublishCount++;
                    $this->recordSkippedLiga( (array) $item );
                    continue;
                }

                $result[] = $normalized;
            }

            $totalPages = $pageData['total_pages'];
            $page++;
        } while ( $page <= $totalPages );

        $this->publishCount = count( $result );

        return $result;
    }

    /**
     * Paginates `/partidos-programados?per_page=100` (only `future` partidos)
     * until `total_pages` is exhausted. This endpoint does NOT filter by
     * `temporada` (see class-level gotcha), so filtering to the current
     * season happens indirectly: any item whose `liga` name is not present in
     * `$ligasIndex` is skipped and counted — in practice this excludes stray
     * old-season noise, since a genuinely new liga would need to be added to
     * the index anyway before it can be trusted.
     *
     * Every item here comes back with `tiene_resultado = false` — a future
     * partido never has a loaded result by definition.
     *
     * @param array<string, array{id:int, torneo_label:string}> $ligasIndex
     * @return array<int, array{match_id:int, liga_id:int, zona:string, kickoff:string, tiene_resultado:bool}>
     */
    public function fetchProgramados( array $ligasIndex ): array {
        $this->futureCount             = 0;
        $this->skippedProgramadosCount = 0;

        $result = [];
        $page   = 1;
        $totalPages = 1;

        do {
            $pageData = $this->fetchPage( $this->buildUrl( '/partidos-programados', [
                'per_page' => self::PER_PAGE,
                'page'     => $page,
            ] ) );

            foreach ( $pageData['items'] as $item ) {
                $normalized = $this->normalizePartido( (array) $item, $ligasIndex, false );

                if ( null === $normalized ) {
                    $this->skippedProgramadosCount++;
                    $this->recordSkippedLiga( (array) $item );
                    continue;
                }

                $result[] = $normalized;
            }

            $totalPages = $pageData['total_pages'];
            $page++;
        } while ( $page <= $totalPages );

        $this->futureCount = count( $result );

        return $result;
    }

    /**
     * Orchestrates fetchLigasIndex() + fetchPartidos() + fetchProgramados()
     * and returns the combined list, ready to be wrapped in a closure and
     * handed to SeedTemporadaService's constructor as `$fetcherFn`.
     *
     * DEDUPLICATION — THE SATURDAY-NIGHT RACE: fetchPartidos() and
     * fetchProgramados() are two separate HTTP calls, made back to back but
     * not atomically. If a partido's result gets loaded into SportsPress
     * between the two — the exact moment a committee member is entering
     * Saturday night's scores while this fetch is still running — its
     * `match_id` comes back from BOTH calls: `publish` with
     * `tiene_resultado = true` (from fetchPartidos()) AND, because the
     * `future` -> `publish` post-status transition hasn't propagated to
     * `/partidos-programados` yet, `future` with `tiene_resultado = false`
     * (from fetchProgramados()). A plain array_merge() with programados last
     * lets the stale, unresolved copy win: FechaRepository::syncPartidos()
     * would then persist `tiene_resultado = 0` for an actually-played match,
     * the fecha would never derive to 'jugada', and countResolvedFechasSince()
     * would silently under-count — the same business bug commit 1ed1e6a7
     * closed, entering through the other endpoint. dedupeByMatchId() below
     * closes this door: whichever copy carries `tiene_resultado = true` always
     * wins, regardless of which call returned it or which one came last.
     *
     * @return array<int, array{match_id:int, liga_id:int, zona:string, kickoff:string, tiene_resultado:bool}>
     */
    public function fetchAll( int $seasonId ): array {
        $ligasIndex  = $this->fetchLigasIndex();
        $partidos    = $this->fetchPartidos( $seasonId, $ligasIndex );
        $programados = $this->fetchProgramados( $ligasIndex );

        return self::dedupeByMatchId( array_merge( $partidos, $programados ) );
    }

    /**
     * Collapses duplicate `match_id`s into one entry, keeping whichever copy
     * has `tiene_resultado = true` — see fetchAll()'s docblock for the race
     * this guards against. A result loaded into SportsPress is never
     * "un-loaded" by a later duplicate: once a match_id is seen with
     * `tiene_resultado = true`, no subsequent copy (in either direction) can
     * overwrite it back to `false`. Preserves the first-seen order of the
     * winning entries.
     *
     * @param array<int, array{match_id:int, liga_id:int, zona:string, kickoff:string, tiene_resultado:bool}> $partidos
     * @return array<int, array{match_id:int, liga_id:int, zona:string, kickoff:string, tiene_resultado:bool}>
     */
    private static function dedupeByMatchId( array $partidos ): array {
        $byMatchId = [];

        foreach ( $partidos as $partido ) {
            $matchId  = (int) $partido['match_id'];
            $existing = $byMatchId[ $matchId ] ?? null;

            if ( null !== $existing && ! empty( $existing['tiene_resultado'] ) ) {
                continue;
            }

            $byMatchId[ $matchId ] = $partido;
        }

        return array_values( $byMatchId );
    }

    /**
     * Diagnostics from the most recent fetchPartidos()/fetchProgramados()
     * calls — used by tools/dry-run-calendario.php to print the download
     * summary. Not consumed by SeedTemporadaService itself.
     *
     * @return array{publish:int, future:int, skipped_publish:int, skipped_programados:int, skipped_ligas: array<string,int>}
     */
    public function stats(): array {
        return [
            'publish'             => $this->publishCount,
            'future'              => $this->futureCount,
            'skipped_publish'     => $this->skippedPublishCount,
            'skipped_programados' => $this->skippedProgramadosCount,
            'skipped_ligas'       => $this->skippedLigas,
        ];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, array{id:int, torneo_label:string}> $ligasIndex
     * @return array{match_id:int, liga_id:int, zona:string, kickoff:string, tiene_resultado:bool}|null
     *         null when the item's `liga` name is not in $ligasIndex.
     */
    private function normalizePartido( array $item, array $ligasIndex, bool $tieneResultado ): ?array {
        $ligaName  = (string) ( $item['liga'] ?? '' );
        $ligaEntry = $ligasIndex[ $ligaName ] ?? null;

        if ( null === $ligaEntry ) {
            return null;
        }

        return [
            'match_id'        => (int) ( $item['id'] ?? 0 ),
            'liga_id'         => (int) $ligaEntry['id'],
            'zona'            => self::extractZona( $ligaName ),
            'kickoff'         => self::buildKickoff(
                (string) ( $item['fecha'] ?? '' ),
                (string) ( $item['hora'] ?? '' )
            ),
            'tiene_resultado' => $tieneResultado,
        ];
    }

    private function recordSkippedLiga( array $item ): void {
        $ligaName = (string) ( $item['liga'] ?? '(sin liga)' );

        $this->skippedLigas[ $ligaName ] = ( $this->skippedLigas[ $ligaName ] ?? 0 ) + 1;
    }

    /**
     * Extracts the `Zona X` suffix from a liga name (e.g. "2026 - Apertura
     * Zona A" -> "Zona A", "Clasificacion Zona 1" -> "Zona 1"). Falls back to
     * the full liga name when no "Zona ..." token is present, per spec.
     */
    private static function extractZona( string $ligaName ): string {
        if ( preg_match( '/\bZona\s+(\S+)/iu', $ligaName, $matches ) ) {
            return 'Zona ' . $matches[1];
        }

        return $ligaName;
    }

    /**
     * kickoff = "{fecha} {hora}:00", or "{fecha} 00:00:00" when `hora` is
     * empty — per spec, NOT "{fecha} :00" (a bare concatenation would produce
     * an invalid DATETIME).
     */
    private static function buildKickoff( string $fecha, string $hora ): string {
        $time = '' === trim( $hora ) ? '00:00:00' : $hora . ':00';

        return $fecha . ' ' . $time;
    }

    /**
     * @param array<string, int|string> $query Scalar-only query params.
     */
    /**
     * One page of a paginated endpoint, with the envelope shape validated.
     *
     * WHY THIS IS NOT INLINE: the first version of this client read a missing
     * `items` key as "no more pages" and asked its HTTP closure to return []
     * on failure. A 500 on page 2 of 3 therefore ended the loop quietly and
     * produced a partial fixture, and the seeder wrote an incomplete calendar
     * without a single error. Since countResolvedFechasSince() counts
     * rows in that calendar, a silently truncated download corrupts the rule
     * the entire feature rests on — a titular could be told to wait for fechas
     * that were simply never downloaded.
     *
     * A caller that cannot fetch must abort the seed, never half-seed it.
     *
     * @return array{items: array<int, mixed>, total_pages: int}
     */
    private function fetchPage( string $url ): array {
        $body = ( $this->httpGetFn )( $url );

        if ( ! is_array( $body ) || ! isset( $body['items'] ) || ! is_array( $body['items'] ) ) {
            throw new \RuntimeException(
                "Unexpected response shape from {$url}: expected an envelope with an 'items' array."
            );
        }

        return [
            'items'       => $body['items'],
            'total_pages' => max( 1, (int) ( $body['total_pages'] ?? 1 ) ),
        ];
    }

    private function buildUrl( string $path, array $query ): string {
        $url = $this->baseUrl . $path;

        $query = array_filter(
            $query,
            static fn( $value ): bool => null !== $value && '' !== $value
        );

        if ( ! empty( $query ) ) {
            $url .= '?' . http_build_query( $query );
        }

        return $url;
    }
}

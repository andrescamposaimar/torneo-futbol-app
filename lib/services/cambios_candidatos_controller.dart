import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/cambios_candidato.dart';
import 'cambios_api_service.dart';

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

/// State machine for ONE section's candidate list on "Pedir cambio" (either
/// [CambiosCandidatosSeccion.listaEspera] or
/// [CambiosCandidatosSeccion.padronCompleto]), scoped to a single plaza.
/// Re-created per (plaza, seccion) via a Riverpod family provider (see
/// `cambios_providers.dart`), so [load] always starts fresh — no re-entry
/// guard like the session-persistent Prode controllers.
sealed class CambiosCandidatosState {
  const CambiosCandidatosState();
}

/// Not yet requested — the LAZY section (`padronCompleto`) starts here and
/// stays here until the captain actually opens it (see
/// `cambios_providers.dart`'s own docblock, "autoLoad"). The eager section
/// (`listaEspera`) never visits this state: its provider calls [load]
/// immediately on creation, exactly like before these two sections existed.
final class CambiosCandidatosIdle extends CambiosCandidatosState {
  const CambiosCandidatosIdle();
}

final class CambiosCandidatosLoading extends CambiosCandidatosState {
  const CambiosCandidatosLoading();
}

final class CambiosCandidatosError extends CambiosCandidatosState {
  const CambiosCandidatosError();
}

/// [candidatos] is every row loaded SO FAR — page 1 through whichever page
/// [CambiosCandidatosController.loadMore] last appended — never the whole
/// server-side population at once (see that controller's own docblock).
/// [query]/[puntajes] are the filters THIS list was fetched with, so the
/// screen can tell whether a section's already-loaded state still matches
/// the currently active search/chips before deciding to re-fetch (see
/// `cambios_solicitar_screen.dart`'s `_onSeccionChanged`).
final class CambiosCandidatosLoaded extends CambiosCandidatosState {
  final List<CambiosCandidato> candidatos;
  final String query;
  final List<double> puntajes;

  /// Whether the server reports more candidates beyond [candidatos] — see
  /// `CambiosCandidatosPagina`'s own docblock for why this is total/page
  /// math, never "did the last page come back full": a page can
  /// legitimately return fewer than its own `per_page` once the
  /// viable-only filter runs, while more pages still remain.
  final bool hasMore;

  /// `true` while [CambiosCandidatosController.loadMore] has an in-flight
  /// request for the NEXT page — the screen renders a small spinner row at
  /// the bottom of the list while this is true, distinct from the
  /// full-screen [CambiosCandidatosLoading] the INITIAL fetch shows.
  final bool isLoadingMore;

  /// `true` when the MOST RECENT [CambiosCandidatosController.loadMore] call
  /// failed — distinct from [CambiosCandidatosError] (which only ever
  /// replaces the WHOLE list on a failed [CambiosCandidatosController.load]):
  /// there is something real already on screen here, so a failed next-page
  /// fetch must render its own retry affordance at the bottom of the list
  /// instead of silently dropping the spinner, which would be
  /// indistinguishable from "that's the whole list" to a captain who stops
  /// scrolling right there. Always `false` again the instant a fresh
  /// [CambiosCandidatosController.load] or a successful
  /// [CambiosCandidatosController.loadMore] replaces this state.
  final bool loadMoreError;

  const CambiosCandidatosLoaded({
    required this.candidatos,
    required this.query,
    this.puntajes = const [],
    this.hasMore = false,
    this.isLoadingMore = false,
    this.loadMoreError = false,
  });
}

// ---------------------------------------------------------------------------
// Controller
// ---------------------------------------------------------------------------

/// Drives ONE section's candidate list as infinite scroll against
/// `GET /cambios/plazas/candidatos` — the backend now ALWAYS paginates (see
/// `Rest\PlazasController::listarCandidatos()`'s own docblock,
/// "PAGINATION"), so this controller owns the page cursor instead of the
/// screen loading everything once and filtering client-side via
/// `PlayerFilterService`, as it did before this slice.
///
/// *** WHY SEARCH/PUNTAJE FILTERING IS NO LONGER LOCAL ***
/// `?search=`/`?puntajes[]=` now narrow the POPULATION on the server, BEFORE
/// pagination (see `Plazas\CandidatosResolver::buscarPaginado()`'s own
/// docblock on the backend) — a client-side filter over only the
/// already-loaded pages would silently miss a match that lives on a page
/// not yet fetched. [load] therefore always starts a FRESH page-1 fetch
/// with whatever [query]/[puntajes] the caller passes, never filters
/// [state] locally.
class CambiosCandidatosController extends StateNotifier<CambiosCandidatosState> {
  final CambiosApiService _service;
  final int seasonId;
  final int teamId;
  final int plazaId;
  final CambiosCandidatosSeccion seccion;

  /// Matches the backend's own default (`Rest\PlazasController::
  /// DEFAULT_PER_PAGE`) — kept in sync by convention, not by a shared
  /// constant, since the two live in different languages/repos; either side
  /// changing this independently only affects how many rows one network
  /// round trip returns, never correctness.
  static const int _perPage = 20;

  /// *** WHY A PAGE CAN STILL COME BACK EMPTY WHILE MORE PAGES REMAIN ***
  /// Even after `Plazas\CandidatosResolver::buscarPaginado()` stopped
  /// letting an over-ceiling candidate fill a page (see that method's own
  /// docblock, "THE CEILING IS A POPULATION FILTER, NOT A PER-PAGE
  /// VERDICT"), a page can still legitimately return FEWER items than
  /// `_perPage` — or zero — because the backend's own phase-2 viability
  /// check (occupying another plaza, blocked by a trunca closure elsewhere)
  /// still runs PER PAGE, after pagination (see that method's own docblock,
  /// "`$total` IS THEREFORE VERY SLIGHTLY OPTIMISTIC"). An empty page is
  /// therefore NOT by itself proof the list is exhausted — only `hasMore`
  /// (computed from the server's own total/page math, see [hasMoreFor]) is.
  /// [maxConsecutiveEmptyPages] is the hard bound against the pathological
  /// case: a server that keeps reporting `hasMore: true` while returning
  /// empty page after empty page must still make this controller STOP,
  /// rather than fetch forever chasing a row that never comes — see
  /// `_fetchSkippingEmptyPages`'s own docblock for how the bound is applied.
  /// Public (not private) so the test suite can drive exactly this many
  /// empty pages without guessing a magic number.
  @visibleForTesting
  static const int maxConsecutiveEmptyPages = 3;

  int _page = 1;

  /// Monotonically increasing request-generation counter. Captured into a
  /// local the instant [load] or [loadMore] starts a fetch, then checked
  /// again right before that fetch's result is allowed to touch [state] or
  /// [_page] — a call whose captured generation no longer matches
  /// [_requestGeneration] by the time its future resolves is STALE and must
  /// abandon its result instead of applying it.
  ///
  /// *** WHY THIS EXISTS *** Both [load] and [loadMore] `await` a network
  /// call and then assign unconditionally. With no ordering guard, a
  /// slower, OLDER request resolving after a newer one started (e.g. a
  /// `loadMore()` still in flight when a debounced search re-triggers
  /// `load()`, or two overlapping `load()` calls from a search box and an
  /// undebounced puntaje chip toggle racing each other) would silently
  /// overwrite the newer, still-correct state with results for filters the
  /// screen no longer shows — see this slice's own task brief for the exact
  /// captain-facing symptom. Every call that starts a fetch increments this
  /// counter FIRST, so it alone decides "am I still the latest".
  int _requestGeneration = 0;

  /// [autoLoad] only decides this controller's INITIAL state — `true`
  /// starts it as [CambiosCandidatosLoading] (the caller is expected to call
  /// [load] right after construction, same as before these two sections
  /// existed); `false` starts it as [CambiosCandidatosIdle] and leaves
  /// fetching entirely to whoever calls [load] later (the lazy
  /// `padronCompleto` section — see `cambios_providers.dart`).
  CambiosCandidatosController(
    this._service, {
    required this.seasonId,
    required this.teamId,
    required this.plazaId,
    required this.seccion,
    bool autoLoad = true,
  }) : super(autoLoad ? const CambiosCandidatosLoading() : const CambiosCandidatosIdle());

  /// Fetches page 1 of this section's candidate list, narrowed server-side
  /// by [query] (`?search=`) and [puntajes] (`?puntajes[]=`). ALWAYS resets
  /// to page 1 and REPLACES [state] — the right call whenever the filters
  /// themselves changed (a debounced search keystroke, a puntaje chip
  /// toggle, or the first time a lazy section is opened). Use [loadMore] to
  /// append the NEXT page of the SAME search instead.
  ///
  /// *** AN EMPTY PAGE 1 IS NOT, BY ITSELF, "NO CANDIDATES" *** See
  /// `_fetchSkippingEmptyPages`'s own docblock for why: this delegates to it
  /// starting at page 1, so [state] stays [CambiosCandidatosLoading] — never
  /// the misleading empty state — for as long as the server keeps reporting
  /// `hasMore: true` on an empty page, up to [maxConsecutiveEmptyPages].
  Future<void> load({String query = '', List<double> puntajes = const []}) async {
    final generation = ++_requestGeneration;
    state = const CambiosCandidatosLoading();
    _page = 1;
    try {
      final resultado = await _fetchSkippingEmptyPages(
        generation: generation,
        fromPage: _page,
        query: query,
        puntajes: puntajes,
      );
      // A newer load()/loadMore() already started while this one was in
      // flight — see [_requestGeneration]'s own docblock, and
      // `_fetchSkippingEmptyPages`'s own `stale` field. Abandon this result;
      // the newer call owns [state] now.
      if (resultado.stale || generation != _requestGeneration) return;
      _page = resultado.page;
      state = CambiosCandidatosLoaded(
        candidatos: resultado.candidatos,
        query: query,
        puntajes: puntajes,
        hasMore: resultado.hasMore,
      );
    } catch (_) {
      if (generation != _requestGeneration) return;
      state = const CambiosCandidatosError();
    }
  }

  /// Appends the NEXT page of the CURRENT search (same [query]/[puntajes]
  /// already in [state]) — called by the screen's scroll listener as the
  /// captain nears the bottom of the list. A no-op when [state] is not
  /// [CambiosCandidatosLoaded], when the server already reported no more
  /// pages (`hasMore == false`), or when a previous [loadMore] call is still
  /// in flight — guards against the scroll listener firing more than once
  /// for the same approach to the bottom.
  ///
  /// A failed page fetch keeps whatever was ALREADY loaded — unlike [load],
  /// there is something real to lose here, so this degrades to
  /// `isLoadingMore: false` + `loadMoreError: true` (see
  /// [CambiosCandidatosLoaded.loadMoreError]'s own docblock for why that
  /// flag exists), never to [CambiosCandidatosError] (that would blank out
  /// a list the captain was already looking at). Calling [loadMore] again —
  /// the screen's own retry affordance, or simply scrolling back to the
  /// bottom — clears the error the same way a successful fetch would.
  ///
  /// Same empty-page continuation as [load] — see
  /// `_fetchSkippingEmptyPages`'s own docblock — except there is already
  /// something real on screen here, so continuation happens silently behind
  /// `isLoadingMore: true` rather than by staying in a full-screen loading
  /// state.
  Future<void> loadMore() async {
    final current = state;
    if (current is! CambiosCandidatosLoaded) return;
    if (!current.hasMore || current.isLoadingMore) return;

    final generation = ++_requestGeneration;

    state = CambiosCandidatosLoaded(
      candidatos: current.candidatos,
      query: current.query,
      puntajes: current.puntajes,
      hasMore: current.hasMore,
      isLoadingMore: true,
    );

    final nextPage = _page + 1;
    try {
      final resultado = await _fetchSkippingEmptyPages(
        generation: generation,
        fromPage: nextPage,
        query: current.query,
        puntajes: current.puntajes,
      );
      // A newer load()/loadMore() already started while this one was in
      // flight — see [_requestGeneration]'s own docblock. Abandon this
      // result (including the page-cursor advance below): applying it now
      // would merge a page fetched for a query the screen no longer shows
      // into whatever the newer call already rendered.
      if (resultado.stale || generation != _requestGeneration) return;
      _page = resultado.page;
      state = CambiosCandidatosLoaded(
        candidatos: [...current.candidatos, ...resultado.candidatos],
        query: current.query,
        puntajes: current.puntajes,
        hasMore: resultado.hasMore,
      );
    } catch (_) {
      if (generation != _requestGeneration) return;
      state = CambiosCandidatosLoaded(
        candidatos: current.candidatos,
        query: current.query,
        puntajes: current.puntajes,
        hasMore: current.hasMore,
        isLoadingMore: false,
        loadMoreError: true,
      );
    }
  }

  /// Fetches starting at [fromPage], transparently skipping forward through
  /// any EMPTY page the server returns while it still reports `hasMore:
  /// true` — shared by [load] (from page 1) and [loadMore] (from the next
  /// page), so neither ever has to render the empty state, or append
  /// nothing, for a page that was stripped empty by the backend's own
  /// per-page phase-2 viability check (occupying another plaza, blocked by a
  /// trunca closure elsewhere — see `Plazas\CandidatosResolver::
  /// buscarPaginado()`'s own docblock on the backend, "`$total` IS
  /// THEREFORE VERY SLIGHTLY OPTIMISTIC"). This is the app-side half of the
  /// same production incident the backend's own ceiling-as-population-filter
  /// fix addresses: EITHER side returning an empty page while more pages
  /// plausibly remain must never read as "no candidates" to the captain.
  ///
  /// *** THE CAP — WHY AN UNBOUNDED LOOP WOULD BE WORSE THAN THE BUG ***
  /// A server that kept returning empty pages while reporting `hasMore:
  /// true` forever (a bug, a misconfigured per_page, or simply a VERY long
  /// stretch of phase-2 non-viable candidates) would make this loop fetch
  /// forever if left unbounded — turning "a short delay before the list
  /// renders" into "the screen never renders at all", which is a worse
  /// failure than the empty state this method exists to avoid. After
  /// [maxConsecutiveEmptyPages] CONSECUTIVE empty pages, this method stops
  /// and returns whatever it has (empty, if every page tried was empty) —
  /// [load]/[loadMore] then render that as a normal terminal state (the
  /// empty view if genuinely nothing came back, same as before this
  /// continuation existed), never a hang. A page that eventually comes back
  /// NON-empty resets the streak implicitly by ending the loop right there.
  ///
  /// *** STALENESS IS CHECKED INSIDE THE LOOP, NOT JUST AFTER IT *** Each
  /// iteration `await`s a real network call — the one point where a NEWER
  /// [load]/[loadMore] call can start and bump [_requestGeneration] (see
  /// that field's own docblock). Checking only once, after this method
  /// returns, would let this loop keep burning network requests for filters
  /// the screen no longer shows; checking after EVERY page keeps a stale
  /// chase as short as a single extra request.
  ///
  /// @return [stale] `true` means [generation] no longer matches
  ///         [_requestGeneration] — the caller must discard [candidatos]
  ///         entirely rather than apply it (same discipline [load]/
  ///         [loadMore] already applied to a single-page result before this
  ///         method existed). [page] is the LAST page this method actually
  ///         fetched (whether or not it was empty) — the caller's new
  ///         `_page` cursor. [hasMore] is [hasMoreFor] over that last page.
  Future<
      ({
        List<CambiosCandidato> candidatos,
        int page,
        bool hasMore,
        bool stale,
      })> _fetchSkippingEmptyPages({
    required int generation,
    required int fromPage,
    required String query,
    required List<double> puntajes,
  }) async {
    var page = fromPage;
    var consecutiveEmptyPages = 0;

    while (true) {
      final pagina = await _service.fetchCandidatos(
        seasonId: seasonId,
        teamId: teamId,
        plazaId: plazaId,
        seccion: seccion,
        search: query.isEmpty ? null : query,
        puntajes: puntajes,
        page: page,
        perPage: _perPage,
      );

      if (generation != _requestGeneration) {
        return (candidatos: const <CambiosCandidato>[], page: page, hasMore: false, stale: true);
      }

      final hasMore = hasMoreFor(
        loadedCount: page * _perPage,
        total: pagina.total,
        perPage: _perPage,
        lastPageCount: pagina.candidatos.length,
      );

      if (pagina.candidatos.isNotEmpty || !hasMore) {
        return (candidatos: pagina.candidatos, page: page, hasMore: hasMore, stale: false);
      }

      consecutiveEmptyPages++;
      if (consecutiveEmptyPages >= maxConsecutiveEmptyPages) {
        return (candidatos: const <CambiosCandidato>[], page: page, hasMore: hasMore, stale: false);
      }

      page++;
    }
  }
}

/// Whether more pages plausibly remain beyond the page(s) already loaded.
///
/// When [total] is known (the server sent `X-WP-Total`), this is exact
/// page/per-page math: [loadedCount] is `page * perPage` — how many items
/// have been CONSUMED from the server's pre-filter population, the same
/// quantity [total] itself measures (see [CambiosCandidatosPagina]'s own
/// docblock) — deliberately never `candidatos.length`, which can be smaller
/// once the viable-only filter runs on the backend (see
/// `CambiosCandidatosLoaded.hasMore`'s own docblock for why a short page
/// does not by itself mean the end of the list).
///
/// When [total] is `null` — the header was missing or unparseable, see
/// [CambiosCandidatosPagina]'s own docblock for why that must NEVER be
/// papered over with a fabricated total — "unknown" stays honestly unknown:
/// this falls back to the one signal that is still true regardless, "did
/// the page we just got come back FULL". A full page ([lastPageCount] ==
/// [perPage]) means there MAY be more, so [hasMoreFor] optimistically
/// returns `true` and lets the next [CambiosCandidatosController.loadMore]
/// attempt find out; a SHORT page (fewer than [perPage] items) is the one
/// case the server can never lie about via a missing header — a short page
/// is only possible because the population genuinely ran out — so this
/// returns `false`.
bool hasMoreFor({
  required int loadedCount,
  required int? total,
  required int perPage,
  int? lastPageCount,
}) {
  if (total != null) return loadedCount < total;
  return (lastPageCount ?? 0) >= perPage;
}

/// Whether [a] and [b] are the SAME filters — used by
/// `cambios_solicitar_screen.dart`'s `_onSeccionChanged` to decide whether
/// switching to an already-loaded section needs a re-fetch (the active
/// search/chips changed since that section last loaded) or not (switching
/// back and forth with nothing changed must never re-fetch).
bool mismosFiltros(String queryA, List<double> puntajesA, String queryB, List<double> puntajesB) {
  return queryA == queryB && listEquals(puntajesA, puntajesB);
}

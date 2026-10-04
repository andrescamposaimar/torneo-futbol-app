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
  Future<void> load({String query = '', List<double> puntajes = const []}) async {
    final generation = ++_requestGeneration;
    state = const CambiosCandidatosLoading();
    _page = 1;
    try {
      final pagina = await _service.fetchCandidatos(
        seasonId: seasonId,
        teamId: teamId,
        plazaId: plazaId,
        seccion: seccion,
        search: query.isEmpty ? null : query,
        puntajes: puntajes,
        page: _page,
        perPage: _perPage,
      );
      // A newer load()/loadMore() already started while this one was in
      // flight — see [_requestGeneration]'s own docblock. Abandon this
      // result; the newer call owns [state] now.
      if (generation != _requestGeneration) return;
      state = CambiosCandidatosLoaded(
        candidatos: pagina.candidatos,
        query: query,
        puntajes: puntajes,
        hasMore: hasMoreFor(
          loadedCount: _page * _perPage,
          total: pagina.total,
          perPage: _perPage,
          lastPageCount: pagina.candidatos.length,
        ),
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
      final pagina = await _service.fetchCandidatos(
        seasonId: seasonId,
        teamId: teamId,
        plazaId: plazaId,
        seccion: seccion,
        search: current.query.isEmpty ? null : current.query,
        puntajes: current.puntajes,
        page: nextPage,
        perPage: _perPage,
      );
      // A newer load()/loadMore() already started while this one was in
      // flight — see [_requestGeneration]'s own docblock. Abandon this
      // result (including the page-cursor advance below): applying it now
      // would merge a page fetched for a query the screen no longer shows
      // into whatever the newer call already rendered.
      if (generation != _requestGeneration) return;
      _page = nextPage;
      state = CambiosCandidatosLoaded(
        candidatos: [...current.candidatos, ...pagina.candidatos],
        query: current.query,
        puntajes: current.puntajes,
        hasMore: hasMoreFor(
          loadedCount: _page * _perPage,
          total: pagina.total,
          perPage: _perPage,
          lastPageCount: pagina.candidatos.length,
        ),
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

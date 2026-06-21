import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/prediction_history.dart';
import 'prode_api_service.dart';

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

/// Lifecycle phase of the "Anteriores" (past predictions) list.
enum ProdeHistoryPhase { loading, ready, error }

/// Immutable state for the paginated "Anteriores" history list.
///
/// Unlike the sealed states elsewhere in this feature, an accumulating
/// infinite-scroll list is modelled best as a single immutable record with a
/// [phase] discriminator plus load-more flags — sealed variants would force the
/// growing [items] list to be threaded through every transition.
class ProdeHistoryState {
  final ProdeHistoryPhase phase;

  /// All entries loaded so far, oldest page first (server returns newest-first
  /// within each page, so the concatenation stays globally newest-first).
  final List<PredictionHistoryEntry> items;

  /// Whether more pages exist beyond what is loaded.
  final bool hasMore;

  /// Count of pages successfully loaded (the next fetch is loadedPages + 1).
  final int loadedPages;

  /// A loadMore() request is in flight (drives the bottom spinner).
  final bool isLoadingMore;

  /// The last loadMore() failed (drives an inline retry affordance).
  final bool loadMoreFailed;

  const ProdeHistoryState({
    this.phase = ProdeHistoryPhase.loading,
    this.items = const [],
    this.hasMore = false,
    this.loadedPages = 0,
    this.isLoadingMore = false,
    this.loadMoreFailed = false,
  });

  /// Loaded successfully but the user has no past predictions.
  bool get isEmpty => phase == ProdeHistoryPhase.ready && items.isEmpty;

  ProdeHistoryState copyWith({
    ProdeHistoryPhase? phase,
    List<PredictionHistoryEntry>? items,
    bool? hasMore,
    int? loadedPages,
    bool? isLoadingMore,
    bool? loadMoreFailed,
  }) {
    return ProdeHistoryState(
      phase: phase ?? this.phase,
      items: items ?? this.items,
      hasMore: hasMore ?? this.hasMore,
      loadedPages: loadedPages ?? this.loadedPages,
      isLoadingMore: isLoadingMore ?? this.isLoadingMore,
      loadMoreFailed: loadMoreFailed ?? this.loadMoreFailed,
    );
  }
}

// ---------------------------------------------------------------------------
// Controller
// ---------------------------------------------------------------------------

/// State machine for the "Anteriores" infinite-scroll history list.
///
/// Loads pages of [pageSize] (15) from `GET /prode/predicciones`. [load] fetches
/// the first page (guarded against redundant re-entry once ready), [loadMore]
/// appends the next page when the user scrolls near the bottom, and [refresh]
/// re-fetches from page 1 keeping the existing list visible on failure.
///
/// NOT autoDispose (session-persistent, like the other Prode controllers).
class ProdeHistoryController extends StateNotifier<ProdeHistoryState> {
  final ProdeApiService _service;

  static const int pageSize = 15;

  ProdeHistoryController(this._service) : super(const ProdeHistoryState());

  /// Loads the first page. No-op once a non-empty list is already ready, so
  /// re-entering the screen does not refetch (mirrors the other controllers).
  Future<void> load() async {
    if (state.phase == ProdeHistoryPhase.ready && state.items.isNotEmpty) {
      return;
    }
    state = const ProdeHistoryState(phase: ProdeHistoryPhase.loading);
    try {
      final page =
          await _service.fetchPredictionHistory(page: 1, perPage: pageSize);
      state = ProdeHistoryState(
        phase: ProdeHistoryPhase.ready,
        items: page.items,
        hasMore: page.hasMore,
        loadedPages: 1,
      );
    } catch (_) {
      state = const ProdeHistoryState(phase: ProdeHistoryPhase.error);
    }
  }

  /// Re-fetches from page 1. Keeps the current list visible if the refresh
  /// fails and the list is non-empty (only flips to error from an empty list).
  Future<void> refresh() async {
    try {
      final page =
          await _service.fetchPredictionHistory(page: 1, perPage: pageSize);
      state = ProdeHistoryState(
        phase: ProdeHistoryPhase.ready,
        items: page.items,
        hasMore: page.hasMore,
        loadedPages: 1,
      );
    } catch (_) {
      if (state.items.isEmpty) {
        state = const ProdeHistoryState(phase: ProdeHistoryPhase.error);
      }
      // else: keep the existing list under the RefreshIndicator.
    }
  }

  /// Appends the next page. No-op when already loading more, when there are no
  /// more pages, or before the first page has loaded.
  Future<void> loadMore() async {
    if (state.isLoadingMore ||
        !state.hasMore ||
        state.phase != ProdeHistoryPhase.ready) {
      return;
    }
    state = state.copyWith(isLoadingMore: true, loadMoreFailed: false);
    try {
      final next = state.loadedPages + 1;
      final page =
          await _service.fetchPredictionHistory(page: next, perPage: pageSize);
      state = state.copyWith(
        items: [...state.items, ...page.items],
        hasMore: page.hasMore,
        loadedPages: next,
        isLoadingMore: false,
      );
    } catch (_) {
      state = state.copyWith(isLoadingMore: false, loadMoreFailed: true);
    }
  }
}

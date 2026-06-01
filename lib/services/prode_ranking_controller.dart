import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/prode_ranking.dart';
import 'prode_api_service.dart';

// ---------------------------------------------------------------------------
// Sealed state
// ---------------------------------------------------------------------------

/// Discriminated state for the Prode Ranking screen.
///
/// Mirrors [ProdeFixturesState]'s sealed-class idiom exactly.
/// Each variant is immutable, const-constructible, and carries only the
/// payload it owns.
sealed class ProdeRankingState {
  const ProdeRankingState();
}

/// Initial state and the state entered at the start of every [ProdeRankingController.load] call
/// when the caller was not already Loaded.
final class ProdeRankingLoading extends ProdeRankingState {
  const ProdeRankingLoading();

  @override
  bool operator ==(Object other) =>
      identical(this, other) || other is ProdeRankingLoading;

  @override
  int get hashCode => runtimeType.hashCode;

  @override
  String toString() => 'ProdeRankingLoading()';
}

/// Successfully loaded: the ranking page is available.
///
/// Carries the entire [RankingPage] envelope so the screen can display
/// total/pagination metadata if needed in a future G6 slice.
final class ProdeRankingLoaded extends ProdeRankingState {
  final RankingPage page;

  const ProdeRankingLoaded(this.page);

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ProdeRankingLoaded &&
          runtimeType == other.runtimeType &&
          page == other.page;

  @override
  int get hashCode => Object.hash(runtimeType, page);

  @override
  String toString() =>
      'ProdeRankingLoaded(items: ${page.items.length}, total: ${page.total})';
}

/// The backend returned an empty items list (HTTP 200 but no entries yet).
///
/// Distinct from [ProdeRankingError] — an empty response is an expected
/// condition (no predictions evaluated yet), not a transport failure.
/// The UI surfaces a neutral "nothing yet" message.
final class ProdeRankingEmpty extends ProdeRankingState {
  const ProdeRankingEmpty();

  @override
  bool operator ==(Object other) =>
      identical(this, other) || other is ProdeRankingEmpty;

  @override
  int get hashCode => runtimeType.hashCode;

  @override
  String toString() => 'ProdeRankingEmpty()';
}

/// A transport or server error occurred.
///
/// [code] is machine-readable; [message] is diagnostic and MUST NOT be shown
/// raw in the UI (the screen shows a generic friendly copy instead).
final class ProdeRankingError extends ProdeRankingState {
  final String code;
  final String message;

  const ProdeRankingError({required this.code, required this.message});

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ProdeRankingError &&
          runtimeType == other.runtimeType &&
          code == other.code &&
          message == other.message;

  @override
  int get hashCode => Object.hash(runtimeType, code, message);

  @override
  String toString() =>
      'ProdeRankingError(code: $code, message: $message)';
}

// ---------------------------------------------------------------------------
// Controller
// ---------------------------------------------------------------------------

/// State machine for the Prode Ranking screen.
///
/// Mirrors [ProdeFixturesController]'s StateNotifier idiom:
///   - NOT autoDispose (state persists for the session; re-entry while Loaded
///     does NOT auto-refetch — the user must pull-to-refresh).
///   - [load] fetches the ranking and drives Loading → Loaded/Empty/Error.
///     Guard: no-op when the current state is not [ProdeRankingLoading].
///   - [refresh] re-fetches WITHOUT pre-setting Loading when coming from a
///     Loaded state — keeps the last list visible under the RefreshIndicator.
///
/// Anonymous load note: [fetchRanking] is an optionalAuth endpoint. Callers
/// with no session token will receive all rows with `is_me: false`. The
/// controller never touches auth machinery and NEVER emits [ProdeAuthRequired].
///
/// Constructor injects [ProdeApiService], keeping the controller testable
/// via a real service + MockClient (same pattern as ProdeFixturesController).
class ProdeRankingController
    extends StateNotifier<ProdeRankingState> {
  final ProdeApiService _service;

  ProdeRankingController(this._service)
      : super(const ProdeRankingLoading());

  // ---------------------------------------------------------------------------
  // Public API
  // ---------------------------------------------------------------------------

  /// Fetches the ranking from the backend.
  ///
  /// Guard: if the current state is NOT [ProdeRankingLoading], the call is
  /// a no-op — prevents a redundant network round-trip when the screen is
  /// re-entered while already Loaded/Empty/Error. The user can force a fresh
  /// fetch via [refresh].
  Future<void> load() async {
    if (state is! ProdeRankingLoading) return;
    // state is already Loading (initial) — no need to set it again.
    await _fetch(keepCurrentOnStart: false);
  }

  /// Re-fetches the ranking.
  ///
  /// Unlike [load], this does NOT pre-set Loading when the current state is
  /// [ProdeRankingLoaded] — keeps the existing list visible under the
  /// RefreshIndicator while the network call is in flight.
  ///
  /// When called from any other state the behaviour mirrors [load].
  Future<void> refresh() async {
    await _fetch(keepCurrentOnStart: state is ProdeRankingLoaded);
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  Future<void> _fetch({required bool keepCurrentOnStart}) async {
    if (!keepCurrentOnStart) {
      state = const ProdeRankingLoading();
    }
    try {
      final page = await _service.fetchRanking();
      state = page.items.isEmpty
          ? const ProdeRankingEmpty()
          : ProdeRankingLoaded(page);
    } on ProdeApiException catch (e) {
      state = ProdeRankingError(
        code: 'ranking_${e.statusCode}',
        message: e.toString(),
      );
    } catch (e) {
      state = ProdeRankingError(
        code: 'ranking_error',
        message: e.toString(),
      );
    }
  }
}

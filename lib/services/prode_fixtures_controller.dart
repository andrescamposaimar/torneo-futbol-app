import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/fecha_activa.dart';
import 'prode_api_service.dart';

// ---------------------------------------------------------------------------
// PredictionDraft value class + SubmitStatus enum
// ---------------------------------------------------------------------------

/// Lifecycle of a per-match prediction submission.
enum SubmitStatus {
  /// No network call in flight; inputs are editable.
  idle,

  /// POST in flight; submit button disabled to prevent double-submit.
  submitting,

  /// POST completed with HTTP 200; inputs remain editable for corrections.
  submitted,

  /// POST failed (locked, validation, network); inputs remain editable.
  error,
}

/// Immutable draft holding the user's current score inputs and submission status
/// for a single match.
///
/// The source-of-truth for score inputs lives here (in the Riverpod controller
/// state), not in the widget's [TextEditingController]. The widget seeds its
/// controllers from this value on build/rebuild, ensuring scores survive scroll
/// recycling.
class PredictionDraft {
  final int? scoreHome;
  final int? scoreAway;
  final SubmitStatus status;

  const PredictionDraft({
    this.scoreHome,
    this.scoreAway,
    this.status = SubmitStatus.idle,
  });

  /// Returns a new [PredictionDraft] with the provided fields overridden.
  /// Any field not supplied keeps its current value.
  PredictionDraft copyWith({
    int? scoreHome,
    int? scoreAway,
    SubmitStatus? status,
    // Explicit null sentinels so callers can clear a score with copyWith(scoreHome: null).
    bool clearScoreHome = false,
    bool clearScoreAway = false,
  }) {
    return PredictionDraft(
      scoreHome: clearScoreHome ? null : (scoreHome ?? this.scoreHome),
      scoreAway: clearScoreAway ? null : (scoreAway ?? this.scoreAway),
      status: status ?? this.status,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is PredictionDraft &&
          runtimeType == other.runtimeType &&
          scoreHome == other.scoreHome &&
          scoreAway == other.scoreAway &&
          status == other.status;

  @override
  int get hashCode => Object.hash(scoreHome, scoreAway, status);

  @override
  String toString() =>
      'PredictionDraft(scoreHome: $scoreHome, scoreAway: $scoreAway, '
      'status: $status)';
}

// ---------------------------------------------------------------------------
// Sealed state
// ---------------------------------------------------------------------------

/// Discriminated state for the Prode Fixtures screen.
///
/// Mirrors [ProdeAuthState]'s sealed-class idiom. Each variant is immutable,
/// const-constructible, and carries only the payload it owns.
sealed class ProdeFixturesState {
  const ProdeFixturesState();
}

/// Initial state and the state entered at the start of every [load] call.
final class ProdeFixturesLoading extends ProdeFixturesState {
  const ProdeFixturesLoading();

  @override
  bool operator ==(Object other) =>
      identical(this, other) || other is ProdeFixturesLoading;

  @override
  int get hashCode => runtimeType.hashCode;

  @override
  String toString() => 'ProdeFixturesLoading()';
}

/// Successfully loaded: an active fecha is available.
///
/// [drafts] is a map from match_id to [PredictionDraft], seeded on load from
/// [FechaActiva.userPredictions]. Matches without a stored prediction get an
/// empty draft (null scores, idle status). This is the single source of truth
/// for score inputs — widgets re-seed their [TextEditingController]s from here.
final class ProdeFixturesLoaded extends ProdeFixturesState {
  final FechaActiva fecha;
  final Map<int, PredictionDraft> drafts;

  const ProdeFixturesLoaded(this.fecha, {this.drafts = const {}});

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ProdeFixturesLoaded &&
          runtimeType == other.runtimeType &&
          fecha == other.fecha &&
          _mapsEqual(drafts, other.drafts);

  static bool _mapsEqual(
      Map<int, PredictionDraft> a, Map<int, PredictionDraft> b) {
    if (a.length != b.length) return false;
    for (final key in a.keys) {
      if (a[key] != b[key]) return false;
    }
    return true;
  }

  @override
  int get hashCode => Object.hash(runtimeType, fecha, Object.hashAll(drafts.values));

  @override
  String toString() =>
      'ProdeFixturesLoaded(fecha: ${fecha.fechaId}, drafts: ${drafts.length})';
}

/// The backend returned 404: no active fecha for this tenant right now.
///
/// Distinct from [ProdeFixturesError] — a 404 is an expected condition, not
/// a transport failure. The UI surfaces a neutral "nothing active" message.
final class ProdeFixturesEmpty extends ProdeFixturesState {
  const ProdeFixturesEmpty();

  @override
  bool operator ==(Object other) =>
      identical(this, other) || other is ProdeFixturesEmpty;

  @override
  int get hashCode => runtimeType.hashCode;

  @override
  String toString() => 'ProdeFixturesEmpty()';
}

/// A transport or server error occurred.
///
/// [code] is machine-readable; [message] is diagnostic and MUST NOT be shown
/// raw in the UI (the screen shows a generic friendly copy instead).
final class ProdeFixturesError extends ProdeFixturesState {
  final String code;
  final String message;

  const ProdeFixturesError({required this.code, required this.message});

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ProdeFixturesError &&
          runtimeType == other.runtimeType &&
          code == other.code &&
          message == other.message;

  @override
  int get hashCode => Object.hash(runtimeType, code, message);

  @override
  String toString() =>
      'ProdeFixturesError(code: $code, message: $message)';
}

// ---------------------------------------------------------------------------
// Controller
// ---------------------------------------------------------------------------

/// State machine for the Prode Fixtures screen.
///
/// Mirrors [ProdeAuthController]'s StateNotifier idiom:
///   - NOT autoDispose (state persists for the session; re-entry while Loaded
///     does NOT auto-refetch).
///   - [load] fetches the active fecha and drives Loading → Loaded/Empty/Error.
///   - [refresh] re-fetches without pre-setting Loading when coming from a
///     Loaded state (keeps the last list visible under the RefreshIndicator).
///
/// Constructor receives a [ProdeApiService] injected by the provider, keeping
/// the controller testable with a real service + MockClient.
class ProdeFixturesController
    extends StateNotifier<ProdeFixturesState> {
  final ProdeApiService _service;

  ProdeFixturesController(this._service)
      : super(const ProdeFixturesLoading());

  // ---------------------------------------------------------------------------
  // Public API
  // ---------------------------------------------------------------------------

  /// Fetches the active fecha from the backend.
  ///
  /// Guard: if the current state is NOT [ProdeFixturesLoading], the call is
  /// a no-op — prevents a redundant network round-trip when the screen is
  /// re-entered while already Loaded/Empty/Error. The user can force a fresh
  /// fetch via [refresh].
  Future<void> load() async {
    if (state is! ProdeFixturesLoading) return;
    // state is already Loading (initial) — no need to set it again.
    await _fetch(keepCurrentOnStart: false);
  }

  /// Re-fetches the active fecha.
  ///
  /// Unlike [load], this does NOT pre-set Loading when the current state is
  /// [ProdeFixturesLoaded] — this keeps the existing list visible under the
  /// RefreshIndicator while the network call is in flight. On any outcome the
  /// state transitions to Loaded/Empty/Error.
  ///
  /// When called from any other state the behaviour mirrors [load].
  Future<void> refresh() async {
    await _fetch(keepCurrentOnStart: state is ProdeFixturesLoaded);
  }

  // ---------------------------------------------------------------------------
  // Draft management
  // ---------------------------------------------------------------------------

  /// Updates the draft score inputs for [matchId] and emits a new loaded state.
  ///
  /// No-op when the current state is not [ProdeFixturesLoaded]. Does NOT
  /// trigger a network call.
  void updateDraft(int matchId, {int? scoreHome, int? scoreAway}) {
    final current = state;
    if (current is! ProdeFixturesLoaded) return;

    final existing = current.drafts[matchId] ?? const PredictionDraft();
    final updated = existing.copyWith(
      scoreHome: scoreHome,
      scoreAway: scoreAway,
    );
    final newDrafts = Map<int, PredictionDraft>.from(current.drafts)
      ..[matchId] = updated;
    state = ProdeFixturesLoaded(current.fecha, drafts: newDrafts);
  }

  /// Submits the prediction for [matchId] via [ProdeApiService.submitPrediction].
  ///
  /// Guards:
  /// - If draft scores are null → no-op.
  /// - If draft status is already [SubmitStatus.submitting] → no-op (double-submit
  ///   guard). The UNIQUE KEY on the backend is the final safety net.
  ///
  /// Status transitions: idle → submitting → submitted (on 200) / error (on any failure).
  Future<void> submitPrediction(int matchId) async {
    final current = state;
    if (current is! ProdeFixturesLoaded) return;

    final draft = current.drafts[matchId] ?? const PredictionDraft();

    // Guard: null scores
    if (draft.scoreHome == null || draft.scoreAway == null) return;

    // Guard: already in flight
    if (draft.status == SubmitStatus.submitting) return;

    // Set submitting
    _setDraftStatus(matchId, SubmitStatus.submitting);

    try {
      final loaded = state as ProdeFixturesLoaded;
      await _service.submitPrediction(
        fechaId: loaded.fecha.fechaId,
        matchId: matchId,
        scoreHome: draft.scoreHome!,
        scoreAway: draft.scoreAway!,
      );
      _setDraftStatus(matchId, SubmitStatus.submitted);
    } catch (_) {
      _setDraftStatus(matchId, SubmitStatus.error);
    }
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  /// Emits a new loaded state with [matchId]'s draft status set to [status].
  void _setDraftStatus(int matchId, SubmitStatus status) {
    final current = state;
    if (current is! ProdeFixturesLoaded) return;
    final existing = current.drafts[matchId] ?? const PredictionDraft();
    final newDrafts = Map<int, PredictionDraft>.from(current.drafts)
      ..[matchId] = existing.copyWith(status: status);
    state = ProdeFixturesLoaded(current.fecha, drafts: newDrafts);
  }

  Future<void> _fetch({required bool keepCurrentOnStart}) async {
    if (!keepCurrentOnStart) {
      state = const ProdeFixturesLoading();
    }
    try {
      final fecha = await _service.fetchFechaActiva();
      final drafts = _seedDrafts(fecha);
      state = ProdeFixturesLoaded(fecha, drafts: drafts);
    } on ProdeNoActiveFecha {
      state = const ProdeFixturesEmpty();
    } on ProdeAuthRequired {
      // G1 is post-auth; a 401 that can't refresh means the session died.
      // The auth gate's own 401 bridge will also flip the parent auth state,
      // but we surface an Error here as a belt-and-suspenders guard.
      state = const ProdeFixturesError(
        code: 'auth_required',
        message: 'Session expired. Please sign in again.',
      );
    } on ProdeSsoException catch (e) {
      state = ProdeFixturesError(code: e.code, message: e.message);
    } catch (e) {
      state = ProdeFixturesError(
        code: 'fixtures_error',
        message: e.toString(),
      );
    }
  }

  /// Builds the initial drafts map from [fecha.userPredictions].
  ///
  /// Matches with an existing prediction get a pre-filled draft (idle status).
  /// All other matches in [fecha.matches] get an empty draft (null scores, idle).
  static Map<int, PredictionDraft> _seedDrafts(FechaActiva fecha) {
    final predictionMap = {
      for (final p in fecha.userPredictions)
        p.matchId: PredictionDraft(
          scoreHome: p.scoreHome,
          scoreAway: p.scoreAway,
        ),
    };
    return {
      for (final m in fecha.matches)
        m.matchId: predictionMap[m.matchId] ?? const PredictionDraft(),
    };
  }
}

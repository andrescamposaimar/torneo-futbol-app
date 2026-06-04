import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/fecha_activa.dart';
import '../models/fecha_summary.dart';
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
// ProdeFixturesFechaError value class  (G6-e)
// ---------------------------------------------------------------------------

/// Inline per-fecha load error, set on [ProdeFixturesLoaded] when
/// [ProdeFixturesController.selectFecha] fails.
///
/// Distinct from the full-screen [ProdeFixturesError] — the selector row
/// stays mounted and the user can tap "Reintentar" to retry the same fetch.
class ProdeFixturesFechaError {
  /// Machine-readable code for diagnostics.
  final String code;

  /// The fecha id that failed to load — used by the retry button to re-issue
  /// [ProdeFixturesController.selectFecha] with the same id.
  final int fechaId;

  const ProdeFixturesFechaError({required this.code, required this.fechaId});

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ProdeFixturesFechaError &&
          runtimeType == other.runtimeType &&
          code == other.code &&
          fechaId == other.fechaId;

  @override
  int get hashCode => Object.hash(runtimeType, code, fechaId);

  @override
  String toString() => 'ProdeFixturesFechaError(code: $code, fechaId: $fechaId)';
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

/// Successfully loaded: a fecha is available for display.
///
/// G6-e additions:
/// - [fechas] — ordered fecha summary list from `GET /prode/fechas`.
/// - [selectedFechaId] — currently selected fecha in the selector.
/// - [isFechaLoading] — scoped spinner while fetching `GET /prode/fecha/{id}`.
/// - [fechaLoadError] — inline error when a per-fecha fetch fails; null when ok.
///
/// [drafts] is seeded from the selected fecha's [FechaActiva.userPredictions].
/// [savedMatchIds] mirrors that set and grows on successful submits.
final class ProdeFixturesLoaded extends ProdeFixturesState {
  final FechaActiva fecha;
  final Map<int, PredictionDraft> drafts;

  /// Set of match IDs whose prediction has been saved on the server.
  final Set<int> savedMatchIds;

  /// Ordered list of all fechas in the current season.
  final List<FechaSummary> fechas;

  /// The currently selected fecha id (drives the selector label and card list).
  final int selectedFechaId;

  /// True while a per-fecha `GET /prode/fecha/{id}` is in flight.
  /// The selector row stays mounted; only the card-list area shows a spinner.
  final bool isFechaLoading;

  /// Set when the most recent [ProdeFixturesController.selectFecha] call
  /// failed. Null when the last fetch succeeded.
  final ProdeFixturesFechaError? fechaLoadError;

  /// Number of matches in this fecha that have a confirmed server prediction.
  int get predictedCount => savedMatchIds.length;

  /// 0-based index of [selectedFechaId] in [fechas]. -1 when not found.
  int get selectedIndex =>
      fechas.indexWhere((f) => f.fechaId == selectedFechaId);

  const ProdeFixturesLoaded(
    this.fecha, {
    this.drafts = const {},
    this.savedMatchIds = const {},
    this.fechas = const [],
    this.selectedFechaId = 0,
    this.isFechaLoading = false,
    this.fechaLoadError,
  });

  ProdeFixturesLoaded copyWith({
    FechaActiva? fecha,
    Map<int, PredictionDraft>? drafts,
    Set<int>? savedMatchIds,
    List<FechaSummary>? fechas,
    int? selectedFechaId,
    bool? isFechaLoading,
    ProdeFixturesFechaError? fechaLoadError,
    bool clearFechaLoadError = false,
  }) {
    return ProdeFixturesLoaded(
      fecha ?? this.fecha,
      drafts: drafts ?? this.drafts,
      savedMatchIds: savedMatchIds ?? this.savedMatchIds,
      fechas: fechas ?? this.fechas,
      selectedFechaId: selectedFechaId ?? this.selectedFechaId,
      isFechaLoading: isFechaLoading ?? this.isFechaLoading,
      fechaLoadError: clearFechaLoadError ? null : (fechaLoadError ?? this.fechaLoadError),
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ProdeFixturesLoaded &&
          runtimeType == other.runtimeType &&
          fecha == other.fecha &&
          _mapsEqual(drafts, other.drafts) &&
          _setsEqual(savedMatchIds, other.savedMatchIds) &&
          _listsEqual(fechas, other.fechas) &&
          selectedFechaId == other.selectedFechaId &&
          isFechaLoading == other.isFechaLoading &&
          fechaLoadError == other.fechaLoadError;

  static bool _mapsEqual(
      Map<int, PredictionDraft> a, Map<int, PredictionDraft> b) {
    if (a.length != b.length) return false;
    for (final key in a.keys) {
      if (a[key] != b[key]) return false;
    }
    return true;
  }

  static bool _setsEqual(Set<int> a, Set<int> b) {
    if (a.length != b.length) return false;
    return a.containsAll(b);
  }

  static bool _listsEqual(List<FechaSummary> a, List<FechaSummary> b) {
    if (a.length != b.length) return false;
    for (var i = 0; i < a.length; i++) {
      if (a[i] != b[i]) return false;
    }
    return true;
  }

  @override
  int get hashCode => Object.hash(
        runtimeType,
        fecha,
        Object.hashAll(drafts.values),
        Object.hashAll(savedMatchIds),
        Object.hashAll(fechas),
        selectedFechaId,
        isFechaLoading,
        fechaLoadError,
      );

  @override
  String toString() =>
      'ProdeFixturesLoaded(fecha: ${fecha.fechaId}, selectedFechaId: $selectedFechaId, '
      'fechas: ${fechas.length}, drafts: ${drafts.length}, '
      'savedMatchIds: ${savedMatchIds.length}, isFechaLoading: $isFechaLoading, '
      'fechaLoadError: $fechaLoadError)';
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
/// G6-e rework: [_fetch] now calls `fetchFechas()` first, short-circuits to
/// [ProdeFixturesEmpty] on an empty list, and delegates initial payload load
/// to [fetchFechaActiva] (200 → active selection) or [fetchFechaById] (404
/// on active → last-in-list fallback). [selectFecha] loads a non-active fecha
/// with a scoped spinner. [refresh] re-fetches both the list and the selected
/// fecha. [submitPrediction] has a fecha-id fence that discards responses for
/// stale fechas.
class ProdeFixturesController
    extends StateNotifier<ProdeFixturesState> {
  final ProdeApiService _service;

  ProdeFixturesController(this._service)
      : super(const ProdeFixturesLoading());

  // ---------------------------------------------------------------------------
  // Public API
  // ---------------------------------------------------------------------------

  /// Fetches the fecha list and the initial (active or last) fecha payload.
  ///
  /// Guard: no-op when state is not [ProdeFixturesLoading].
  Future<void> load() async {
    if (state is! ProdeFixturesLoading) return;
    await _fetch(keepCurrentOnStart: false);
  }

  /// Re-fetches the fecha list and the currently selected fecha.
  ///
  /// Does NOT pre-set Loading when already Loaded — keeps the existing list
  /// visible under the RefreshIndicator.
  Future<void> refresh() async {
    await _fetch(keepCurrentOnStart: state is ProdeFixturesLoaded);
  }

  // ---------------------------------------------------------------------------
  // Draft management
  // ---------------------------------------------------------------------------

  /// Updates the draft score inputs for [matchId] and emits a new loaded state.
  void updateDraft(int matchId, {int? scoreHome, int? scoreAway}) {
    final current = state;
    if (current is! ProdeFixturesLoaded) return;

    final existing = current.drafts[matchId] ?? const PredictionDraft();
    final updated = existing.copyWith(
      scoreHome: scoreHome,
      scoreAway: scoreAway,
      clearScoreHome: scoreHome == null,
      clearScoreAway: scoreAway == null,
    );
    final newDrafts = Map<int, PredictionDraft>.from(current.drafts)
      ..[matchId] = updated;
    state = current.copyWith(drafts: newDrafts);
  }

  /// Submits the prediction for [matchId].
  ///
  /// G6-e fence: captures the selected fecha id before the await. After the
  /// network call returns, if the selected fecha id has changed (user switched
  /// fechas while in flight), the response is silently discarded — no state
  /// mutation, no error shown.
  Future<bool> submitPrediction(int matchId) async {
    final current = state;
    if (current is! ProdeFixturesLoaded) return false;

    final draft = current.drafts[matchId] ?? const PredictionDraft();

    if (draft.scoreHome == null || draft.scoreAway == null) return false;
    if (draft.status == SubmitStatus.submitting) return false;

    // Capture the fecha id the user is submitting against.
    final submittingFechaId = current.selectedFechaId;

    _setDraftStatus(matchId, SubmitStatus.submitting);

    try {
      final loaded = state as ProdeFixturesLoaded;
      await _service.submitPrediction(
        fechaId: loaded.fecha.fechaId,
        matchId: matchId,
        scoreHome: draft.scoreHome!,
        scoreAway: draft.scoreAway!,
      );

      // Fence: discard if user switched fechas while this was in flight.
      final afterAwait = state;
      if (afterAwait is! ProdeFixturesLoaded ||
          afterAwait.selectedFechaId != submittingFechaId) {
        return false;
      }

      _setDraftStatusAndMarkSaved(matchId);
      return true;
    } catch (_) {
      // Fence check for error path too.
      final afterAwait = state;
      if (afterAwait is! ProdeFixturesLoaded ||
          afterAwait.selectedFechaId != submittingFechaId) {
        return false;
      }
      _setDraftStatus(matchId, SubmitStatus.error);
      return false;
    }
  }

  // ---------------------------------------------------------------------------
  // Fecha selector  (G6-e)
  // ---------------------------------------------------------------------------

  /// Loads the payload for [fechaId] and updates the selected fecha.
  ///
  /// Emits a scoped loading state (isFechaLoading: true) while the request is
  /// in flight; the selector row stays mounted. On success, reseeds drafts and
  /// savedMatchIds from the new fecha's user_predictions.
  Future<void> selectFecha(int fechaId) async {
    final current = state;
    if (current is! ProdeFixturesLoaded) return;

    state = current.copyWith(
      isFechaLoading: true,
      clearFechaLoadError: true,
    );

    try {
      final newFecha = await _service.fetchFechaById(fechaId);
      final newDrafts = _seedDrafts(newFecha);
      final newSaved = _seedSavedMatchIds(newFecha);

      final afterLoad = state;
      if (afterLoad is! ProdeFixturesLoaded) return;

      state = afterLoad.copyWith(
        fecha: newFecha,
        drafts: newDrafts,
        savedMatchIds: newSaved,
        selectedFechaId: fechaId,
        isFechaLoading: false,
        clearFechaLoadError: true,
      );
    } catch (e) {
      final afterError = state;
      if (afterError is! ProdeFixturesLoaded) return;

      final code = e is ProdeSsoException ? e.code : 'fetch_fecha_error';
      state = afterError.copyWith(
        isFechaLoading: false,
        fechaLoadError: ProdeFixturesFechaError(code: code, fechaId: fechaId),
      );
    }
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  void _setDraftStatus(int matchId, SubmitStatus status) {
    final current = state;
    if (current is! ProdeFixturesLoaded) return;
    final existing = current.drafts[matchId] ?? const PredictionDraft();
    final newDrafts = Map<int, PredictionDraft>.from(current.drafts)
      ..[matchId] = existing.copyWith(status: status);
    state = current.copyWith(drafts: newDrafts);
  }

  void _setDraftStatusAndMarkSaved(int matchId) {
    final current = state;
    if (current is! ProdeFixturesLoaded) return;
    final existing = current.drafts[matchId] ?? const PredictionDraft();
    final newDrafts = Map<int, PredictionDraft>.from(current.drafts)
      ..[matchId] = existing.copyWith(status: SubmitStatus.submitted);
    final newSaved = {...current.savedMatchIds, matchId};
    state = current.copyWith(drafts: newDrafts, savedMatchIds: newSaved);
  }

  /// Core fetch routine.
  ///
  /// G6-e flow:
  /// 1. `GET /prode/fechas` → empty list → [ProdeFixturesEmpty] (short-circuit).
  /// 2. Non-empty list → `GET /prode/fecha-activa`:
  ///    - 200 → select matching id from list; payload from active response.
  ///    - `ProdeNoActiveFecha` (404) → select last id → `GET /prode/fecha/{lastId}`.
  /// 3. Emit [ProdeFixturesLoaded] with `fechas`, `selectedFechaId`.
  ///
  /// On [refresh], if the currently selected id still exists in the refreshed
  /// list, reload it; otherwise fall back to active/last logic.
  Future<void> _fetch({required bool keepCurrentOnStart}) async {
    // Capture selected id before potentially clobbering state.
    final previousSelectedId =
        state is ProdeFixturesLoaded ? (state as ProdeFixturesLoaded).selectedFechaId : null;

    if (!keepCurrentOnStart) {
      state = const ProdeFixturesLoading();
    }

    try {
      // Step 1: fetch the fecha list.
      final fechas = await _service.fetchFechas();

      if (fechas.isEmpty) {
        state = const ProdeFixturesEmpty();
        return;
      }

      // Step 2: determine selected fecha and payload.
      //
      // On refresh: if the previously selected id is still in the new list,
      // reload it via fetchFechaById. Otherwise fall through to active/last.
      if (previousSelectedId != null &&
          fechas.any((f) => f.fechaId == previousSelectedId)) {
        final payload = await _service.fetchFechaById(previousSelectedId);
        final drafts = _seedDrafts(payload);
        final saved = _seedSavedMatchIds(payload);
        state = ProdeFixturesLoaded(
          payload,
          drafts: drafts,
          savedMatchIds: saved,
          fechas: fechas,
          selectedFechaId: previousSelectedId,
        );
        return;
      }

      // Default: try active fecha, fall back to last.
      await _loadActiveOrLast(fechas);
    } on ProdeAuthRequired {
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

  /// Tries `fetchFechaActiva()`; on `ProdeNoActiveFecha` falls back to the
  /// last fecha in [fechas] via `fetchFechaById`.
  Future<void> _loadActiveOrLast(List<FechaSummary> fechas) async {
    try {
      final activeFecha = await _service.fetchFechaActiva();
      final selectedId = activeFecha.fechaId;
      final drafts = _seedDrafts(activeFecha);
      final saved = _seedSavedMatchIds(activeFecha);
      state = ProdeFixturesLoaded(
        activeFecha,
        drafts: drafts,
        savedMatchIds: saved,
        fechas: fechas,
        selectedFechaId: selectedId,
      );
    } on ProdeNoActiveFecha {
      // No active fecha → select last in list.
      final lastFecha = fechas.last;
      final payload = await _service.fetchFechaById(lastFecha.fechaId);
      final drafts = _seedDrafts(payload);
      final saved = _seedSavedMatchIds(payload);
      state = ProdeFixturesLoaded(
        payload,
        drafts: drafts,
        savedMatchIds: saved,
        fechas: fechas,
        selectedFechaId: lastFecha.fechaId,
      );
    }
  }

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

  static Set<int> _seedSavedMatchIds(FechaActiva fecha) {
    return {for (final p in fecha.userPredictions) p.matchId};
  }
}

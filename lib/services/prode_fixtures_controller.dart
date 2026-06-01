import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/fecha_activa.dart';
import 'prode_api_service.dart';

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
final class ProdeFixturesLoaded extends ProdeFixturesState {
  final FechaActiva fecha;

  const ProdeFixturesLoaded(this.fecha);

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ProdeFixturesLoaded &&
          runtimeType == other.runtimeType &&
          fecha == other.fecha;

  @override
  int get hashCode => Object.hash(runtimeType, fecha);

  @override
  String toString() => 'ProdeFixturesLoaded(fecha: ${fecha.fechaId})';
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
  // Private helpers
  // ---------------------------------------------------------------------------

  Future<void> _fetch({required bool keepCurrentOnStart}) async {
    if (!keepCurrentOnStart) {
      state = const ProdeFixturesLoading();
    }
    try {
      final fecha = await _service.fetchFechaActiva();
      state = ProdeFixturesLoaded(fecha);
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
}

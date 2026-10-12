import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/cambios_plaza.dart';
import 'cambios_api_service.dart';
import 'cambios_load_failure.dart';

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

/// State machine for "Mi Plantel" — the captain's own plazas.
///
/// An empty [CambiosPlantelLoaded.plazas] is a legitimate, expected state in
/// production today (the plazas table is empty until a backfill that has
/// not happened yet) — never rendered as an error.
sealed class CambiosPlantelState {
  const CambiosPlantelState();
}

final class CambiosPlantelLoading extends CambiosPlantelState {
  const CambiosPlantelLoading();
}

/// A failed load. [code] says WHY (see [describeCambiosLoadFailure]);
/// [message] is diagnostic only and MUST NOT be shown raw.
final class CambiosPlantelError extends CambiosPlantelState {
  final String code;
  final String message;

  const CambiosPlantelError({required this.code, required this.message});

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CambiosPlantelError && code == other.code && message == other.message;

  @override
  int get hashCode => Object.hash(runtimeType, code, message);

  @override
  String toString() => 'CambiosPlantelError(code: $code, message: $message)';
}

final class CambiosPlantelLoaded extends CambiosPlantelState {
  final List<CambiosPlaza> plazas;
  const CambiosPlantelLoaded({required this.plazas});
}

// ---------------------------------------------------------------------------
// Controller
// ---------------------------------------------------------------------------

class CambiosPlantelController extends StateNotifier<CambiosPlantelState> {
  final CambiosApiService _service;

  CambiosPlantelController(this._service) : super(const CambiosPlantelLoading());

  Future<void> load({required int seasonId, required int teamId}) async {
    state = const CambiosPlantelLoading();
    try {
      final plazas =
          await _service.fetchPlazas(seasonId: seasonId, teamId: teamId);
      state = CambiosPlantelLoaded(plazas: plazas);
    } catch (e) {
      final failure = describeCambiosLoadFailure('plantel', e);
      state = CambiosPlantelError(code: failure.code, message: failure.message);
    }
  }

  /// Re-fetches, keeping the same semantics as [load] — used after a
  /// solicitud is submitted from "Pedir cambio" so the roster reflects the
  /// captain's action without a Navigator result (shared Riverpod state).
  Future<void> refresh({required int seasonId, required int teamId}) =>
      load(seasonId: seasonId, teamId: teamId);
}

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/cambios_plaza.dart';
import 'cambios_api_service.dart';

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

final class CambiosPlantelError extends CambiosPlantelState {
  const CambiosPlantelError();
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
    } catch (_) {
      state = const CambiosPlantelError();
    }
  }

  /// Re-fetches, keeping the same semantics as [load] — used after a
  /// solicitud is submitted from "Pedir cambio" so the roster reflects the
  /// captain's action without a Navigator result (shared Riverpod state).
  Future<void> refresh({required int seasonId, required int teamId}) =>
      load(seasonId: seasonId, teamId: teamId);
}

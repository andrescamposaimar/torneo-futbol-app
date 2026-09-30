import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/cambios_candidato.dart';
import 'cambios_api_service.dart';

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

/// State machine for the candidate list on "Pedir cambio", scoped to a
/// single plaza. Re-created per plaza via a Riverpod family provider (see
/// `cambios_providers.dart`), so [load] always starts fresh — no re-entry
/// guard like the session-persistent Prode controllers.
sealed class CambiosCandidatosState {
  const CambiosCandidatosState();
}

final class CambiosCandidatosLoading extends CambiosCandidatosState {
  const CambiosCandidatosLoading();
}

final class CambiosCandidatosError extends CambiosCandidatosState {
  const CambiosCandidatosError();
}

final class CambiosCandidatosLoaded extends CambiosCandidatosState {
  final List<CambiosCandidato> candidatos;
  final String query;
  const CambiosCandidatosLoaded({required this.candidatos, required this.query});
}

// ---------------------------------------------------------------------------
// Controller
// ---------------------------------------------------------------------------

class CambiosCandidatosController extends StateNotifier<CambiosCandidatosState> {
  final CambiosApiService _service;
  final int seasonId;
  final int teamId;
  final int plazaId;

  CambiosCandidatosController(
    this._service, {
    required this.seasonId,
    required this.teamId,
    required this.plazaId,
  }) : super(const CambiosCandidatosLoading());

  /// Fetches the viable candidate list, optionally narrowed server-side by
  /// [query] (`?search=`). Always re-fetches — the caller (a debounced
  /// search field) decides when this runs.
  Future<void> load({String query = ''}) async {
    state = const CambiosCandidatosLoading();
    try {
      final candidatos = await _service.fetchCandidatos(
        seasonId: seasonId,
        teamId: teamId,
        plazaId: plazaId,
        search: query.isEmpty ? null : query,
      );
      state = CambiosCandidatosLoaded(candidatos: candidatos, query: query);
    } catch (_) {
      state = const CambiosCandidatosError();
    }
  }
}

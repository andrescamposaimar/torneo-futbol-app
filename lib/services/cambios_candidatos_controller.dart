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
  final CambiosCandidatosSeccion seccion;

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

  /// Fetches this section's candidate list from the backend, optionally
  /// narrowed server-side by [query] (`?search=`). Always re-fetches — the
  /// caller decides when this runs (eagerly once for `listaEspera`, once on
  /// first open for `padronCompleto`, never per keystroke: the screen's own
  /// search field and puntaje chips filter the already-loaded list locally,
  /// via `PlayerFilterService` — see `cambios_solicitar_screen.dart`).
  Future<void> load({String query = ''}) async {
    state = const CambiosCandidatosLoading();
    try {
      final candidatos = await _service.fetchCandidatos(
        seasonId: seasonId,
        teamId: teamId,
        plazaId: plazaId,
        seccion: seccion,
        search: query.isEmpty ? null : query,
      );
      state = CambiosCandidatosLoaded(candidatos: candidatos, query: query);
    } catch (_) {
      state = const CambiosCandidatosError();
    }
  }
}

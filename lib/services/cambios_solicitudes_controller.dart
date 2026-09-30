import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/cambios_solicitud.dart';
import 'cambios_api_service.dart';

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

/// State machine for "Mis Solicitudes" — every solicitud the captain's team
/// has ever made, in any estado.
sealed class CambiosSolicitudesState {
  const CambiosSolicitudesState();
}

final class CambiosSolicitudesLoading extends CambiosSolicitudesState {
  const CambiosSolicitudesLoading();
}

final class CambiosSolicitudesError extends CambiosSolicitudesState {
  const CambiosSolicitudesError();
}

final class CambiosSolicitudesLoaded extends CambiosSolicitudesState {
  final List<CambiosSolicitud> solicitudes;
  const CambiosSolicitudesLoaded({required this.solicitudes});
}

// ---------------------------------------------------------------------------
// Controller
// ---------------------------------------------------------------------------

class CambiosSolicitudesController extends StateNotifier<CambiosSolicitudesState> {
  final CambiosApiService _service;

  CambiosSolicitudesController(this._service)
      : super(const CambiosSolicitudesLoading());

  Future<void> load({required int seasonId, required int teamId}) async {
    state = const CambiosSolicitudesLoading();
    try {
      final solicitudes =
          await _service.fetchSolicitudes(seasonId: seasonId, teamId: teamId);
      state = CambiosSolicitudesLoaded(solicitudes: solicitudes);
    } catch (_) {
      state = const CambiosSolicitudesError();
    }
  }

  Future<void> refresh({required int seasonId, required int teamId}) =>
      load(seasonId: seasonId, teamId: teamId);
}

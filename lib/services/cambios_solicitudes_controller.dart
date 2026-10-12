import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/cambios_solicitud.dart';
import 'cambios_api_service.dart';
import 'cambios_load_failure.dart';

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

/// A failed load. [code] says WHY (see [describeCambiosLoadFailure]) and is
/// safe to show as a short reference; [message] is diagnostic only and MUST
/// NOT be shown raw — the screen keeps its own plain-Spanish copy.
final class CambiosSolicitudesError extends CambiosSolicitudesState {
  final String code;
  final String message;

  const CambiosSolicitudesError({required this.code, required this.message});

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CambiosSolicitudesError && code == other.code && message == other.message;

  @override
  int get hashCode => Object.hash(runtimeType, code, message);

  @override
  String toString() => 'CambiosSolicitudesError(code: $code, message: $message)';
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
    } catch (e) {
      final failure = describeCambiosLoadFailure('solicitudes', e);
      state = CambiosSolicitudesError(code: failure.code, message: failure.message);
    }
  }

  Future<void> refresh({required int seasonId, required int teamId}) =>
      load(seasonId: seasonId, teamId: teamId);
}

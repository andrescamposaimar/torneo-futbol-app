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

  /// Withdraws a still-pending request and reloads the list.
  ///
  /// Returns null on success, or a short Spanish sentence to show the
  /// captain. It deliberately does NOT move the state to
  /// [CambiosSolicitudesError] on failure: the list on screen is still
  /// correct, and blanking it into a full-screen error over a failed
  /// cancellation would lose everything else he was looking at.
  ///
  /// It ALWAYS reloads, success or not. A `solicitud_ya_resuelta` means the
  /// comisión decided while he had the screen open, so the most useful
  /// response is to show him what they decided — the message alone would
  /// leave a stale "Pendiente" card sitting under it.
  Future<String?> cancelar({
    required int seasonId,
    required int teamId,
    required int solicitudId,
  }) async {
    String? error;

    try {
      await _service.cancelarSolicitud(
        seasonId: seasonId,
        teamId: teamId,
        solicitudId: solicitudId,
      );
    } on CambiosApiException catch (e) {
      error = switch (e.code) {
        'solicitud_ya_resuelta' =>
          'La comisión ya resolvió ese pedido mientras tenías la pantalla abierta.',
        'solicitud_no_encontrada' => 'No encontramos ese pedido.',
        _ => 'No pudimos cancelar el pedido. Probá de nuevo en unos minutos.',
      };
    } catch (_) {
      error = 'No pudimos cancelar el pedido. Revisá tu conexión.';
    }

    await load(seasonId: seasonId, teamId: teamId);

    return error;
  }
}

import 'package:flutter/foundation.dart';

/// The season's currently OPEN fecha — the earliest one not yet resolved
/// (`estado` neither `jugada` nor `dirimida`) — as returned by
/// `GET /cambios/fecha-abierta`.
///
/// [CambiosApiService.fetchFechaAbierta] returns `null`, never this class,
/// when the backend answers `{"fecha": null}`: the season has no unresolved
/// fecha — "the season is over" or "nothing is scheduled yet" — a normal
/// state the "Pedir cambio" screen renders as a sentence, never a failure.
@immutable
class CambiosFechaAbierta {
  final int fechaId;
  final int numeroEnTorneo;
  final String torneo;
  final String playDate;

  /// Whether a `regreso` request against THIS fecha is still within its own
  /// deadline. See `Dictamen\Reglas\SolicitudEnPlazo`'s own docblock: regreso
  /// closes BEFORE sustitucion, so this can be `false` while
  /// [sustitucionAbierta] is still `true` — the two windows are computed
  /// independently on the backend, never one derived from the other.
  final bool regresoAbierta;

  /// Whether a `sustitucion` request against THIS fecha is still within its
  /// own deadline.
  final bool sustitucionAbierta;

  const CambiosFechaAbierta({
    required this.fechaId,
    required this.numeroEnTorneo,
    required this.torneo,
    required this.playDate,
    required this.regresoAbierta,
    required this.sustitucionAbierta,
  });

  /// The one flag the "Pedir cambio" screen actually needs — whichever
  /// window matches [esSustitucion] — so it never has to know which wire
  /// key maps to which `CambiosSolicitudTipo`.
  bool ventanaAbiertaPara({required bool esSustitucion}) =>
      esSustitucion ? sustitucionAbierta : regresoAbierta;

  factory CambiosFechaAbierta.fromJson(Map<String, dynamic> json) {
    final rawVentanas = json['ventanas'];
    final ventanas =
        rawVentanas is Map ? rawVentanas.cast<String, dynamic>() : const <String, dynamic>{};

    return CambiosFechaAbierta(
      fechaId: (json['fecha_id'] as int?) ?? 0,
      numeroEnTorneo: (json['numero_en_torneo'] as int?) ?? 0,
      torneo: (json['torneo'] as String?) ?? '',
      playDate: (json['play_date'] as String?) ?? '',
      regresoAbierta: ventanas['regreso_abierta'] == true,
      sustitucionAbierta: ventanas['sustitucion_abierta'] == true,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CambiosFechaAbierta &&
          runtimeType == other.runtimeType &&
          fechaId == other.fechaId &&
          numeroEnTorneo == other.numeroEnTorneo &&
          torneo == other.torneo &&
          playDate == other.playDate &&
          regresoAbierta == other.regresoAbierta &&
          sustitucionAbierta == other.sustitucionAbierta;

  @override
  int get hashCode => Object.hash(
        fechaId,
        numeroEnTorneo,
        torneo,
        playDate,
        regresoAbierta,
        sustitucionAbierta,
      );

  @override
  String toString() =>
      'CambiosFechaAbierta(fechaId: $fechaId, torneo: $torneo, playDate: $playDate, '
      'regresoAbierta: $regresoAbierta, sustitucionAbierta: $sustitucionAbierta)';
}

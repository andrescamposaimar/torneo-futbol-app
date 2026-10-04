import 'package:flutter/foundation.dart';

/// A window's phase, as returned by `GET /cambios/fecha-abierta`'s
/// `ventanas.{regreso,sustitucion}` — replaces a plain boolean because a
/// boolean cannot distinguish "the window has not opened yet" from "the
/// window's own deadline already passed": both used to read as `false`. See
/// `Rest\FechaController`'s own docblock, "THREE STATES, NOT TWO", for the
/// backend side of this contract.
enum CambiosVentanaFase {
  /// Before `apertura_solicitudes` — the window has not opened yet.
  antes,

  /// Within the window: a request against this fecha can be submitted now.
  abierta,

  /// Past the window's own closing deadline.
  cerrada;

  /// Parses the wire value (`'antes'` | `'abierta'` | `'cerrada'`), falling
  /// back to [cerrada] for anything unknown or missing — the same
  /// "never throw on an unexpected payload shape" discipline this file's
  /// other parsing already follows. [cerrada] is the safest unknown default:
  /// it keeps submission disabled, never wrongly enables it.
  static CambiosVentanaFase fromWire(Object? value) {
    switch (value) {
      case 'antes':
        return CambiosVentanaFase.antes;
      case 'abierta':
        return CambiosVentanaFase.abierta;
      default:
        return CambiosVentanaFase.cerrada;
    }
  }
}

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

  /// The phase of a `regreso` request against THIS fecha. See
  /// `Dictamen\Reglas\SolicitudEnPlazo`'s own docblock: regreso closes
  /// BEFORE sustitucion, so this can be [CambiosVentanaFase.cerrada] while
  /// [sustitucionFase] is still [CambiosVentanaFase.abierta] — the two
  /// windows are computed independently on the backend, never one derived
  /// from the other.
  final CambiosVentanaFase regresoFase;

  /// The phase of a `sustitucion` request against THIS fecha.
  final CambiosVentanaFase sustitucionFase;

  /// When the regreso window opens — parsed from `plazos_utc.apertura_solicitudes`.
  /// `null` only if the payload is missing or malformed; the "Pedir cambio"
  /// screen uses this to tell a captain WHEN to come back while
  /// [regresoFase] (or [sustitucionFase]) is [CambiosVentanaFase.antes].
  /// Both windows share the SAME `apertura_solicitudes` instant (see
  /// `Rest\FechaController::shapeFecha()`), so a single field covers both.
  final DateTime? aperturaSolicitudesUtc;

  const CambiosFechaAbierta({
    required this.fechaId,
    required this.numeroEnTorneo,
    required this.torneo,
    required this.playDate,
    required this.regresoFase,
    required this.sustitucionFase,
    this.aperturaSolicitudesUtc,
  });

  /// The one flag the "Pedir cambio" screen actually needs — whichever
  /// window matches [esSustitucion] — so it never has to know which wire
  /// key maps to which `CambiosSolicitudTipo`.
  bool ventanaAbiertaPara({required bool esSustitucion}) =>
      faseFor(esSustitucion: esSustitucion) == CambiosVentanaFase.abierta;

  /// The raw [CambiosVentanaFase] for whichever window matches
  /// [esSustitucion] — the three-state equivalent of [ventanaAbiertaPara],
  /// for callers (like `_VentanaEstadoBanner`) that need to tell `antes`
  /// apart from `cerrada`, not just "open or not".
  CambiosVentanaFase faseFor({required bool esSustitucion}) =>
      esSustitucion ? sustitucionFase : regresoFase;

  factory CambiosFechaAbierta.fromJson(Map<String, dynamic> json) {
    final rawVentanas = json['ventanas'];
    final ventanas =
        rawVentanas is Map ? rawVentanas.cast<String, dynamic>() : const <String, dynamic>{};

    final rawPlazos = json['plazos_utc'];
    final plazos = rawPlazos is Map ? rawPlazos.cast<String, dynamic>() : const <String, dynamic>{};

    return CambiosFechaAbierta(
      fechaId: (json['fecha_id'] as int?) ?? 0,
      numeroEnTorneo: (json['numero_en_torneo'] as int?) ?? 0,
      torneo: (json['torneo'] as String?) ?? '',
      playDate: (json['play_date'] as String?) ?? '',
      regresoFase: CambiosVentanaFase.fromWire(ventanas['regreso']),
      sustitucionFase: CambiosVentanaFase.fromWire(ventanas['sustitucion']),
      aperturaSolicitudesUtc: _parseUtc(plazos['apertura_solicitudes']),
    );
  }

  /// Parses a `'Y-m-d H:i:s'` UTC civil string (the shape every
  /// `plazos_utc` field uses — see `PlazosCalculator::computeUtc()`) into a
  /// [DateTime]. Returns `null` instead of throwing on anything unexpected,
  /// consistent with this factory's own "never throw on a malformed payload"
  /// discipline.
  static DateTime? _parseUtc(Object? value) {
    if (value is! String || value.isEmpty) return null;
    final isoLike = value.contains('T') ? value : value.replaceFirst(' ', 'T');
    return DateTime.tryParse('${isoLike}Z');
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
          regresoFase == other.regresoFase &&
          sustitucionFase == other.sustitucionFase &&
          aperturaSolicitudesUtc == other.aperturaSolicitudesUtc;

  @override
  int get hashCode => Object.hash(
        fechaId,
        numeroEnTorneo,
        torneo,
        playDate,
        regresoFase,
        sustitucionFase,
        aperturaSolicitudesUtc,
      );

  @override
  String toString() =>
      'CambiosFechaAbierta(fechaId: $fechaId, torneo: $torneo, playDate: $playDate, '
      'regresoFase: $regresoFase, sustitucionFase: $sustitucionFase, '
      'aperturaSolicitudesUtc: $aperturaSolicitudesUtc)';
}

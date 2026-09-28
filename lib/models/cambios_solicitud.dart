import 'package:flutter/foundation.dart';

import 'cambios_dictamen.dart';

/// `tipo` values a solicitud may carry — mirrors
/// `Dictamen\SolicitudDeCambio::TIPO_SUSTITUCION` / `TIPO_REGRESO` on the
/// backend. Kept as a plain wire-string enum (not a Dart `enum`) so an
/// unrecognized future value parses into [CambiosSolicitudTipo.desconocido]
/// instead of throwing.
enum CambiosSolicitudTipo {
  sustitucion,
  regreso,
  desconocido;

  static CambiosSolicitudTipo fromWire(String? raw) {
    switch (raw) {
      case 'sustitucion':
        return CambiosSolicitudTipo.sustitucion;
      case 'regreso':
        return CambiosSolicitudTipo.regreso;
      default:
        return CambiosSolicitudTipo.desconocido;
    }
  }

  String toWire() {
    switch (this) {
      case CambiosSolicitudTipo.sustitucion:
        return 'sustitucion';
      case CambiosSolicitudTipo.regreso:
        return 'regreso';
      case CambiosSolicitudTipo.desconocido:
        return 'sustitucion';
    }
  }

  String get label {
    switch (this) {
      case CambiosSolicitudTipo.sustitucion:
        return 'Cambio';
      case CambiosSolicitudTipo.regreso:
        return 'Regreso';
      case CambiosSolicitudTipo.desconocido:
        return 'Pedido';
    }
  }
}

/// `estado` values a solicitud moves through — mirrors
/// `Solicitudes\EstadoSolicitud` on the backend.
enum CambiosSolicitudEstado {
  pendiente,
  aprobada,
  rechazada,
  publicada,
  anulada,
  desconocido;

  static CambiosSolicitudEstado fromWire(String? raw) {
    switch (raw) {
      case 'pendiente':
        return CambiosSolicitudEstado.pendiente;
      case 'aprobada':
        return CambiosSolicitudEstado.aprobada;
      case 'rechazada':
        return CambiosSolicitudEstado.rechazada;
      case 'publicada':
        return CambiosSolicitudEstado.publicada;
      case 'anulada':
        return CambiosSolicitudEstado.anulada;
      default:
        return CambiosSolicitudEstado.desconocido;
    }
  }

  String get label {
    switch (this) {
      case CambiosSolicitudEstado.pendiente:
        return 'Pendiente';
      case CambiosSolicitudEstado.aprobada:
        return 'Aprobada';
      case CambiosSolicitudEstado.rechazada:
        return 'Rechazada';
      case CambiosSolicitudEstado.publicada:
        return 'Publicada';
      case CambiosSolicitudEstado.anulada:
        return 'Anulada';
      case CambiosSolicitudEstado.desconocido:
        return 'Desconocido';
    }
  }
}

/// A single solicitud, as returned by `GET /cambios/solicitudes`.
@immutable
class CambiosSolicitud {
  final int id;
  final int plazaId;
  final CambiosSolicitudTipo tipo;
  final int? entrantePlayerId;
  final int fechaId;
  final CambiosSolicitudEstado estado;

  /// Wire format `"Y-m-d H:i:s"` (UTC, no timezone conversion — same
  /// convention as `PredictionHistoryEntry.kickoff`).
  final DateTime? solicitadaAt;
  final DateTime? resueltaAt;

  final String? nota;
  final CambiosDictamen dictamen;

  const CambiosSolicitud({
    required this.id,
    required this.plazaId,
    required this.tipo,
    this.entrantePlayerId,
    required this.fechaId,
    required this.estado,
    this.solicitadaAt,
    this.resueltaAt,
    this.nota,
    required this.dictamen,
  });

  factory CambiosSolicitud.fromJson(Map<String, dynamic> json) {
    final rawDictamen = json['dictamen'];
    return CambiosSolicitud(
      id: (json['id'] as int?) ?? 0,
      plazaId: (json['plaza_id'] as int?) ?? 0,
      tipo: CambiosSolicitudTipo.fromWire(json['tipo'] as String?),
      entrantePlayerId: json['entrante_player_id'] as int?,
      fechaId: (json['fecha_id'] as int?) ?? 0,
      estado: CambiosSolicitudEstado.fromWire(json['estado'] as String?),
      solicitadaAt: _parseWireDateTime(json['solicitada_at']),
      resueltaAt: _parseWireDateTime(json['resuelta_at']),
      nota: json['nota'] as String?,
      dictamen: rawDictamen is Map
          ? CambiosDictamen.fromJson(rawDictamen.cast<String, dynamic>())
          : const CambiosDictamen(procede: false),
    );
  }

  static DateTime? _parseWireDateTime(Object? raw) {
    if (raw is! String || raw.isEmpty) return null;
    try {
      return DateTime.parse(raw.replaceFirst(' ', 'T'));
    } on FormatException {
      return null;
    }
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CambiosSolicitud &&
          runtimeType == other.runtimeType &&
          id == other.id &&
          plazaId == other.plazaId &&
          tipo == other.tipo &&
          entrantePlayerId == other.entrantePlayerId &&
          fechaId == other.fechaId &&
          estado == other.estado &&
          solicitadaAt == other.solicitadaAt &&
          resueltaAt == other.resueltaAt &&
          nota == other.nota &&
          dictamen == other.dictamen;

  @override
  int get hashCode => Object.hash(
        id,
        plazaId,
        tipo,
        entrantePlayerId,
        fechaId,
        estado,
        solicitadaAt,
        resueltaAt,
        nota,
        dictamen,
      );

  @override
  String toString() =>
      'CambiosSolicitud(id: $id, tipo: $tipo, estado: $estado)';
}

/// The `POST /cambios/solicitudes` response — a freshly created solicitud's
/// id, its starting estado (always `pendiente`), and the dictamen evaluated
/// at that moment.
@immutable
class CambiosNuevaSolicitud {
  final int id;
  final CambiosSolicitudEstado estado;
  final CambiosDictamen dictamen;

  const CambiosNuevaSolicitud({
    required this.id,
    required this.estado,
    required this.dictamen,
  });

  factory CambiosNuevaSolicitud.fromJson(Map<String, dynamic> json) {
    final rawDictamen = json['dictamen'];
    return CambiosNuevaSolicitud(
      id: (json['id'] as int?) ?? 0,
      estado: CambiosSolicitudEstado.fromWire(json['estado'] as String?),
      dictamen: rawDictamen is Map
          ? CambiosDictamen.fromJson(rawDictamen.cast<String, dynamic>())
          : const CambiosDictamen(procede: false),
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CambiosNuevaSolicitud &&
          runtimeType == other.runtimeType &&
          id == other.id &&
          estado == other.estado &&
          dictamen == other.dictamen;

  @override
  int get hashCode => Object.hash(id, estado, dictamen);

  @override
  String toString() => 'CambiosNuevaSolicitud(id: $id, estado: $estado)';
}

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

/// One side ("sale" or "entra") of a solicitud's player pair, as returned by
/// `GET /cambios/solicitudes` — see `Rest\SolicitudesController::shapeLado()`
/// on the backend for exactly how each side is derived per `tipo`.
///
/// *** `playerId == null` MEANS "NOT RECORDED" — THE WHOLE OBJECT DEGRADES,
/// NOTHING IS GUESSED *** This happens for a `saliente_player_id` that
/// predates the backend column this feature added (a solicitud created
/// before that migration). There is no id to attach a name or a puntaje to,
/// so every field parses to null — `CambiosSolicitudesScreen` renders this
/// as "Sin registrar", never a blank line or a fabricated name.
///
/// `nombre` is non-null whenever `playerId` is known (the backend always
/// falls back to `"Jugador #<id>"` rather than an empty title), so the app
/// never has to invent a placeholder of its own. `puntaje` stays genuinely
/// nullable — same contract as `CambiosCandidato.puntaje` — so a card can
/// render a name with NO brackets at all when it is unknown, rather than a
/// fabricated `[0]` or an empty `[]`/`[-]`.
@immutable
class CambiosSolicitudLado {
  final int? playerId;
  final String? nombre;

  /// Decimal score (e.g. 4.5), or null when it could not be resolved.
  final double? puntaje;

  const CambiosSolicitudLado({this.playerId, this.nombre, this.puntaje});

  /// The "not recorded" state — every field null. Used both as the parsed
  /// result of a missing/malformed JSON object and as this class's own
  /// explicit default.
  static const CambiosSolicitudLado noRegistrado = CambiosSolicitudLado();

  factory CambiosSolicitudLado.fromJson(Object? json) {
    if (json is! Map) return noRegistrado;
    final map = json.cast<String, dynamic>();
    final rawPuntaje = map['puntaje'];
    return CambiosSolicitudLado(
      playerId: map['player_id'] as int?,
      nombre: map['nombre'] as String?,
      puntaje: rawPuntaje is num ? rawPuntaje.toDouble() : null,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CambiosSolicitudLado &&
          runtimeType == other.runtimeType &&
          playerId == other.playerId &&
          nombre == other.nombre &&
          puntaje == other.puntaje;

  @override
  int get hashCode => Object.hash(playerId, nombre, puntaje);

  @override
  String toString() =>
      'CambiosSolicitudLado(playerId: $playerId, nombre: $nombre, puntaje: $puntaje)';
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

  /// Who leaves the plaza — see `CambiosSolicitudLado`'s own docblock for how
  /// this differs by `tipo` on the backend and what a "not recorded" value
  /// means.
  final CambiosSolicitudLado sale;

  /// Who comes in — the entrante for a `sustitucion`, or the plaza's titular
  /// for a `regreso` (see `CambiosSolicitudLado`'s own docblock).
  final CambiosSolicitudLado entra;

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
    this.sale = CambiosSolicitudLado.noRegistrado,
    this.entra = CambiosSolicitudLado.noRegistrado,
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
      sale: CambiosSolicitudLado.fromJson(json['sale']),
      entra: CambiosSolicitudLado.fromJson(json['entra']),
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
          dictamen == other.dictamen &&
          sale == other.sale &&
          entra == other.entra;

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
        sale,
        entra,
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

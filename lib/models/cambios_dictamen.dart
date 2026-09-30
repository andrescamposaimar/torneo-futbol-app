import 'package:flutter/foundation.dart';

/// A single reason a [CambiosDictamen] did not clear a solicitud.
///
/// `codigo` is the stable, machine-readable rule id (e.g.
/// `puntaje_excede_techo`) — see `cambios_motivo_mensajes.dart` for the
/// parent-facing Spanish sentence each one maps to. `mensaje` is the
/// backend's own copy (committee-facing, sometimes leaks internal
/// shorthand like "CC5b") and is kept only for diagnostics — screens must
/// never render it directly.
@immutable
class CambiosDictamenMotivo {
  final String codigo;
  final String mensaje;
  final Map<String, dynamic> datos;

  const CambiosDictamenMotivo({
    required this.codigo,
    required this.mensaje,
    this.datos = const {},
  });

  factory CambiosDictamenMotivo.fromJson(Map<String, dynamic> json) {
    final rawDatos = json['datos'];
    return CambiosDictamenMotivo(
      codigo: (json['codigo'] as String?) ?? '',
      mensaje: (json['mensaje'] as String?) ?? '',
      datos: rawDatos is Map ? rawDatos.cast<String, dynamic>() : const {},
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CambiosDictamenMotivo &&
          runtimeType == other.runtimeType &&
          codigo == other.codigo &&
          mensaje == other.mensaje &&
          mapEquals(datos, other.datos);

  @override
  int get hashCode => Object.hash(codigo, mensaje, Object.hashAllUnordered(
        datos.entries.map((e) => Object.hash(e.key, e.value)),
      ));

  @override
  String toString() => 'CambiosDictamenMotivo(codigo: $codigo)';
}

/// The dictamen (verdict) for a solicitud — favorable or not, always a
/// normal 200 from the backend's own perspective (see
/// `SolicitudesController`'s docblock: "a dictamen that does not procede is
/// not an error").
@immutable
class CambiosDictamen {
  final bool procede;
  final List<CambiosDictamenMotivo> motivos;

  /// Only present on the POST /solicitudes response; absent (null) on the
  /// GET /solicitudes listing shape.
  final int? fechasFaltantesLiberacion;

  const CambiosDictamen({
    required this.procede,
    this.motivos = const [],
    this.fechasFaltantesLiberacion,
  });

  factory CambiosDictamen.fromJson(Map<String, dynamic> json) {
    final rawMotivos = json['motivos'];
    final motivos = (rawMotivos is List)
        ? rawMotivos
            .whereType<Map>()
            .map((e) => CambiosDictamenMotivo.fromJson(e.cast<String, dynamic>()))
            .toList(growable: false)
        : const <CambiosDictamenMotivo>[];

    return CambiosDictamen(
      procede: json['procede'] == true,
      motivos: motivos,
      fechasFaltantesLiberacion: json['fechas_faltantes_liberacion'] as int?,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CambiosDictamen &&
          runtimeType == other.runtimeType &&
          procede == other.procede &&
          listEquals(motivos, other.motivos) &&
          fechasFaltantesLiberacion == other.fechasFaltantesLiberacion;

  @override
  int get hashCode =>
      Object.hash(procede, Object.hashAll(motivos), fechasFaltantesLiberacion);

  @override
  String toString() =>
      'CambiosDictamen(procede: $procede, motivos: ${motivos.length})';
}

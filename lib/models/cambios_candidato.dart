import 'package:flutter/foundation.dart';

/// A single candidate for a plaza, as returned by
/// `GET /cambios/plazas/candidatos`.
///
/// By default the endpoint returns only viable candidates, so [viable] is
/// normally always true and [motivo] null in what this app renders — both
/// are still parsed defensively in case a future caller passes
/// `incluir_no_viables=1`.
@immutable
class CambiosCandidato {
  final int playerId;
  final String nombre;
  final bool esPadre;

  /// Decimal score (e.g. 3.5), or null when it could not be computed.
  final double? puntaje;

  final bool viable;

  /// Machine-readable reason code when [viable] is false (e.g.
  /// `puntaje_excede_techo`), null when viable.
  final String? motivo;

  const CambiosCandidato({
    required this.playerId,
    required this.nombre,
    required this.esPadre,
    this.puntaje,
    required this.viable,
    this.motivo,
  });

  factory CambiosCandidato.fromJson(Map<String, dynamic> json) {
    final rawPuntaje = json['puntaje'];
    return CambiosCandidato(
      playerId: (json['player_id'] as int?) ?? 0,
      nombre: (json['nombre'] as String?) ?? '',
      esPadre: json['es_padre'] == true,
      puntaje: rawPuntaje is num ? rawPuntaje.toDouble() : null,
      viable: json['viable'] == true,
      motivo: json['motivo'] as String?,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CambiosCandidato &&
          runtimeType == other.runtimeType &&
          playerId == other.playerId &&
          nombre == other.nombre &&
          esPadre == other.esPadre &&
          puntaje == other.puntaje &&
          viable == other.viable &&
          motivo == other.motivo;

  @override
  int get hashCode =>
      Object.hash(playerId, nombre, esPadre, puntaje, viable, motivo);

  @override
  String toString() =>
      'CambiosCandidato(playerId: $playerId, nombre: $nombre, '
      'esPadre: $esPadre, puntaje: $puntaje, viable: $viable)';
}

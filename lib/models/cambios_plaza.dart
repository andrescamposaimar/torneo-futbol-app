import 'package:flutter/foundation.dart';

/// A single plaza (roster slot) of a team, as returned by `GET /cambios/plazas`.
///
/// `ocupantePlayerId`/`ocupanteNombre` are null only when the plaza has no
/// vigent ocupación (should not happen in practice, but the backend does not
/// assume it either — see `PlazaRepository::findOcupacionVigente()`).
///
/// `fechasFaltantesLiberacion` is null in two distinct cases, told apart by
/// [fechasFaltantesLiberacionIndeterminado]:
///   - `false` — the plaza has no ocupaciones chain to read at all.
///   - `true`  — the chain was read but the liberation count itself could
///     not be trusted. This is an honest "we don't know", never a fabricated
///     zero — screens must say so, never print a number.
@immutable
class CambiosPlaza {
  final int plazaId;

  /// `'campo'` or `'suplente'` — kept as the raw wire value; see [tipoLabel]
  /// for the friendly Spanish label.
  final String tipo;

  final int titularPlayerId;
  final String titularNombre;

  final int? ocupantePlayerId;
  final String? ocupanteNombre;

  final bool esTitularElOcupante;
  final bool cerrada;

  final int? fechasFaltantesLiberacion;
  final bool fechasFaltantesLiberacionIndeterminado;

  const CambiosPlaza({
    required this.plazaId,
    required this.tipo,
    required this.titularPlayerId,
    required this.titularNombre,
    this.ocupantePlayerId,
    this.ocupanteNombre,
    required this.esTitularElOcupante,
    required this.cerrada,
    this.fechasFaltantesLiberacion,
    this.fechasFaltantesLiberacionIndeterminado = false,
  });

  /// `'campo'` → "Campo", `'suplente'` → "Suplente", anything else → the raw
  /// value with the first letter capitalized (defensive — never blank).
  String get tipoLabel {
    switch (tipo) {
      case 'campo':
        return 'Campo';
      case 'suplente':
        return 'Suplente';
      default:
        return tipo.isEmpty
            ? 'Plaza'
            : tipo[0].toUpperCase() + tipo.substring(1);
    }
  }

  /// Whether this plaza is open for a "pedir regreso" action: someone other
  /// than the titular occupies it, it is not closed, and we KNOW (not just
  /// hope) that zero fechas remain before the titular may return.
  bool get regresoElegible =>
      !cerrada &&
      !esTitularElOcupante &&
      ocupantePlayerId != null &&
      !fechasFaltantesLiberacionIndeterminado &&
      fechasFaltantesLiberacion == 0;

  factory CambiosPlaza.fromJson(Map<String, dynamic> json) {
    return CambiosPlaza(
      plazaId: (json['plaza_id'] as int?) ?? 0,
      tipo: (json['tipo'] as String?) ?? '',
      titularPlayerId: (json['titular_player_id'] as int?) ?? 0,
      titularNombre: (json['titular_nombre'] as String?) ?? '',
      ocupantePlayerId: json['ocupante_player_id'] as int?,
      ocupanteNombre: json['ocupante_nombre'] as String?,
      esTitularElOcupante: json['es_titular_el_ocupante'] == true,
      cerrada: json['cerrada'] == true,
      fechasFaltantesLiberacion: json['fechas_faltantes_liberacion'] as int?,
      fechasFaltantesLiberacionIndeterminado:
          json['fechas_faltantes_liberacion_indeterminado'] == true,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CambiosPlaza &&
          runtimeType == other.runtimeType &&
          plazaId == other.plazaId &&
          tipo == other.tipo &&
          titularPlayerId == other.titularPlayerId &&
          titularNombre == other.titularNombre &&
          ocupantePlayerId == other.ocupantePlayerId &&
          ocupanteNombre == other.ocupanteNombre &&
          esTitularElOcupante == other.esTitularElOcupante &&
          cerrada == other.cerrada &&
          fechasFaltantesLiberacion == other.fechasFaltantesLiberacion &&
          fechasFaltantesLiberacionIndeterminado ==
              other.fechasFaltantesLiberacionIndeterminado;

  @override
  int get hashCode => Object.hash(
        plazaId,
        tipo,
        titularPlayerId,
        titularNombre,
        ocupantePlayerId,
        ocupanteNombre,
        esTitularElOcupante,
        cerrada,
        fechasFaltantesLiberacion,
        fechasFaltantesLiberacionIndeterminado,
      );

  @override
  String toString() =>
      'CambiosPlaza(plazaId: $plazaId, tipo: $tipo, titular: $titularNombre, '
      'ocupante: $ocupanteNombre, cerrada: $cerrada)';
}

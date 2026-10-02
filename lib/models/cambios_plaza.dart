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

  final int titularPlayerId;
  final String titularNombre;

  final int? ocupantePlayerId;
  final String? ocupanteNombre;

  final bool esTitularElOcupante;
  final bool cerrada;

  /// The plaza's own ceiling, as a decimal (e.g. `2.5`) — the SAME constraint
  /// `GET /cambios/plazas/candidatos` already enforces server-side. This
  /// screen only ever DISPLAYS it (e.g. greying out puntaje filter chips
  /// above it) — it must never compute eligibility from this value itself;
  /// see `Rest\PlazasController::listar()`'s own docblock on the backend.
  final double puntajeTecho;

  final int? fechasFaltantesLiberacion;
  final bool fechasFaltantesLiberacionIndeterminado;

  const CambiosPlaza({
    required this.plazaId,
    required this.titularPlayerId,
    required this.titularNombre,
    this.ocupantePlayerId,
    this.ocupanteNombre,
    required this.esTitularElOcupante,
    required this.cerrada,
    this.puntajeTecho = 0,
    this.fechasFaltantesLiberacion,
    this.fechasFaltantesLiberacionIndeterminado = false,
  });

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
      titularPlayerId: (json['titular_player_id'] as int?) ?? 0,
      titularNombre: (json['titular_nombre'] as String?) ?? '',
      ocupantePlayerId: json['ocupante_player_id'] as int?,
      ocupanteNombre: json['ocupante_nombre'] as String?,
      esTitularElOcupante: json['es_titular_el_ocupante'] == true,
      cerrada: json['cerrada'] == true,
      puntajeTecho: (json['puntaje_techo'] as num?)?.toDouble() ?? 0,
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
          titularPlayerId == other.titularPlayerId &&
          titularNombre == other.titularNombre &&
          ocupantePlayerId == other.ocupantePlayerId &&
          ocupanteNombre == other.ocupanteNombre &&
          esTitularElOcupante == other.esTitularElOcupante &&
          cerrada == other.cerrada &&
          puntajeTecho == other.puntajeTecho &&
          fechasFaltantesLiberacion == other.fechasFaltantesLiberacion &&
          fechasFaltantesLiberacionIndeterminado ==
              other.fechasFaltantesLiberacionIndeterminado;

  @override
  int get hashCode => Object.hash(
        plazaId,
        titularPlayerId,
        titularNombre,
        ocupantePlayerId,
        ocupanteNombre,
        esTitularElOcupante,
        cerrada,
        puntajeTecho,
        fechasFaltantesLiberacion,
        fechasFaltantesLiberacionIndeterminado,
      );

  @override
  String toString() =>
      'CambiosPlaza(plazaId: $plazaId, titular: $titularNombre, '
      'ocupante: $ocupanteNombre, cerrada: $cerrada)';
}

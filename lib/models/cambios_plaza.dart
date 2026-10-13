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

  /// Whether this is the team's ONE goal plaza — the STORED
  /// `cambios_plaza.es_arco` column (0.1.13), read by `GET /cambios/plazas`
  /// as a plain fact since 0.1.18 (`Rest\PlazasController::shapePlaza()`).
  /// Never derived client-side from the titular's position name — see
  /// `cambios_plantel_screen.dart`'s `_TitularCard` and
  /// `cambios_arco_titular_picker_screen.dart` for the two places that used
  /// to do exactly that (a string compare against `'Arquero'`) before this
  /// field existed, which risked silently drifting from this same column
  /// the moment a `sp_position` term was renamed.
  ///
  /// Missing/malformed JSON degrades to `false`, never to "this is the goal
  /// plaza" — an absent fact must never read as a confirmed one.
  final bool esArco;

  /// The plaza's own ceiling, as a decimal (e.g. `2.5`) — the SAME constraint
  /// `GET /cambios/plazas/candidatos` already enforces server-side. This
  /// screen only ever DISPLAYS it (e.g. greying out puntaje filter chips
  /// above it) — it must never compute eligibility from this value itself;
  /// see `Rest\PlazasController::listar()`'s own docblock on the backend.
  final double puntajeTecho;

  final int? fechasFaltantesLiberacion;
  final bool fechasFaltantesLiberacionIndeterminado;

  /// The request already standing against this plaza, or null if there is
  /// none — see [CambiosSolicitudAbierta]. Every action that would file a
  /// NEW request for this plaza is replaced by a legend while this is set,
  /// so a captain cannot queue two changes on one slot.
  ///
  /// *** THIS FIELD IS A COURTESY, NOT THE GUARD ***
  /// Unlike [esArco], a missing or malformed value here degrades to "no open
  /// request", which shows the buttons again. That is deliberate and it is
  /// only safe because the server refuses the request anyway
  /// (`Dictamen\Reglas\PlazaSinSolicitudAbierta`): the worst case is a
  /// captain who taps and is told no, never a second request that slips
  /// through. Hiding a button has never been a rule — if this field is ever
  /// the ONLY thing standing between a captain and a double booking, the
  /// rule has been deleted and that is the bug to fix.
  final CambiosSolicitudAbierta? solicitudAbierta;

  const CambiosPlaza({
    required this.plazaId,
    required this.titularPlayerId,
    required this.titularNombre,
    this.ocupantePlayerId,
    this.ocupanteNombre,
    required this.esTitularElOcupante,
    required this.cerrada,
    this.esArco = false,
    this.puntajeTecho = 0,
    this.fechasFaltantesLiberacion,
    this.fechasFaltantesLiberacionIndeterminado = false,
    this.solicitudAbierta,
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
      esArco: json['es_arco'] == true,
      puntajeTecho: (json['puntaje_techo'] as num?)?.toDouble() ?? 0,
      fechasFaltantesLiberacion: json['fechas_faltantes_liberacion'] as int?,
      fechasFaltantesLiberacionIndeterminado:
          json['fechas_faltantes_liberacion_indeterminado'] == true,
      solicitudAbierta: CambiosSolicitudAbierta.fromJson(json['solicitud_abierta']),
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
          esArco == other.esArco &&
          puntajeTecho == other.puntajeTecho &&
          fechasFaltantesLiberacion == other.fechasFaltantesLiberacion &&
          fechasFaltantesLiberacionIndeterminado ==
              other.fechasFaltantesLiberacionIndeterminado &&
          solicitudAbierta == other.solicitudAbierta;

  @override
  int get hashCode => Object.hash(
        plazaId,
        titularPlayerId,
        titularNombre,
        ocupantePlayerId,
        ocupanteNombre,
        esTitularElOcupante,
        cerrada,
        esArco,
        puntajeTecho,
        fechasFaltantesLiberacion,
        fechasFaltantesLiberacionIndeterminado,
        solicitudAbierta,
      );

  @override
  String toString() =>
      'CambiosPlaza(plazaId: $plazaId, titular: $titularNombre, '
      'ocupante: $ocupanteNombre, cerrada: $cerrada)';
}

/// One still-open request standing against a plaza, as `GET /cambios/plazas`
/// reports it in `solicitud_abierta`. "Open" means the backend's own
/// definition — `pendiente` OR `aprobada` — because an approved request is
/// merely waiting for the publication batch: the plaza is already spoken for.
///
/// [estado] is carried for diagnostics and for whoever needs the distinction
/// later; the roster screen deliberately does NOT branch on it. The process
/// owner's call: a captain looking at his roster only needs to know the plaza
/// is taken, and the exact estado belongs on "Mis pedidos", which is where he
/// goes to follow it.
@immutable
class CambiosSolicitudAbierta {
  final int id;
  final String estado;

  const CambiosSolicitudAbierta({required this.id, required this.estado});

  static CambiosSolicitudAbierta? fromJson(Object? json) {
    if (json is! Map) return null;
    final id = json['id'];
    if (id is! int || id <= 0) return null;
    return CambiosSolicitudAbierta(
      id: id,
      estado: (json['estado'] as String?) ?? '',
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CambiosSolicitudAbierta &&
          runtimeType == other.runtimeType &&
          id == other.id &&
          estado == other.estado;

  @override
  int get hashCode => Object.hash(runtimeType, id, estado);

  @override
  String toString() => 'CambiosSolicitudAbierta(id: $id, estado: $estado)';
}

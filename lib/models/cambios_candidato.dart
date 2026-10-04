import 'package:flutter/foundation.dart';

/// Which of the two candidate pools `GET /cambios/plazas/candidatos?seccion=`
/// enumerates — mirrors `Plazas\CandidatosSeccion` on the backend. Neither
/// name editorializes about approval odds (see that backend class's own
/// docblock) — both are neutral descriptions of WHERE a candidate comes
/// from, never a hint about how likely the committee is to approve them.
///
/// Omitting `?seccion` entirely keeps the backend's pre-existing
/// season-registered pool — this app never does that any more (both screen
/// sections always pass one of these two), but the backend still accepts the
/// omission for other callers; see `CambiosApiService.fetchCandidatos()`.
enum CambiosCandidatosSeccion {
  /// Players on the "lista de espera" pseudo-team — who actually signed up
  /// to come in as a cambio.
  listaEspera,

  /// Every OTHER published player, i.e. the whole padrón minus the lista de
  /// espera team — widened on purpose so a previously-registered,
  /// already-rated player can come in even without being on this year's
  /// `sp_season` list.
  padronCompleto;

  String toWire() {
    switch (this) {
      case CambiosCandidatosSeccion.listaEspera:
        return 'lista_espera';
      case CambiosCandidatosSeccion.padronCompleto:
        return 'padron_completo';
    }
  }
}

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

/// One page of `GET /cambios/plazas/candidatos`, as
/// [CambiosApiService.fetchCandidatos] returns it: the page's own candidatos,
/// plus [total] — the size of the WHOLE matching population (after
/// `search`/`puntajes`, BEFORE pagination — see
/// `Rest\PlazasController::listarCandidatos()`'s own docblock on the
/// backend, "PAGINATION"), read from the `X-WP-Total` response header, the
/// SAME convention `ApiService.getJugadoresRaw()` already reads. A screen
/// decides whether more pages remain from [total] and its own
/// page/per-page math, never from how many items THIS page returned (a page
/// can legitimately come back short of `per_page` while more pages remain —
/// see that same backend docblock for why).
typedef CambiosCandidatosPagina = ({List<CambiosCandidato> candidatos, int total});

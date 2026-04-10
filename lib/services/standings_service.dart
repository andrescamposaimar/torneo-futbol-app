/// Lógica de negocio para ordenar equipos en tablas de posiciones.
///
/// Criterio de desempate:
///   1. Puntos (pts) — mayor primero.
///   2. Resultado directo entre los equipos empatados.
///   3. Diferencia de goles (dg) — mayor primero.
///   4. Goles a favor (gf) — mayor primero.
class StandingsService {
  /// Ordena [equipos] aplicando el criterio de desempate y re-asigna
  /// el campo 'posicion' (1-based) en cada elemento.
  static void ordenarYAsignarPosicion(
    List<dynamic> equipos,
    List<dynamic> partidosTemporada,
  ) {
    equipos.sort((a, b) {
      final ptsA = _parseInt(a['pts']);
      final ptsB = _parseInt(b['pts']);
      if (ptsB != ptsA) return ptsB.compareTo(ptsA);

      final winner = _compararDirecto(a, b, partidosTemporada);
      if (winner != 0) return -winner;

      final dgA = _parseInt(a['dg']);
      final dgB = _parseInt(b['dg']);
      if (dgB != dgA) return dgB.compareTo(dgA);

      final gfA = _parseInt(a['gf']);
      final gfB = _parseInt(b['gf']);
      return gfB.compareTo(gfA);
    });

    for (var i = 0; i < equipos.length; i++) {
      equipos[i]['posicion'] = i + 1;
    }
  }

  /// Compara los resultados directos entre [a] y [b] dentro de [partidos].
  /// Retorna -1 si A ganó el enfrentamiento, 1 si B ganó, 0 en otro caso.
  static int _compararDirecto(
    dynamic a,
    dynamic b,
    List<dynamic> partidos,
  ) {
    final nombreA = a['equipo'];
    final nombreB = b['equipo'];

    for (final partido in partidos) {
      final local = partido['equipo_local'];
      final visitante = partido['equipo_visitante'];

      if ((local == nombreA && visitante == nombreB) ||
          (local == nombreB && visitante == nombreA)) {
        final gl = _parseInt(partido['goles_local']);
        final gv = _parseInt(partido['goles_visitante']);

        if (local == nombreA && gl > gv) return -1;
        if (local == nombreB && gv > gl) return 1;
        if (local == nombreA && gv > gl) return 1;
        if (local == nombreB && gl > gv) return -1;
      }
    }
    return 0;
  }

  static int _parseInt(dynamic value) {
    if (value == null) return 0;
    if (value is int) return value;
    if (value is String) return int.tryParse(value) ?? 0;
    return 0;
  }
}

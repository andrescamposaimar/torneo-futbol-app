/// Lógica de negocio para filtrar y ordenar jugadores en listas de espera/reserva.
/// Funciones puras sin dependencias de Flutter — testeables con dart test.
class PlayerFilterService {
  /// Filtra una lista de jugadores por nombre, posición y puntajes seleccionados.
  ///
  /// - [query]: texto de búsqueda sobre el nombre del jugador (vacío = sin filtro)
  /// - [posicion]: posición exacta a filtrar (null = todas las posiciones)
  /// - [puntajes]: lista de puntajes a incluir (vacía = todos los puntajes)
  static List<dynamic> filtrar(
    List<dynamic> jugadores, {
    String query = '',
    String? posicion,
    List<double> puntajes = const [],
  }) {
    final queryLower = query.toLowerCase();
    return jugadores.where((j) {
      final nombre = (j['title']?['rendered'] ?? '').toLowerCase();
      final pos = (j['posicion'] ?? '').toString();
      final puntajeRaw = j['metrics']?['puntaje'];
      final puntaje = _parsePuntaje(puntajeRaw);

      final matchNombre = queryLower.isEmpty || nombre.contains(queryLower);
      final matchPos = posicion == null || pos == posicion;
      final matchPts = puntajes.isEmpty || puntajes.contains(puntaje);

      return matchNombre && matchPos && matchPts;
    }).toList();
  }

  /// Comparador para ordenar jugadores de mayor a menor puntaje.
  /// Usar con List.sort(): `jugadores.sort(PlayerFilterService.comparadorPuntaje)`
  static int comparadorPuntaje(dynamic a, dynamic b) {
    final aNum = _parsePuntaje(a['metrics']?['puntaje']);
    final bNum = _parsePuntaje(b['metrics']?['puntaje']);
    return bNum.compareTo(aNum);
  }

  static double _parsePuntaje(dynamic valor) {
    if (valor is num) return valor.toDouble();
    if (valor is String) return double.tryParse(valor.replaceAll(',', '.')) ?? 0;
    return 0;
  }
}

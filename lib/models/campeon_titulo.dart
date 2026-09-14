/// One Copa Chaminade title won by the viewed player, as fed by
/// `GET /entre-redes/v1/campeones/jugador/{id}/titulos` (API-2).
///
/// `fromJson` follows the `Jugador.fromJson` convention: tolerant parsing,
/// defaults rather than throwing, and an `_parseInt` that accepts both `num`
/// and `String` since WordPress meta occasionally travels as a string.
class JugadorTitulo {
  final int anio;
  final String zona;
  final String equipoNombre;
  final bool esCapitan;

  const JugadorTitulo({
    required this.anio,
    required this.zona,
    required this.equipoNombre,
    required this.esCapitan,
  });

  factory JugadorTitulo.fromJson(Map<String, dynamic> json) {
    return JugadorTitulo(
      anio: _parseInt(json['anio']),
      zona: (json['zona'] as String?) ?? '',
      equipoNombre: (json['equipo_nombre'] as String?) ?? '',
      esCapitan: json['es_capitan'] == true,
    );
  }

  static int _parseInt(dynamic value) {
    if (value is num) return value.toInt();
    if (value is String) return int.tryParse(value) ?? 0;
    return 0;
  }
}

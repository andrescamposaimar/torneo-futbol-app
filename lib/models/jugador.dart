class EstadisticasJugador {
  final int partidosJugados;
  final int goles;
  final int temporadas;

  /// Whether the API actually sent the stats block. A player with zero matches
  /// is a real answer; a backend that does not know how to count them is not,
  /// and the screen must not render the two as the same thing.
  final bool disponible;

  const EstadisticasJugador({
    this.partidosJugados = 0,
    this.goles = 0,
    this.temporadas = 0,
    this.disponible = false,
  });

  factory EstadisticasJugador.fromJson(Map<String, dynamic> json) {
    return EstadisticasJugador(
      partidosJugados: _parseInt(json['partidos_jugados']),
      goles: _parseInt(json['goles']),
      temporadas: _parseInt(json['temporadas']),
      disponible: true,
    );
  }

  /// The API returns these as numbers, but WordPress meta occasionally travels
  /// as a string, so both shapes are accepted.
  static int _parseInt(dynamic value) {
    if (value is num) return value.toInt();
    if (value is String) return int.tryParse(value) ?? 0;
    return 0;
  }
}

class Jugador {
  final int id;
  final String nombre;
  final String? imagen;
  final String posicion;
  final double puntaje;
  final EstadisticasJugador estadisticas;
  final String equipo;
  final int? equipoId;
  final String escudo;
  final String? fechaNacimiento;
  final List<dynamic> temporadas;
  final bool capitan;
  final bool reemplazoAlta;
  final bool reemplazoBaja;
  final Map<String, dynamic> raw;

  const Jugador({
    required this.id,
    required this.nombre,
    this.imagen,
    required this.posicion,
    required this.puntaje,
    this.estadisticas = const EstadisticasJugador(),
    required this.equipo,
    this.equipoId,
    required this.escudo,
    this.fechaNacimiento,
    required this.temporadas,
    this.capitan = false,
    this.reemplazoAlta = false,
    this.reemplazoBaja = false,
    required this.raw,
  });

  factory Jugador.fromJson(Map<String, dynamic> json) {
    final metrics = json['metrics'] ?? {};
    final dynamic puntajeRaw = metrics['puntaje'];
    double parsedPuntaje = 0;

    if (puntajeRaw is num) {
      parsedPuntaje = puntajeRaw.toDouble();
    } else if (puntajeRaw is String) {
      parsedPuntaje = double.tryParse(puntajeRaw.replaceAll(',', '.')) ?? 0;
    }

    final imagenRaw = json['featured_image'];

    return Jugador(
      id: json['id'],
      nombre: json['title']?['rendered'] ?? 'Sin nombre',
      imagen: (imagenRaw is String && imagenRaw.isNotEmpty) ? imagenRaw : null,
      posicion: (json['posicion'] ?? json['position'] ?? '-').toString(),
      puntaje: parsedPuntaje,
      estadisticas: json['estadisticas'] is Map
          ? EstadisticasJugador.fromJson(
              Map<String, dynamic>.from(json['estadisticas'] as Map))
          : const EstadisticasJugador(),
      equipo: json['equipo']?.toString() ?? 'Sin equipo',
      equipoId: json['equipo_id'] != null ? int.tryParse(json['equipo_id'].toString()) : null,
      escudo: json['escudo'] ?? '',
      fechaNacimiento: json['fecha_nacimiento'],
      temporadas: json['temporadas'] ?? [],
      capitan: json['capitan'] == true,
      reemplazoAlta: json['reemplazo_alta'] == true,
      reemplazoBaja: json['reemplazo_baja'] == true,
      raw: json,
    );
  }

  Map<String, dynamic> toJson() => raw;
}

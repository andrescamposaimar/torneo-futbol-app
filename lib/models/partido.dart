class Partido {
  final int id;
  final String equipoLocal;
  final String equipoVisitante;
  final String? golesLocal;
  final String? golesVisitante;
  final String? fecha;
  final String? hora;
  final String liga;
  final String? escudoLocal;
  final String? escudoVisitante;
  final String? cancha;
  final String? arbitro;
  final String? status;
  final int? temporada;
  final Map<String, dynamic> raw;

  const Partido({
    required this.id,
    required this.equipoLocal,
    required this.equipoVisitante,
    this.golesLocal,
    this.golesVisitante,
    this.fecha,
    this.hora,
    required this.liga,
    this.escudoLocal,
    this.escudoVisitante,
    this.cancha,
    this.arbitro,
    this.status,
    this.temporada,
    required this.raw,
  });

  factory Partido.fromJson(Map<String, dynamic> json) {
    return Partido(
      id: json['id'],
      equipoLocal: json['equipo_local']?.toString() ?? 'Local',
      equipoVisitante: json['equipo_visitante']?.toString() ?? 'Visitante',
      golesLocal: json['goles_local']?.toString(),
      golesVisitante: json['goles_visitante']?.toString(),
      fecha: json['fecha']?.toString(),
      hora: json['hora']?.toString(),
      liga: json['liga']?.toString() ?? '',
      escudoLocal: json['escudo_local']?.toString(),
      escudoVisitante: json['escudo_visitante']?.toString(),
      cancha: json['cancha']?.toString(),
      arbitro: json['arbitro']?.toString(),
      status: json['status']?.toString(),
      temporada: json['temporada'] != null
          ? int.tryParse(json['temporada'].toString())
          : null,
      raw: json,
    );
  }

  Map<String, dynamic> toJson() => raw;
}

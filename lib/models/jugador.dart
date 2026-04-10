class Jugador {
  final int id;
  final String nombre;
  final String? imagen;
  final String posicion;
  final double puntaje;
  final String caracter;
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
    required this.caracter,
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
      caracter: metrics['caracter']?.toString() ?? '-',
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

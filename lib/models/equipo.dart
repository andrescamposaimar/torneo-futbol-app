class Equipo {
  final int id;
  final String nombre;
  final String? imagen;
  final String? escudo;
  final List<dynamic> temporadas;
  final Map<String, dynamic> raw;

  const Equipo({
    required this.id,
    required this.nombre,
    this.imagen,
    this.escudo,
    required this.temporadas,
    required this.raw,
  });

  factory Equipo.fromJson(Map<String, dynamic> json) {
    final imagenRaw = json['imagen'];
    final escudoRaw = json['escudo'];
    return Equipo(
      id: json['id'],
      nombre: json['nombre']?.toString() ?? 'Sin nombre',
      imagen: (imagenRaw is String && imagenRaw.isNotEmpty) ? imagenRaw : null,
      escudo: (escudoRaw is String && escudoRaw.isNotEmpty) ? escudoRaw : null,
      temporadas: json['temporadas'] ?? [],
      raw: json,
    );
  }

  /// URL de logo preferida: escudo primero, imagen como fallback.
  String? get logoUrl => escudo ?? imagen;

  Map<String, dynamic> toJson() => raw;
}

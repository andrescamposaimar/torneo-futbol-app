class Temporada {
  final int id;
  final String name;
  final bool isCurrent;
  final Map<String, dynamic> raw;

  const Temporada({
    required this.id,
    required this.name,
    required this.isCurrent,
    required this.raw,
  });

  factory Temporada.fromJson(Map<String, dynamic> json) {
    return Temporada(
      id: json['id'],
      name: json['name']?.toString() ?? '',
      isCurrent: json['is_current'] == true,
      raw: json,
    );
  }

  Map<String, dynamic> toJson() => raw;
}

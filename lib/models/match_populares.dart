/// How the Prode crowd predicted one match.
///
/// Mirrors `GET /prode/populares`. [porcentajes] is null both while the round
/// is still open — the backend withholds the split so a late voter cannot copy
/// the crowd — and when nobody predicted the match. [total] tells the two
/// apart: an open round reports 0, and so does a match nobody played.
class MatchPopulares {
  final int matchId;
  final int total;
  final double? local;
  final double? empate;
  final double? visitante;

  const MatchPopulares({
    required this.matchId,
    required this.total,
    this.local,
    this.empate,
    this.visitante,
  });

  /// True when there is a split worth rendering.
  bool get hayDatos =>
      local != null && empate != null && visitante != null && total > 0;

  factory MatchPopulares.fromJson(Map<String, dynamic> json) {
    final porcentajes = json['populares'];

    double? leer(String clave) {
      if (porcentajes is! Map) return null;
      final valor = porcentajes[clave];
      if (valor is num) return valor.toDouble();
      return double.tryParse(valor?.toString() ?? '');
    }

    return MatchPopulares(
      matchId: int.tryParse(json['match_id']?.toString() ?? '') ?? 0,
      total: int.tryParse(json['total']?.toString() ?? '') ?? 0,
      local: leer('1'),
      empate: leer('X'),
      visitante: leer('2'),
    );
  }
}

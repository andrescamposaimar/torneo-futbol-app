/// Prioridad numérica de una liga/zona para ordenarlas en pantalla.
///
/// Si se pasa [orden], se usa el índice del nombre en esa lista (case-insensitive).
/// Si no se encuentra o no se pasa [orden], se usa el orden hardcodeado:
/// Clausura A→0, B→1, C→2.
/// Apertura  A→3, B→4, C→5.
/// Clasificación 1→7 … 6→12.
/// Cualquier otra→99.
int prioridadLiga(String? liga, {List<String>? orden}) {
  final l = liga?.toLowerCase() ?? '';

  if (orden != null && orden.isNotEmpty) {
    final idx = orden.indexWhere((e) => e.toLowerCase() == l);
    return idx >= 0 ? idx : 99;
  }

  final clausuraMatch = RegExp(r'clausura.*\b(a|b|c)\b').firstMatch(l);
  if (clausuraMatch != null) {
    switch (clausuraMatch.group(1)) {
      case 'a': return 0;
      case 'b': return 1;
      case 'c': return 2;
    }
  }

  final aperturaMatch = RegExp(r'apertura.*\b(a|b|c)\b').firstMatch(l);
  if (aperturaMatch != null) {
    switch (aperturaMatch.group(1)) {
      case 'a': return 3;
      case 'b': return 4;
      case 'c': return 5;
    }
  }

  final clasifMatch = RegExp(r'clasificacion.*\b([1-6])\b').firstMatch(l);
  if (clasifMatch != null) {
    return 6 + int.parse(clasifMatch.group(1)!); // 7–12
  }

  return 99;
}

/// Prioridad numérica de un título de tabla (para filtros de StandingsScreen).
///
/// Clausura→0, Apertura→1, Clasificación→2, otros→3.
int prioridadTitulo(String titulo) {
  final lower = titulo.toLowerCase();
  if (lower.contains('clausura')) return 0;
  if (lower.contains('apertura')) return 1;
  if (lower.contains('clasificacion')) return 2;
  return 3;
}

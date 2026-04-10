/// Formatea un valor de puntaje a String con 0 o 1 decimal.
/// Retorna '-' si el valor es nulo, booleano o no parseable.
String formatearPuntaje(dynamic valor) {
  try {
    if (valor == null || valor is bool) return '-';
    if (valor is num) {
      return valor.toStringAsFixed(valor.truncateToDouble() == valor ? 0 : 1);
    }
    if (valor is String) {
      final normalizado = valor.replaceAll(',', '.');
      final numParsed = double.tryParse(normalizado);
      if (numParsed != null) {
        return numParsed.toStringAsFixed(numParsed.truncateToDouble() == numParsed ? 0 : 1);
      }
    }
  } catch (_) {}
  return '-';
}

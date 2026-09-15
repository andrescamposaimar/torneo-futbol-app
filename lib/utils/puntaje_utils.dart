/// Formatea un valor de puntaje a String con 0 o 1 decimal.
/// Retorna '-' si el valor es nulo, booleano, no parseable, o cero.
///
/// Un puntaje en 0 significa "todavía sin calificar", nunca "calificado con
/// cero": los jugadores arrancan sin puntaje y el backend representa eso
/// como 0 (numérico o string, incluida la forma con coma decimal). Por eso
/// esta función trata el cero igual que a un valor ausente — es la única
/// función por la que pasan los cinco call sites, así que es el único lugar
/// donde esa regla puede vivir sin poder olvidarse en alguno de ellos.
String formatearPuntaje(dynamic valor) {
  try {
    if (valor == null || valor is bool) return '-';
    if (valor is num) {
      if (valor == 0) return '-';
      return valor.toStringAsFixed(valor.truncateToDouble() == valor ? 0 : 1);
    }
    if (valor is String) {
      final normalizado = valor.replaceAll(',', '.');
      final numParsed = double.tryParse(normalizado);
      if (numParsed != null) {
        if (numParsed == 0) return '-';
        return numParsed.toStringAsFixed(numParsed.truncateToDouble() == numParsed ? 0 : 1);
      }
    }
  } catch (_) {}
  return '-';
}

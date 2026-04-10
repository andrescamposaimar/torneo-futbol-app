import 'package:intl/intl.dart';

/// Calcula la edad en años a partir de una fecha de nacimiento.
/// Acepta [fechaNacimiento] como String (ISO 8601) o cualquier tipo.
/// Retorna 0 si el valor es nulo, vacío o no parseable.
int calcularEdad(dynamic fechaNacimiento) {
  if (fechaNacimiento is! String || fechaNacimiento.isEmpty) return 0;
  try {
    final nacimiento = DateTime.parse(fechaNacimiento);
    final hoy = DateTime.now();
    int edad = hoy.year - nacimiento.year;
    if (hoy.month < nacimiento.month ||
        (hoy.month == nacimiento.month && hoy.day < nacimiento.day)) {
      edad--;
    }
    return edad;
  } catch (_) {
    return 0;
  }
}

/// Formatea una fecha de nacimiento ISO 8601 a 'dd/MM/yyyy'.
/// Retorna '-' si el valor es nulo, vacío o no parseable.
String formatFechaNacimiento(String? nacimiento) {
  if (nacimiento == null || nacimiento.isEmpty) return '-';
  try {
    final parsed = DateTime.parse(nacimiento);
    return DateFormat('dd/MM/yyyy').format(parsed);
  } catch (_) {
    return '-';
  }
}

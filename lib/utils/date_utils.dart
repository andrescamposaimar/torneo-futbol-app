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

const List<String> _diasSemana = [
  'Lunes',
  'Martes',
  'Miércoles',
  'Jueves',
  'Viernes',
  'Sábado',
  'Domingo',
];

const List<String> _meses = [
  'enero',
  'febrero',
  'marzo',
  'abril',
  'mayo',
  'junio',
  'julio',
  'agosto',
  'septiembre',
  'octubre',
  'noviembre',
  'diciembre',
];

/// Formatea una fecha ISO 8601 a texto largo en español,
/// por ejemplo 'Sábado 8 de agosto de 2026'.
///
/// Los nombres de días y meses están hardcodeados a propósito: la app no
/// inicializa los datos de locale de `intl`, por lo que `DateFormat` con
/// locale 'es' lanzaría en runtime.
///
/// Retorna null si el valor es nulo, vacío o no parseable, para que el
/// llamador decida el texto de fallback.
String? formatFechaLarga(String? fecha) {
  if (fecha == null || fecha.isEmpty) return null;
  final parsed = DateTime.tryParse(fecha);
  if (parsed == null) return null;
  final dia = _diasSemana[parsed.weekday - 1];
  final mes = _meses[parsed.month - 1];
  return '$dia ${parsed.day} de $mes de ${parsed.year}';
}

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

/// Fixed UTC-3 offset for Argentina, this app's single league's timezone —
/// `Calendario\PlazosCalculator`'s own docblock on the backend documents it
/// as not observing DST. Used ONLY to turn an already-known UTC instant
/// (never `DateTime.now()`) into the Argentina CALENDAR DATE it falls on for
/// display — a formatting step, never a clock comparison (the window's
/// open/closed decision itself is always computed on the backend; see
/// `CambiosSolicitarScreen`'s own docblock, "WHY THE WINDOW CHECK HAPPENS
/// HERE, NOT ONLY ON THE BACKEND", for the one check that IS mirrored on the
/// device, and why this is not another one of those).
///
/// A fixed offset — rather than `DateTime.toLocal()` — is deliberate: the
/// device's ambient timezone is whatever the user (or a test runner) happens
/// to be set to, and this app has exactly one civil calendar that matters
/// for a plazo date, Argentina's, independent of where the phone or the CI
/// machine think they are.
const Duration argentinaUtcOffset = Duration(hours: -3);

/// Formats a UTC [instanteUtc] as 'día dd/MM' in Argentina civil time, in
/// lowercase (e.g. 'domingo 11/10') — the short form a "window not open yet"
/// banner needs to tell a captain WHEN it opens. Reuses [_diasSemana] for
/// the same reason [formatFechaLarga] does: this app does not initialize
/// `intl`'s 'es' locale data, so a locale-aware `DateFormat` would throw.
String formatDiaYFechaCorta(DateTime instanteUtc) {
  final local = instanteUtc.toUtc().add(argentinaUtcOffset);
  final dia = _diasSemana[local.weekday - 1].toLowerCase();
  final dd = local.day.toString().padLeft(2, '0');
  final mm = local.month.toString().padLeft(2, '0');
  return '$dia $dd/$mm';
}

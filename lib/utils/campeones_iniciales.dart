/// Pure-Dart helpers for the Copa Chaminade history screen's squad-row
/// avatars (ADR-C2). No `package:flutter` import — precedent:
/// `lib/utils/campeones_copy.dart` — so these functions are unit tested
/// without a `WidgetTester`, no pump, no binding.
library;

/// First character of [s] matching a Unicode letter, or `null` if none.
/// Skips non-letters rather than taking `s[0]` blindly, so `D´AGOSTINO`
/// yields `D`, not `´` (U+00B4 — real production data, used as an
/// apostrophe).
String? _primeraLetra(String s) {
  final match = _letraRegExp.firstMatch(s);
  return match?.group(0);
}

final RegExp _letraRegExp = RegExp(r'\p{L}', unicode: true);

bool _tieneLetra(String s) => _letraRegExp.hasMatch(s);

/// Derives up to two initials for a champion squad entry.
///
/// Handles the stored `APELLIDO, X.` sheet form (comma branch: surname
/// initial + given-name initial, surname first — matching the label beside
/// it) and comma-less names (first token + last token). Returns `'?'` for a
/// name that contains no letter at all.
///
/// This function deliberately does NOT reimplement `NameParser`
/// (`wordpress_plugins/entre-redes-campeones/src/Linking/NameParser.php`):
/// no particle absorption, no `stripMiddleInitialPeriods`, no `validate()`.
/// `Andres Dos Santos` gives `AS` here while his match key is
/// `{DOS SANTOS, A}` — and that is correct: this is decoration on a name
/// already displayed in full, and nothing links off it. Do NOT "align"
/// these two rules — a second, drifting copy of the one algorithm that took
/// four review rounds to simplify would be far worse than an avatar reading
/// `AS`.
String inicialesDeNombre(String nombre) {
  final s = nombre.trim();
  if (s.isEmpty) return '?';

  final commaIndex = s.indexOf(',');
  if (commaIndex != -1) {
    final izq = s.substring(0, commaIndex);
    final der = s.substring(commaIndex + 1);
    final a = _primeraLetra(izq);
    final b = _primeraLetra(der);
    if (a != null && b != null) return (a + b).toUpperCase();
    if (a != null) return a.toUpperCase();
    if (b != null) return b.toUpperCase();
    return '?';
  }

  final tokens = s.split(RegExp(r'\s+')).where(_tieneLetra).toList();
  if (tokens.isEmpty) return '?';
  if (tokens.length == 1) return _primeraLetra(tokens.first)!.toUpperCase();
  final primero = _primeraLetra(tokens.first)!;
  final ultimo = _primeraLetra(tokens.last)!;
  return (primero + ultimo).toUpperCase();
}

/// Deterministic palette index for [iniciales]. Pure arithmetic — the same
/// initials always yield the same index, on every run and every device.
///
/// Deliberately NOT `String.hashCode`: Dart does not specify `hashCode` to
/// be stable across SDK versions, and it differs between the VM and
/// `dart2js`. "The same person always looks the same" must not rest on an
/// unspecified implementation detail — a sum of code units is fully
/// specified and trivially assertable.
///
/// Keyed on the initials, not the full name, so `BASSO, A.` / `Basso, A` /
/// `BASSO,  A.` all land on the same tint. Accepted, bounded limitation: an
/// accented first letter (`ÁLVAREZ` vs `ALVAREZ`) is a different code unit
/// and therefore a different tint — rare, purely decorative, not worth a
/// client-side copy of the name normalizer.
int indiceColorAvatar(String iniciales, {int cantidadColores = 3}) {
  if (iniciales.isEmpty) return 0;
  var suma = 0;
  for (final u in iniciales.codeUnits) {
    suma += u;
  }
  return suma % cantidadColores;
}

import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/services/rotating_code.dart';

/// Shared fixture with `wordpress_plugins/entre-redes-credencial/tests/Code/RotatingCodeTest.php`
/// (design D4): every vector below must reproduce the PHP `RotatingCode::code()`
/// output byte for byte — this is the ONLY piece of the server's RotatingCode
/// the Dart client needs to port (`seedFor()` is server-only, per that class's
/// own docblock).
void main() {
  group('RotatingCode.code()', () {
    const seed = 'c2hhcmVkLXRlc3QtdmVjdG9yLXNlZWQtMDAx';

    test('matches the shared server test vectors', () {
      expect(RotatingCode.code(seed, 0), equals('371289'));
      expect(RotatingCode.code(seed, 59), equals('267226'));
      expect(RotatingCode.code(seed, 60), equals('438525'));
      expect(RotatingCode.code(seed, 1700000000), equals('169218'));
    });

    test('is stable within the same 30s step', () {
      // Both instants fall in the same 30s window (counter 56666667) — the
      // code must not change mid-window.
      expect(
        RotatingCode.code(seed, 1700000015),
        equals(RotatingCode.code(seed, 1700000030)),
      );
    });

    test('is always six digits, zero-padded', () {
      for (var ts = 0; ts < 3000; ts += 30) {
        expect(RotatingCode.code(seed, ts), matches(RegExp(r'^\d{6}$')));
      }
    });

    test('changes across a 30s boundary', () {
      expect(
        RotatingCode.code(seed, 1700000000),
        isNot(equals(RotatingCode.code(seed, 1700000030))),
      );
    });
  });
}

import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/utils/date_utils.dart';

void main() {
  group('formatDiaYFechaCorta', () {
    test('converts a UTC instant to Argentina civil time (fixed UTC-3, no DST) '
        'and formats it as "día dd/MM", lowercase', () {
      // 2026-10-11 03:00:00 UTC - 3h = 2026-10-11 00:00:00 in Argentina, a
      // Sunday — the exact fecha #20 apertura_solicitudes case this fix is
      // about.
      expect(
        formatDiaYFechaCorta(DateTime.utc(2026, 10, 11, 3, 0, 0)),
        'domingo 11/10',
      );
    });

    test('a UTC instant that crosses midnight into the PREVIOUS Argentina '
        'calendar day formats as that earlier day — proves the fixed offset '
        'is actually applied, not a no-op', () {
      // 2026-10-11 02:00:00 UTC - 3h = 2026-10-10 23:00:00 in Argentina, a
      // Saturday — still October 10th locally, even though the instant is
      // already October 11th in UTC.
      expect(
        formatDiaYFechaCorta(DateTime.utc(2026, 10, 11, 2, 0, 0)),
        'sábado 10/10',
      );
    });

    test('pads single-digit day and month with a leading zero', () {
      // 2026-03-02 UTC-3 = 2026-03-01, a Sunday.
      expect(
        formatDiaYFechaCorta(DateTime.utc(2026, 3, 2, 2, 0, 0)),
        'domingo 01/03',
      );
    });
  });
}

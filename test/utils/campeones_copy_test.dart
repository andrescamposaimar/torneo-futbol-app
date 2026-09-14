import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/utils/campeones_copy.dart';

void main() {
  group('etiquetaTitulos', () {
    test('1 título uses the singular form', () {
      expect(etiquetaTitulos(1), '1 título');
    });

    test('0 títulos uses the plural form', () {
      expect(etiquetaTitulos(0), '0 títulos');
    });

    test('N > 1 títulos uses the plural form', () {
      expect(etiquetaTitulos(3), '3 títulos');
    });
  });
}

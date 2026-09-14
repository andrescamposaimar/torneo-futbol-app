import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/campeon_titulo.dart';

void main() {
  group('JugadorTitulo.fromJson', () {
    test('parses anio, zona, equipo_nombre and es_capitan', () {
      final titulo = JugadorTitulo.fromJson(const {
        'anio': 2016,
        'zona': 'A',
        'equipo_nombre': 'CHELSEA',
        'es_capitan': true,
      });

      expect(titulo.anio, 2016);
      expect(titulo.zona, 'A');
      expect(titulo.equipoNombre, 'CHELSEA');
      expect(titulo.esCapitan, isTrue);
    });

    test('tolerates anio arriving as a String (WordPress meta convention)', () {
      final titulo = JugadorTitulo.fromJson(const {
        'anio': '2016',
        'zona': 'A',
        'equipo_nombre': 'CHELSEA',
        'es_capitan': false,
      });

      expect(titulo.anio, 2016);
      expect(titulo.esCapitan, isFalse);
    });

    test('defaults missing optional fields instead of throwing', () {
      final titulo = JugadorTitulo.fromJson(const {'anio': 2016});

      expect(titulo.anio, 2016);
      expect(titulo.zona, '');
      expect(titulo.equipoNombre, '');
      expect(titulo.esCapitan, isFalse);
    });

    test('a non-numeric, non-string anio defaults to 0 rather than throwing', () {
      final titulo = JugadorTitulo.fromJson(const {'anio': null});

      expect(titulo.anio, 0);
    });
  });
}

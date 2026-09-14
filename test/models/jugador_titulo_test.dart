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

  group('CampeonTitulo.fromJson', () {
    test('parses anio, zona, posicion, equipo_nombre and plantel', () {
      final titulo = CampeonTitulo.fromJson(const {
        'anio': 2016,
        'zona': 'A',
        'posicion': '1',
        'equipo_nombre': 'CHELSEA',
        'plantel': [
          {
            'nombre': 'BASSO, A.',
            'es_capitan': true,
            'jugador_id': 42,
            'foto_url': 'https://example.com/basso.jpg',
          },
          {
            'nombre': 'MAZZARA, M.',
            'es_capitan': false,
            'jugador_id': null,
            'foto_url': null,
          },
        ],
      });

      expect(titulo.anio, 2016);
      expect(titulo.zona, 'A');
      // TitleRecord::$posicion travels as a string on the wire — must not
      // be coerced through the int-parsing convention used elsewhere.
      expect(titulo.posicion, '1');
      expect(titulo.equipoNombre, 'CHELSEA');
      expect(titulo.plantel, hasLength(2));

      expect(titulo.plantel[0].orden, 0);
      expect(titulo.plantel[0].nombre, 'BASSO, A.');
      expect(titulo.plantel[0].esCapitan, isTrue);
      expect(titulo.plantel[0].jugadorId, 42);
      expect(titulo.plantel[0].fotoUrl, 'https://example.com/basso.jpg');

      expect(titulo.plantel[1].orden, 1);
      expect(titulo.plantel[1].jugadorId, isNull);
      expect(titulo.plantel[1].fotoUrl, isNull);
    });

    test('defaults missing optional fields instead of throwing', () {
      final titulo = CampeonTitulo.fromJson(const {'anio': 2016});

      expect(titulo.anio, 2016);
      expect(titulo.zona, '');
      expect(titulo.posicion, '');
      expect(titulo.equipoNombre, '');
      expect(titulo.plantel, isEmpty);
    });

    test('a jugador_id arriving as a String is tolerated', () {
      final titulo = CampeonTitulo.fromJson(const {
        'anio': 2016,
        'plantel': [
          {'nombre': 'BASSO, A.', 'es_capitan': false, 'jugador_id': '42'},
        ],
      });

      expect(titulo.plantel.single.jugadorId, 42);
    });

    test('an empty-string foto_url is treated as absent', () {
      final titulo = CampeonTitulo.fromJson(const {
        'anio': 2016,
        'plantel': [
          {'nombre': 'BASSO, A.', 'es_capitan': false, 'foto_url': ''},
        ],
      });

      expect(titulo.plantel.single.fotoUrl, isNull);
    });
  });
}

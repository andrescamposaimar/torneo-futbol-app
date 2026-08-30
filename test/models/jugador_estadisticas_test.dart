import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/jugador.dart';

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

Map<String, dynamic> _jugadorJson({Object? estadisticas = _absent}) {
  return {
    'id': 4321,
    'title': {'rendered': 'Juan Pérez'},
    'equipo': 'Equipo A',
    'escudo': '',
    'temporadas': ['2024', '2025', '2026'],
    'metrics': {'puntaje': '7,5'},
    if (estadisticas != _absent) 'estadisticas': estadisticas,
  };
}

const Object _absent = Object();

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

void main() {
  group('EstadisticasJugador', () {
    test('reads the stats block sent by the API', () {
      final jugador = Jugador.fromJson(_jugadorJson(estadisticas: {
        'partidos_jugados': 42,
        'goles': 17,
        'temporadas': 3,
      }));

      expect(jugador.estadisticas.partidosJugados, 42);
      expect(jugador.estadisticas.goles, 17);
      expect(jugador.estadisticas.temporadas, 3);
      expect(jugador.estadisticas.disponible, isTrue);
    });

    test('accepts numeric strings, as WordPress meta sometimes returns them', () {
      final jugador = Jugador.fromJson(_jugadorJson(estadisticas: {
        'partidos_jugados': '42',
        'goles': '17',
        'temporadas': '3',
      }));

      expect(jugador.estadisticas.partidosJugados, 42);
      expect(jugador.estadisticas.goles, 17);
      expect(jugador.estadisticas.temporadas, 3);
    });

    test('a player with no matches is available and reads zero', () {
      final jugador = Jugador.fromJson(_jugadorJson(estadisticas: {
        'partidos_jugados': 0,
        'goles': 0,
        'temporadas': 1,
      }));

      expect(jugador.estadisticas.partidosJugados, 0);
      expect(jugador.estadisticas.goles, 0);
      expect(jugador.estadisticas.disponible, isTrue);
    });

    test('an API that does not send the block is not available', () {
      final jugador = Jugador.fromJson(_jugadorJson());

      expect(jugador.estadisticas.disponible, isFalse);
      expect(jugador.estadisticas.partidosJugados, 0);
      expect(jugador.estadisticas.goles, 0);
      expect(jugador.estadisticas.temporadas, 0);
    });

    test('a malformed block is treated as missing rather than as zeros', () {
      final jugador = Jugador.fromJson(_jugadorJson(estadisticas: 'nope'));

      expect(jugador.estadisticas.disponible, isFalse);
    });

    test('unparseable values fall back to zero without throwing', () {
      final jugador = Jugador.fromJson(_jugadorJson(estadisticas: {
        'partidos_jugados': null,
        'goles': 'siete',
      }));

      expect(jugador.estadisticas.partidosJugados, 0);
      expect(jugador.estadisticas.goles, 0);
      expect(jugador.estadisticas.temporadas, 0);
      expect(jugador.estadisticas.disponible, isTrue);
    });
  });
}

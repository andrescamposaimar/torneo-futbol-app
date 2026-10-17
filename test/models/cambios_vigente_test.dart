import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/cambios_vigente.dart';

Map<String, dynamic> _jugador(String nombre, {Object? puntaje, bool sin = false}) => {
      'player_id': 1,
      'nombre': nombre,
      'puntaje': puntaje,
      'sin_calificar': sin,
    };

Map<String, dynamic> _row() => {
      'team_id': 5,
      'team_nombre': 'Los Pibes',
      'plaza_id': 9,
      'es_arco': true,
      'baja': _jugador('Campos, Andres', puntaje: 3.5),
      'alta': _jugador('Grigorjew, Gerardo', puntaje: 4),
      'desde': {'fecha_id': 3, 'label': 'Apertura Fecha 7'},
      'situacion': 'Faltan 2 fechas para poder volver.',
      'situacion_tipo': 'faltan_fechas',
      'fechas_restantes': 2,
    };

CambiosVigenteJugador _j(double? puntaje, {bool sin = false}) =>
    CambiosVigenteJugador(playerId: 1, nombre: 'X, Y', puntaje: puntaje, sinCalificar: sin);

void main() {
  group('flipNombre', () {
    test('flips Apellido, Nombre', () => expect(flipNombre('Campos, Andres'), 'Andres Campos'));
    test('splits on the FIRST comma only',
        () => expect(flipNombre('De la Cruz, Juan, Jr'), 'Juan, Jr De la Cruz'));
    test('trims and keeps names without comma', () => expect(flipNombre('  Pepe  '), 'Pepe'));
  });

  group('formatPuntajeVigente', () {
    test('decimal with comma', () => expect(formatPuntajeVigente(_j(3.5)), '3,5'));
    test('integer without decimals', () => expect(formatPuntajeVigente(_j(4)), '4'));
    test('sin_calificar wins', () => expect(formatPuntajeVigente(_j(3.5, sin: true)), 'sin calificar'));
    test('null puntaje', () => expect(formatPuntajeVigente(_j(null)), 'sin calificar'));
  });

  group('CambiosVigentes.fromJson', () {
    test('parses the envelope and a row', () {
      final r = CambiosVigentes.fromJson({
        'season_id': 7,
        'vigentes': [_row()],
      });
      expect(r.seasonId, 7);
      final v = r.vigentes.single;
      expect(v.teamNombre, 'Los Pibes');
      expect(v.esArco, isTrue);
      expect(v.baja.etiqueta, 'Andres Campos (3,5)');
      expect(v.alta.etiqueta, 'Gerardo Grigorjew (4)');
      expect(v.desdeLabel, 'Apertura Fecha 7');
      expect(v.fechasRestantes, 2);
    });

    test('null puntaje and sin_calificar parse as sin calificar', () {
      final row = _row()..['baja'] = _jugador('Campos, Andres', sin: true);
      final v = CambiosVigente.fromJson(row);
      expect(v.baja.puntaje, isNull);
      expect(v.baja.etiqueta, 'Andres Campos (sin calificar)');
      expect(v.fechasRestantes, 2);
    });

    test('a corrupt row is skipped and the other rows survive', () {
      final roto = _row()..remove('plaza_id');
      final r = CambiosVigentes.fromJson({
        'season_id': 7,
        'vigentes': [roto, 'no es un mapa', _row()..['team_nombre'] = 'Sigue'],
      });
      expect(r.vigentes.map((v) => v.teamNombre), ['Sigue']);
    });

    test('missing list throws FormatException', () {
      expect(() => CambiosVigentes.fromJson({'season_id': 1}), throwsFormatException);
    });
  });
}

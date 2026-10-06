import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/cambios_solicitud.dart';

void main() {
  group('CambiosSolicitudLado.fromJson', () {
    test('parses player_id, nombre and puntaje', () {
      final lado = CambiosSolicitudLado.fromJson({
        'player_id': 777,
        'nombre': 'Campos, Andres',
        'puntaje': 4.5,
      });

      expect(lado.playerId, 777);
      expect(lado.nombre, 'Campos, Andres');
      expect(lado.puntaje, 4.5);
    });

    test('an integer puntaje (e.g. 5) parses as a double, never fabricating a decimal', () {
      final lado = CambiosSolicitudLado.fromJson({
        'player_id': 777,
        'nombre': 'Campos, Andres',
        'puntaje': 5,
      });

      expect(lado.puntaje, 5.0);
    });

    test('a null puntaje parses to null, never a fabricated value', () {
      final lado = CambiosSolicitudLado.fromJson({
        'player_id': 777,
        'nombre': 'Campos, Andres',
        'puntaje': null,
      });

      expect(lado.puntaje, isNull);
    });

    test('an entirely null payload (not recorded) degrades to noRegistrado — every field null', () {
      final lado = CambiosSolicitudLado.fromJson(null);

      expect(lado, CambiosSolicitudLado.noRegistrado);
      expect(lado.playerId, isNull);
      expect(lado.nombre, isNull);
      expect(lado.puntaje, isNull);
    });

    test('a non-map payload (defensive parsing) degrades to noRegistrado, never throws', () {
      final lado = CambiosSolicitudLado.fromJson('not a map');

      expect(lado, CambiosSolicitudLado.noRegistrado);
    });

    test('a non-numeric puntaje (defensive parsing) degrades to null, never throws', () {
      final lado = CambiosSolicitudLado.fromJson({
        'player_id': 777,
        'nombre': 'Campos, Andres',
        'puntaje': 'not a number',
      });

      expect(lado.puntaje, isNull);
    });
  });

  group('CambiosSolicitud.fromJson — sale/entra', () {
    Map<String, dynamic> buildJson({Object? sale, Object? entra}) => {
          'id': 7,
          'plaza_id': 1,
          'tipo': 'sustitucion',
          'entrante_player_id': 200,
          'fecha_id': 5,
          'estado': 'pendiente',
          'solicitada_at': '2026-03-01 10:00:00',
          'dictamen': {'procede': true, 'motivos': []},
          'sale': sale,
          'entra': entra,
        };

    test('parses both sides from a full response', () {
      final solicitud = CambiosSolicitud.fromJson(buildJson(
        sale: {'player_id': 777, 'nombre': 'Campos, Andres', 'puntaje': 4.5},
        entra: {'player_id': 200, 'nombre': 'Grigorjew, Gerardo', 'puntaje': 4.5},
      ));

      expect(solicitud.sale.playerId, 777);
      expect(solicitud.sale.nombre, 'Campos, Andres');
      expect(solicitud.entra.playerId, 200);
      expect(solicitud.entra.nombre, 'Grigorjew, Gerardo');
    });

    test('a response that omits sale/entra entirely (an older payload shape) degrades '
        'to noRegistrado for both, never throws', () {
      final json = buildJson()
        ..remove('sale')
        ..remove('entra');
      final solicitud = CambiosSolicitud.fromJson(json);

      expect(solicitud.sale, CambiosSolicitudLado.noRegistrado);
      expect(solicitud.entra, CambiosSolicitudLado.noRegistrado);
    });
  });
}

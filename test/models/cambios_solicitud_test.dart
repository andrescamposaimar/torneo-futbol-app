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

  group('CambiosSolicitudTipo.reasignacionArquero', () {
    test('fromWire parses the wire string, toWire round-trips it, and label is Spanish', () {
      final tipo = CambiosSolicitudTipo.fromWire('reasignacion_arquero');

      expect(tipo, CambiosSolicitudTipo.reasignacionArquero);
      expect(tipo.toWire(), 'reasignacion_arquero');
      expect(tipo.label, 'Reasignación de arquero');
    });

    test('an unrecognized tipo still degrades to desconocido, never throws', () {
      expect(CambiosSolicitudTipo.fromWire('algo_nuevo'), CambiosSolicitudTipo.desconocido);
    });
  });

  group('CambiosSolicitudMovimientos.fromJson', () {
    test('parses both arco and campo, each shaped like sale/entra', () {
      final movimientos = CambiosSolicitudMovimientos.fromJson({
        'arco': {
          'sale': {'player_id': 1, 'nombre': 'Arquero Saliente', 'puntaje': 3.0},
          'entra': {'player_id': 2, 'nombre': 'Titular de Campo', 'puntaje': 4.5},
        },
        'campo': {
          'sale': {'player_id': 2, 'nombre': 'Titular de Campo', 'puntaje': 4.5},
          'entra': {'player_id': 3, 'nombre': 'Candidato Externo', 'puntaje': 2.5},
        },
      });

      expect(movimientos.isEmpty, isFalse);
      expect(movimientos.arco!.sale.playerId, 1);
      expect(movimientos.arco!.entra.playerId, 2);
      expect(movimientos.campo!.sale.playerId, 2);
      expect(movimientos.campo!.entra.playerId, 3);
    });

    test('a null payload (ordinary sustitucion/regreso rows) degrades to isEmpty, never throws', () {
      final movimientos = CambiosSolicitudMovimientos.fromJson(null);

      expect(movimientos.isEmpty, isTrue);
      expect(movimientos.arco, isNull);
      expect(movimientos.campo, isNull);
    });

    test('a non-map payload (defensive parsing) degrades to isEmpty, never throws', () {
      expect(CambiosSolicitudMovimientos.fromJson('not a map').isEmpty, isTrue);
    });

    test('a partially-degraded payload (only one movement present) keeps that movement, '
        'leaving the other null rather than fabricating it', () {
      final movimientos = CambiosSolicitudMovimientos.fromJson({
        'arco': {
          'sale': {'player_id': 1, 'nombre': 'Arquero Saliente', 'puntaje': 3.0},
          'entra': {'player_id': 2, 'nombre': 'Titular de Campo', 'puntaje': 4.5},
        },
      });

      expect(movimientos.isEmpty, isFalse);
      expect(movimientos.arco, isNotNull);
      expect(movimientos.campo, isNull);
    });
  });

  group('CambiosSolicitud.fromJson — movimientos', () {
    test('a reasignacion_arquero row carries its movimientos pair', () {
      final solicitud = CambiosSolicitud.fromJson({
        'id': 9,
        'plaza_id': 30,
        'tipo': 'reasignacion_arquero',
        'fecha_id': 5,
        'estado': 'pendiente',
        'dictamen': {'procede': true, 'motivos': []},
        'sale': null,
        'entra': null,
        'movimientos': {
          'arco': {
            'sale': {'player_id': 1, 'nombre': 'Arquero Saliente', 'puntaje': 3.0},
            'entra': {'player_id': 2, 'nombre': 'Titular de Campo', 'puntaje': 4.5},
          },
          'campo': {
            'sale': {'player_id': 2, 'nombre': 'Titular de Campo', 'puntaje': 4.5},
            'entra': {'player_id': 3, 'nombre': 'Candidato Externo', 'puntaje': 2.5},
          },
        },
      });

      expect(solicitud.tipo, CambiosSolicitudTipo.reasignacionArquero);
      expect(solicitud.sale, CambiosSolicitudLado.noRegistrado);
      expect(solicitud.entra, CambiosSolicitudLado.noRegistrado);
      expect(solicitud.movimientos.isEmpty, isFalse);
      expect(solicitud.movimientos.arco!.entra.nombre, 'Titular de Campo');
      expect(solicitud.movimientos.campo!.entra.nombre, 'Candidato Externo');
    });

    test('an ordinary sustitucion row with no movimientos key degrades to isEmpty, never throws', () {
      final solicitud = CambiosSolicitud.fromJson({
        'id': 7,
        'plaza_id': 1,
        'tipo': 'sustitucion',
        'fecha_id': 5,
        'estado': 'pendiente',
        'dictamen': {'procede': true, 'motivos': []},
      });

      expect(solicitud.movimientos.isEmpty, isTrue);
    });
  });
}

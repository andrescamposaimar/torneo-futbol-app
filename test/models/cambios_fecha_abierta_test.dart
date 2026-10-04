import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/cambios_fecha_abierta.dart';

void main() {
  group('CambiosVentanaFase.fromWire', () {
    test('parses every valid wire value', () {
      expect(CambiosVentanaFase.fromWire('antes'), CambiosVentanaFase.antes);
      expect(CambiosVentanaFase.fromWire('abierta'), CambiosVentanaFase.abierta);
      expect(CambiosVentanaFase.fromWire('cerrada'), CambiosVentanaFase.cerrada);
    });

    test('falls back to cerrada on an unknown or missing value — the safest '
        'default, since it keeps submission disabled rather than wrongly '
        'enabling it', () {
      expect(CambiosVentanaFase.fromWire('algo_inesperado'), CambiosVentanaFase.cerrada);
      expect(CambiosVentanaFase.fromWire(null), CambiosVentanaFase.cerrada);
      expect(CambiosVentanaFase.fromWire(true), CambiosVentanaFase.cerrada);
    });
  });

  group('CambiosFechaAbierta.fromJson', () {
    Map<String, dynamic> buildJson({
      Object? ventanas = const {'regreso': 'cerrada', 'sustitucion': 'abierta'},
      Object? plazosUtc = const {'apertura_solicitudes': '2026-01-04 03:00:00'},
    }) =>
        {
          'fecha_id': 42,
          'numero_en_torneo': 3,
          'torneo': 'Apertura',
          'play_date': '2026-01-10',
          'plazos_utc': plazosUtc,
          'ventanas': ventanas,
        };

    test('parses fecha fields, both window phases, and apertura_solicitudes as UTC', () {
      final fecha = CambiosFechaAbierta.fromJson(buildJson());

      expect(fecha.fechaId, 42);
      expect(fecha.numeroEnTorneo, 3);
      expect(fecha.torneo, 'Apertura');
      expect(fecha.playDate, '2026-01-10');
      expect(fecha.regresoFase, CambiosVentanaFase.cerrada);
      expect(fecha.sustitucionFase, CambiosVentanaFase.abierta);
      expect(fecha.aperturaSolicitudesUtc, DateTime.utc(2026, 1, 4, 3, 0, 0));
      expect(fecha.aperturaSolicitudesUtc!.isUtc, isTrue);
    });

    test('THE regression case this fix is about: a window in the antes phase '
        'is parsed as antes, never silently treated as closed', () {
      final fecha = CambiosFechaAbierta.fromJson(
        buildJson(ventanas: const {'regreso': 'antes', 'sustitucion': 'antes'}),
      );

      expect(fecha.regresoFase, CambiosVentanaFase.antes);
      expect(fecha.sustitucionFase, CambiosVentanaFase.antes);
      expect(fecha.ventanaAbiertaPara(esSustitucion: true), isFalse);
      expect(fecha.faseFor(esSustitucion: true), CambiosVentanaFase.antes);
    });

    test('a missing ventanas object parses both windows as cerrada, never throws', () {
      final fecha = CambiosFechaAbierta.fromJson(buildJson(ventanas: null));

      expect(fecha.regresoFase, CambiosVentanaFase.cerrada);
      expect(fecha.sustitucionFase, CambiosVentanaFase.cerrada);
    });

    test('a missing or malformed plazos_utc parses aperturaSolicitudesUtc as null, never throws', () {
      expect(CambiosFechaAbierta.fromJson(buildJson(plazosUtc: null)).aperturaSolicitudesUtc, isNull);
      expect(
        CambiosFechaAbierta.fromJson(
          buildJson(plazosUtc: const {'apertura_solicitudes': 'not-a-date'}),
        ).aperturaSolicitudesUtc,
        isNull,
      );
    });

    test('ventanaAbiertaPara and faseFor route to the window matching esSustitucion', () {
      final fecha = CambiosFechaAbierta.fromJson(buildJson());

      expect(fecha.ventanaAbiertaPara(esSustitucion: false), isFalse); // regreso: cerrada
      expect(fecha.ventanaAbiertaPara(esSustitucion: true), isTrue); // sustitucion: abierta
      expect(fecha.faseFor(esSustitucion: false), CambiosVentanaFase.cerrada);
      expect(fecha.faseFor(esSustitucion: true), CambiosVentanaFase.abierta);
    });
  });
}

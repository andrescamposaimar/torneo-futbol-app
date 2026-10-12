import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/cambios_plaza.dart';

Map<String, dynamic> _json({Object? esArco}) => {
      'plaza_id': 1,
      'titular_player_id': 100,
      'titular_nombre': 'Juan Pérez',
      'ocupante_player_id': 100,
      'ocupante_nombre': 'Juan Pérez',
      'es_titular_el_ocupante': true,
      'cerrada': false,
      if (esArco != null) 'es_arco': esArco,
    };

void main() {
  group('CambiosPlaza.fromJson — es_arco', () {
    /// `es_arco` is the STORED `cambios_plaza.es_arco` column (see that
    /// field's own docblock) — this parse is the ONLY place the app ever
    /// reads it. Never re-derive "is this the goal plaza?" from anything
    /// else (a titular's position name, a player id, etc.) anywhere this
    /// value is consumed.
    test('parses true', () {
      expect(CambiosPlaza.fromJson(_json(esArco: true)).esArco, isTrue);
    });

    test('parses false', () {
      expect(CambiosPlaza.fromJson(_json(esArco: false)).esArco, isFalse);
    });

    test('a missing key degrades to false, never to "this is the goal plaza"', () {
      expect(CambiosPlaza.fromJson(_json()).esArco, isFalse);
    });

    test('a malformed (non-boolean) value degrades to false, never throws', () {
      expect(CambiosPlaza.fromJson(_json(esArco: 'yes')).esArco, isFalse);
      expect(CambiosPlaza.fromJson(_json(esArco: 1)).esArco, isFalse);
      expect(CambiosPlaza.fromJson(_json(esArco: null)).esArco, isFalse);
    });
  });

  group('solicitud_abierta', () {
    test('parses id and estado when the backend reports an open request', () {
      final plaza = CambiosPlaza.fromJson({
        'plaza_id': 1,
        'solicitud_abierta': {'id': 42, 'estado': 'aprobada'},
      });

      expect(plaza.solicitudAbierta, const CambiosSolicitudAbierta(id: 42, estado: 'aprobada'));
    });

    test('absent key means no open request', () {
      expect(CambiosPlaza.fromJson({'plaza_id': 1}).solicitudAbierta, isNull);
    });

    test('explicit null means no open request', () {
      expect(
        CambiosPlaza.fromJson({'plaza_id': 1, 'solicitud_abierta': null}).solicitudAbierta,
        isNull,
      );
    });

    test('malformed payloads degrade to null instead of throwing', () {
      // This field is a courtesy, not the guard — see CambiosPlaza's own
      // docblock. Degrading to "no open request" shows the button again and
      // the server still refuses the request; throwing here would take down
      // the whole roster screen over a field it can live without.
      for (final bad in <Object?>[
        'pendiente',
        42,
        <String, dynamic>{},
        {'estado': 'pendiente'},
        {'id': 0, 'estado': 'pendiente'},
        {'id': '42', 'estado': 'pendiente'},
      ]) {
        expect(
          CambiosPlaza.fromJson({'plaza_id': 1, 'solicitud_abierta': bad}).solicitudAbierta,
          isNull,
          reason: 'payload $bad must not be read as an open request',
        );
      }
    });

    test('a missing estado still counts as an open request', () {
      // Presence is what gates the buttons; the estado is only carried for
      // whoever needs the distinction later.
      final plaza = CambiosPlaza.fromJson({
        'plaza_id': 1,
        'solicitud_abierta': {'id': 42},
      });

      expect(plaza.solicitudAbierta?.id, 42);
      expect(plaza.solicitudAbierta?.estado, '');
    });

    test('two plazas differing only in solicitudAbierta are not equal', () {
      const abierta = CambiosSolicitudAbierta(id: 1, estado: 'pendiente');
      const a = CambiosPlaza(
        plazaId: 1,
        titularPlayerId: 1,
        titularNombre: 'X',
        esTitularElOcupante: true,
        cerrada: false,
      );
      const b = CambiosPlaza(
        plazaId: 1,
        titularPlayerId: 1,
        titularNombre: 'X',
        esTitularElOcupante: true,
        cerrada: false,
        solicitudAbierta: abierta,
      );

      expect(a, isNot(b));
      expect(a.hashCode, isNot(b.hashCode));
    });
  });
}

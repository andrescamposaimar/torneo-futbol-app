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
}

import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/cambios_candidato.dart';

void main() {
  group('CambiosCandidato.fromJson — posicion', () {
    Map<String, dynamic> buildJson({Object? posicion = 'Arquero'}) => {
          'player_id': 800,
          'nombre': 'Juan Pérez',
          'es_padre': true,
          'puntaje': 4.5,
          'viable': true,
          'posicion': posicion,
        };

    test('parses a string posicion from the backend', () {
      final candidato = CambiosCandidato.fromJson(buildJson(posicion: 'Mediocampista'));

      expect(candidato.posicion, 'Mediocampista');
    });

    test('a missing posicion key defaults to an empty string, never throws', () {
      final json = buildJson()..remove('posicion');
      final candidato = CambiosCandidato.fromJson(json);

      expect(candidato.posicion, '');
    });

    test('a null posicion defaults to an empty string', () {
      final candidato = CambiosCandidato.fromJson(buildJson(posicion: null));

      expect(candidato.posicion, '');
    });

    test('a non-string posicion (defensive parsing) defaults to an empty string, never throws', () {
      final candidato = CambiosCandidato.fromJson(buildJson(posicion: 123));

      expect(candidato.posicion, '');
    });
  });

  group('CambiosCandidato equality — posicion is part of the value', () {
    test('two candidatos that differ only by posicion are not equal', () {
      const a = CambiosCandidato(
        playerId: 1,
        nombre: 'Juan',
        esPadre: false,
        viable: true,
        posicion: 'Arquero',
      );
      const b = CambiosCandidato(
        playerId: 1,
        nombre: 'Juan',
        esPadre: false,
        viable: true,
        posicion: 'Defensor',
      );

      expect(a == b, isFalse);
    });

    test('two otherwise-identical candidatos with the same posicion are equal', () {
      const a = CambiosCandidato(
        playerId: 1,
        nombre: 'Juan',
        esPadre: false,
        viable: true,
        posicion: 'Arquero',
      );
      const b = CambiosCandidato(
        playerId: 1,
        nombre: 'Juan',
        esPadre: false,
        viable: true,
        posicion: 'Arquero',
      );

      expect(a == b, isTrue);
      expect(a.hashCode, b.hashCode);
    });
  });
}

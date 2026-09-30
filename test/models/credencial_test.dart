import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/credencial.dart';

Map<String, dynamic> _activeWireJson({
  Map<String, dynamic>? teamOverride,
  String? birthDate = '1990-05-01 00:00:00',
  String? caracter = 'Padre Alumno',
}) {
  return {
    'state': 'active',
    'photo_request': null,
    'credential': {
      'id': 'cred-uuid-1',
      'issued_at': '2026-09-29 12:00:00',
      'expires_at': '2027-09-29 12:00:00',
      'player_id': 42,
      'full_name': 'Juan Perez',
      'dni': '30111222',
      'birth_date': birthDate,
      'caracter': caracter,
      'team': teamOverride ??
          {'id': 7, 'name': 'Lista de Espera 2026', 'kind': 'waiting_list'},
      'photo': {'url': 'https://example.com/photo.jpg', 'sha256': 'abc123'},
      'code_seed': 'c2hhcmVkLXRlc3QtdmVjdG9yLXNlZWQtMDAx',
      'code': {'alg': 'SHA256', 'step': 30, 'digits': 6},
    },
  };
}

void main() {
  group('CredencialResponse', () {
    test('parses a full active payload and round-trips through toJson', () {
      final wire = _activeWireJson();
      final response = CredencialResponse.fromJson(wire);

      expect(response.state, CredencialCardState.active);
      expect(response.photoRequest, isNull);
      expect(response.credential!.id, 'cred-uuid-1');
      expect(response.credential!.team!.kind, CredencialTeamKind.waitingList);
      expect(response.credential!.photo.sha256, 'abc123');
      expect(response.credential!.code.digits, 6);

      final roundTripped = CredencialResponse.fromJson(response.toJson());
      expect(roundTripped, equals(response));
    });

    test('parses null team, caracter, and birth_date without failing', () {
      final wire = _activeWireJson(
        teamOverride: null,
        birthDate: null,
        caracter: null,
      )..['credential']['team'] = null;

      final response = CredencialResponse.fromJson(wire);

      expect(response.credential!.team, isNull);
      expect(response.credential!.caracter, isNull);
      expect(response.credential!.birthDate, isNull);
    });

    test('parses blocked/no_photo/not_a_player states with null credential',
        () {
      for (final wireState in ['blocked', 'no_photo', 'not_a_player']) {
        final response = CredencialResponse.fromJson({
          'state': wireState,
          'photo_request': null,
          'credential': null,
        });
        expect(response.credential, isNull);
      }
    });

    test('parses a pending photo_request', () {
      final response = CredencialResponse.fromJson({
        'state': 'no_photo',
        'photo_request': {
          'id': 5,
          'status': 'pending',
          'created_at': '2026-09-29 10:00:00',
        },
        'credential': null,
      });

      expect(response.photoRequest!.id, 5);
      expect(response.photoRequest!.status, PhotoRequestStatus.pending);
    });

    test('rejects an unknown state string', () {
      expect(
        () => CredencialResponse.fromJson({
          'state': 'made_up',
          'photo_request': null,
          'credential': null,
        }),
        throwsFormatException,
      );
    });
  });
}

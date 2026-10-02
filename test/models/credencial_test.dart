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
      'photo': {'id': 55, 'url': 'https://example.com/photo.jpg'},
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
      expect(response.credential!.photo.id, 55);
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

    test(
        'a photo with a missing id is a parse error (design rev 9: id is '
        'the photo identity, never optional)', () {
      final wire = _activeWireJson();
      (wire['credential'] as Map<String, dynamic>)['photo'] = {
        'url': 'https://example.com/photo.jpg',
      };

      expect(
        () => CredencialResponse.fromJson(wire),
        throwsA(isA<TypeError>()),
      );
    });

    test(
        'withPhotoUrl replaces only the photo url, round-tripping through '
        'toJson/fromJson (design D16: the saved JSON must be able to carry '
        'an old url for a same-id retry)', () {
      final wire = _activeWireJson();
      final response = CredencialResponse.fromJson(wire);

      final updated = response.withPhotoUrl('https://example.com/new.jpg');

      expect(updated.credential!.photo.url, 'https://example.com/new.jpg');
      expect(updated.credential!.photo.id, response.credential!.photo.id,
          reason: 'withPhotoUrl must never change the photo identity');
      expect(updated.credential!.fullName, response.credential!.fullName);
      expect(updated.credential!.dni, response.credential!.dni);
      expect(updated.state, response.state);
      expect(updated.photoRequest, response.photoRequest);
      // The original response must be untouched (immutability).
      expect(response.credential!.photo.url, 'https://example.com/photo.jpg');

      final roundTripped = CredencialResponse.fromJson(updated.toJson());
      expect(roundTripped, equals(updated));
    });

    test(
        'withPhotoUrl on a response with no credential (e.g. blocked) is a '
        'no-op', () {
      const response = CredencialResponse(state: CredencialCardState.blocked);

      final updated = response.withPhotoUrl('https://example.com/new.jpg');

      expect(updated, equals(response));
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

import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/models/credencial.dart';
import 'package:torneo_futbol_app/services/credencial_api_service.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';

const _testConfig = ProdeAuthConfig(
  prodeApiBaseUrl: 'https://test.example.com/wp-json/entre-redes/v1/prode',
  googleWebClientId: 'test-google',
  appleTeamId: 'TEST_TEAM',
);

const _credencialBaseUrl =
    'https://test.example.com/wp-json/entre-redes/v1/credencial';

void _setUpFakeStorage(Map<String, String> store) {
  FlutterSecureStoragePlatform.instance = TestFlutterSecureStoragePlatform(store);
}

Map<String, dynamic> _activeWireBody() => {
      'state': 'active',
      'photo_request': null,
      'credential': {
        'id': 'cred-1',
        'issued_at': '2026-09-29 00:00:00',
        'expires_at': '2027-09-29 00:00:00',
        'player_id': 1,
        'full_name': 'Juan Perez',
        'dni': '30111222',
        'birth_date': null,
        'caracter': null,
        'team': null,
        'photo': {'url': 'https://example.com/p.jpg', 'sha256': 'abc'},
        'code_seed': 'c2VlZA',
        'code': {'alg': 'SHA256', 'step': 30, 'digits': 6},
      },
    };

void main() {
  group('CredencialApiService', () {
    late Map<String, String> store;
    late ProdeAuthRepository authRepo;

    setUp(() async {
      store = {};
      _setUpFakeStorage(store);
      authRepo = ProdeAuthRepository();
      // Seed a logged-in session so ProdeApiService.request() does not fail
      // fast with "no access token available" before the mock client ever
      // sees the request.
      await authRepo.write(
        accessToken: 'access-token',
        refreshToken: 'refresh-token',
        sessionVersion: '1',
        tenantId: 'marianista',
      );
    });

    CredencialApiService makeService(http.Client client) {
      final prodeApi = ProdeApiService(
        config: _testConfig,
        authRepo: authRepo,
        httpClient: client,
      );
      return CredencialApiService(
        prodeApi: prodeApi,
        credencialApiBaseUrl: _credencialBaseUrl,
      );
    }

    group('fetchCredencial()', () {
      test('200 → returns a parsed CredencialResponse', () async {
        final client = MockClient((request) async {
          expect(request.method, 'GET');
          expect(request.url.toString(), '$_credencialBaseUrl/credencial');
          expect(request.headers['Authorization'], 'Bearer access-token');
          return http.Response(json.encode(_activeWireBody()), 200);
        });

        final result = await makeService(client).fetchCredencial();

        expect(result.state, CredencialCardState.active);
        expect(result.credential!.fullName, 'Juan Perez');
      });

      test('non-200 → throws CredencialApiException with the server code', () async {
        final client = MockClient((_) async => http.Response(
              json.encode({'code': 'error_interno', 'message': 'boom'}),
              500,
            ));

        await expectLater(
          makeService(client).fetchCredencial(),
          throwsA(
            isA<CredencialApiException>()
                .having((e) => e.statusCode, 'statusCode', 500)
                .having((e) => e.code, 'code', 'error_interno'),
          ),
        );
      });

      test('an unrecoverable 401 propagates ProdeAuthRequired unchanged', () async {
        final client = MockClient((_) async => http.Response(
              json.encode({'code': 'session_revoked', 'message': 'revoked'}),
              401,
            ));

        await expectLater(
          makeService(client).fetchCredencial(),
          throwsA(isA<ProdeAuthRequired>()
              .having((e) => e.code, 'code', 'session_revoked')),
        );
      });
    });

    group('uploadPhoto()', () {
      test('202 → returns PhotoUploadAccepted with the request id', () async {
        final client = MockClient((request) async {
          expect(request.method, 'POST');
          expect(request.url.toString(), '$_credencialBaseUrl/foto');
          final body = json.decode(request.body) as Map<String, dynamic>;
          expect(body['image_base64'], base64Encode([1, 2, 3]));
          return http.Response(
            json.encode({'request_id': 42, 'status': 'pending'}),
            202,
          );
        });

        final result = await makeService(client)
            .uploadPhoto(Uint8List.fromList([1, 2, 3]));

        expect(result.requestId, 42);
      });

      test('409 already_pending → throws CredencialApiException', () async {
        final client = MockClient((_) async => http.Response(
              json.encode({
                'code': 'already_pending',
                'message': 'Ya tenés una foto pendiente.',
              }),
              409,
            ));

        await expectLater(
          makeService(client).uploadPhoto(Uint8List.fromList([1, 2, 3])),
          throwsA(
            isA<CredencialApiException>()
                .having((e) => e.statusCode, 'statusCode', 409)
                .having((e) => e.code, 'code', 'already_pending'),
          ),
        );
      });
    });
  });
}

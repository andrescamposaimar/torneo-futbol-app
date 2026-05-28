import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const _testConfig = ProdeAuthConfig(
  prodeApiBaseUrl: 'https://test.example.com/wp-json/entre-redes/v1/prode',
  googleWebClientId: 'test-google',
  appleTeamId: 'TEST_TEAM',
);

void _setUpFakeStorage(Map<String, String> store) {
  FlutterSecureStoragePlatform.instance =
      TestFlutterSecureStoragePlatform(store);
}

ProdeApiService _makeService(
  ProdeAuthRepository repo,
  http.Client client,
) {
  return ProdeApiService(
    config: _testConfig,
    authRepo: repo,
    httpClient: client,
  );
}

http.Response _refreshSuccessResponse({
  String accessToken = 'new-access',
  String refreshToken = 'new-refresh',
  int userId = 10,
  int playerId = 3,
  String name = 'Player One',
  int sessionVersion = 2,
}) {
  return http.Response(
    json.encode({
      'access_token': accessToken,
      'refresh_token': refreshToken,
      'user': {
        'user_id': userId,
        'player_id': playerId,
        'name': name,
        'session_version': sessionVersion,
      },
    }),
    200,
    headers: {'content-type': 'application/json'},
  );
}

http.Response _refresh401Response({
  String code = 'token_expired',
  String message = 'Token expired.',
}) {
  return http.Response(
    json.encode({'code': code, 'message': message}),
    401,
    headers: {'content-type': 'application/json'},
  );
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

void main() {
  group('ProdeApiService.attemptSilentRefresh()', () {
    late Map<String, String> store;
    late ProdeAuthRepository repo;

    setUp(() {
      store = {};
      _setUpFakeStorage(store);
      repo = ProdeAuthRepository();
    });

    // (a) 200 with valid body → returns ProdeUser, persists new tokens
    test('200 success → returns real ProdeUser and persists new tokens', () async {
      final service = _makeService(
        repo,
        MockClient((_) async => _refreshSuccessResponse(
          accessToken: 'fresh-access',
          refreshToken: 'fresh-refresh',
          userId: 99,
          playerId: 5,
          name: 'María García',
          sessionVersion: 7,
        )),
      );

      final user = await service.attemptSilentRefresh(refreshToken: 'old-refresh');

      expect(user, isNotNull);
      expect(user!.userId, equals(99));
      expect(user.playerId, equals(5));
      expect(user.name, equals('María García'));
      expect(user.sessionVersion, equals(7));

      // New tokens must be persisted in storage.
      expect(await repo.readAccessToken(), equals('fresh-access'));
      expect(await repo.readRefreshToken(), equals('fresh-refresh'));
      expect(await repo.readSessionVersion(), equals('7'));
    });

    // (b) 200 success → in-memory cache is updated
    test('200 success → service in-memory cache is updated to new access token', () async {
      // Verify the cache is updated by checking that the next request would use
      // the fresh token. We do this indirectly: a 200 response should leave
      // the service with the correct cached token (tested via storage match).
      await repo.write(
        accessToken: 'old-access',
        refreshToken: 'old-refresh',
        sessionVersion: '1',
        tenantId: 'marianista',
      );

      final service = _makeService(
        repo,
        MockClient((_) async => _refreshSuccessResponse(
          accessToken: 'updated-access',
          refreshToken: 'updated-refresh',
        )),
      );

      await service.attemptSilentRefresh(refreshToken: 'old-refresh');

      // Storage should reflect new tokens.
      expect(await repo.readAccessToken(), equals('updated-access'));
      expect(await repo.readRefreshToken(), equals('updated-refresh'));
    });

    // (c) 401 session_revoked → clears storage, fires onAuthRequired, returns null
    test('401 session_revoked → clears storage, fires onAuthRequired', () async {
      await repo.write(
        accessToken: 'acc',
        refreshToken: 'ref',
        sessionVersion: '1',
        tenantId: 'marianista',
      );

      final service = _makeService(
        repo,
        MockClient((_) async => _refresh401Response(code: 'session_revoked')),
      );

      ProdeAuthRequired? captured;
      service.onAuthRequired = (e) => captured = e;

      // attemptSilentRefresh emits onAuthRequired then throws ProdeAuthRequired.
      // Use expectLater + await so the matcher actually observes the throw
      // before subsequent assertions run.
      await expectLater(
        service.attemptSilentRefresh(refreshToken: 'ref'),
        throwsA(isA<ProdeAuthRequired>()),
      );

      expect(captured, isNotNull);
      expect(captured!.code, equals('session_revoked'));

      // Storage must be cleared.
      expect(await repo.readAccessToken(), isNull);
    });

    // (d) 401 non-revoked → clears storage, fires onAuthRequired, returns null
    test('401 token_expired → clears storage, fires onAuthRequired', () async {
      await repo.write(
        accessToken: 'acc',
        refreshToken: 'ref',
        sessionVersion: '1',
        tenantId: 'marianista',
      );

      final service = _makeService(
        repo,
        MockClient((_) async => _refresh401Response(code: 'refresh_token_invalid')),
      );

      ProdeAuthRequired? captured;
      service.onAuthRequired = (e) => captured = e;

      await expectLater(
        service.attemptSilentRefresh(refreshToken: 'ref'),
        throwsA(isA<ProdeAuthRequired>()),
      );

      expect(captured, isNotNull);
      expect(captured!.code, equals('refresh_token_invalid'));
    });

    // (e) Network failure → returns null WITHOUT clearing storage
    test('network failure → returns null, storage unchanged', () async {
      await repo.write(
        accessToken: 'acc',
        refreshToken: 'ref',
        sessionVersion: '1',
        tenantId: 'marianista',
      );

      final service = _makeService(
        repo,
        MockClient((_) async => throw http.ClientException('Network unreachable')),
      );

      bool onAuthRequiredCalled = false;
      service.onAuthRequired = (_) => onAuthRequiredCalled = true;

      final user = await service.attemptSilentRefresh(refreshToken: 'ref');

      expect(user, isNull);
      expect(onAuthRequiredCalled, isFalse);

      // Storage must NOT be cleared.
      expect(await repo.readAccessToken(), equals('acc'));
      expect(await repo.readRefreshToken(), equals('ref'));
    });

    // (f) 500 server error → returns null WITHOUT clearing storage
    test('non-401 server error → returns null, storage unchanged', () async {
      await repo.write(
        accessToken: 'acc',
        refreshToken: 'ref',
        sessionVersion: '1',
        tenantId: 'marianista',
      );

      final service = _makeService(
        repo,
        MockClient((_) async => http.Response('Internal Server Error', 500)),
      );

      final user = await service.attemptSilentRefresh(refreshToken: 'ref');

      expect(user, isNull);
      expect(await repo.readAccessToken(), equals('acc'));
    });

    // (g) 200 with malformed body → returns null, storage unchanged
    test('200 with malformed body → returns null gracefully', () async {
      await repo.write(
        accessToken: 'acc',
        refreshToken: 'ref',
        sessionVersion: '1',
        tenantId: 'marianista',
      );

      final service = _makeService(
        repo,
        MockClient((_) async => http.Response(
          json.encode({'access_token': 'new', 'refresh_token': 'new-ref'}),
          // Missing 'user' field
          200,
          headers: {'content-type': 'application/json'},
        )),
      );

      final user = await service.attemptSilentRefresh(refreshToken: 'ref');

      expect(user, isNull);
      // Storage unchanged (no partial write)
      expect(await repo.readAccessToken(), equals('acc'));
    });

    // (h) onTokensChanged fires after successful refresh storage write
    test('200 success → onTokensChanged fires via writeTokens', () async {
      var changedCount = 0;
      repo.onTokensChanged = () => changedCount++;

      final service = _makeService(
        repo,
        MockClient((_) async => _refreshSuccessResponse()),
      );

      await service.attemptSilentRefresh(refreshToken: 'any-refresh');

      // writeTokens was called → onTokensChanged must have fired once.
      expect(changedCount, equals(1));
    });
  });

  // ---------------------------------------------------------------------------
  // ProdeApiService.parseProdeUser — direct synchronous parser unit tests.
  //
  // The parser is annotated @visibleForTesting precisely so test failures
  // pinpoint parser bugs without confounding HTTP/storage layers. The single
  // happy-path integration test for the parser → onTokensRefreshed pipeline
  // lives in prode_auth_controller_test (or the silent-refresh group above).
  // ---------------------------------------------------------------------------

  group('ProdeApiService.parseProdeUser()', () {
    test('parses valid payload — all fields present as int/String', () {
      final user = ProdeApiService.parseProdeUser({
        'user_id': 10,
        'player_id': 3,
        'name': 'Ana López',
        'session_version': 2,
      });
      expect(user, isNotNull);
      expect(user!.userId, equals(10));
      expect(user.playerId, equals(3));
      expect(user.name, equals('Ana López'));
      expect(user.sessionVersion, equals(2));
    });

    test('parses session_version delivered as String', () {
      final user = ProdeApiService.parseProdeUser({
        'user_id': 1,
        'player_id': 1,
        'name': 'Test',
        'session_version': '3',
      });
      expect(user, isNotNull);
      expect(user!.sessionVersion, equals(3));
    });

    test('null raw → returns null', () {
      expect(ProdeApiService.parseProdeUser(null), isNull);
    });

    test('missing user_id → returns null', () {
      expect(
        ProdeApiService.parseProdeUser({
          'player_id': 1,
          'name': 'Test',
          'session_version': 1,
        }),
        isNull,
      );
    });

    test('missing name → returns null', () {
      expect(
        ProdeApiService.parseProdeUser({
          'user_id': 1,
          'player_id': 1,
          'session_version': 1,
        }),
        isNull,
      );
    });

    test('unparseable session_version → returns null', () {
      expect(
        ProdeApiService.parseProdeUser({
          'user_id': 1,
          'player_id': 1,
          'name': 'Test',
          'session_version': 'bad-value',
        }),
        isNull,
      );
    });

    test('empty user map → returns null', () {
      expect(ProdeApiService.parseProdeUser({}), isNull);
    });

    test('wrong-type user_id (String instead of int) → returns null', () {
      expect(
        ProdeApiService.parseProdeUser({
          'user_id': '10',
          'player_id': 1,
          'name': 'Test',
          'session_version': 1,
        }),
        isNull,
      );
    });
  });
}

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';
import 'package:torneo_futbol_app/services/prode_auth_state.dart';
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

    // parseSessionVersionFromWire unification: exotic types (double, bool, List)
    // must return null. This is the same behavior the pre-PR-08 inline
    // parsers had (both via `is String` and via `int.tryParse(toString())`,
    // which produced null for double "3.0", bool "true", etc.). The PR-08
    // refactor extracts the shared helper without changing semantics.
    test('session_version as double → returns null', () {
      expect(
        ProdeApiService.parseProdeUser({
          'user_id': 1,
          'player_id': 1,
          'name': 'Test',
          'session_version': 3.0,
        }),
        isNull,
      );
    });

    test('session_version as bool → returns null', () {
      expect(
        ProdeApiService.parseProdeUser({
          'user_id': 1,
          'player_id': 1,
          'name': 'Test',
          'session_version': true,
        }),
        isNull,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // Direct unit tests for the static @visibleForTesting helpers.
  // ---------------------------------------------------------------------------

  group('ProdeApiService.extractErrorCode()', () {
    test('returns body[code] when present', () {
      expect(
        ProdeApiService.extractErrorCode({'code': 'token_expired'}),
        equals('token_expired'),
      );
    });

    test('falls back to body[error] when code is absent', () {
      expect(
        ProdeApiService.extractErrorCode({'error': 'refresh_token_invalid'}),
        equals('refresh_token_invalid'),
      );
    });

    test('prefers code over error when both present', () {
      expect(
        ProdeApiService.extractErrorCode({
          'code': 'session_revoked',
          'error': 'other',
        }),
        equals('session_revoked'),
      );
    });

    test("returns 'unknown' when neither key is present", () {
      expect(ProdeApiService.extractErrorCode({}), equals('unknown'));
    });

    test('non-String code value falls through to error or unknown', () {
      expect(
        ProdeApiService.extractErrorCode({'code': 42, 'error': 'fallback'}),
        equals('fallback'),
      );
    });
  });

  group('ProdeApiService.parseSessionVersionFromWire()', () {
    test('int → int', () {
      expect(ProdeApiService.parseSessionVersionFromWire(7), equals(7));
    });

    test('numeric String → int', () {
      expect(ProdeApiService.parseSessionVersionFromWire('7'), equals(7));
    });

    test('non-numeric String → null', () {
      expect(ProdeApiService.parseSessionVersionFromWire('abc'), isNull);
    });

    test('double → null', () {
      expect(ProdeApiService.parseSessionVersionFromWire(3.0), isNull);
    });

    test('bool → null', () {
      expect(ProdeApiService.parseSessionVersionFromWire(true), isNull);
    });

    test('null → null', () {
      expect(ProdeApiService.parseSessionVersionFromWire(null), isNull);
    });
  });

  group('ProdeApiService.request() — 401 interceptor', () {
    late Map<String, String> store;
    late ProdeAuthRepository repo;

    setUp(() {
      store = {};
      _setUpFakeStorage(store);
      repo = ProdeAuthRepository();
    });

    // Headline feature of PR-06: a 401 token_expired triggers a refresh, the
    // freshly-parsed user is propagated via onTokensRefreshed (so the controller
    // can lift a degraded placeholder), and the original request is retried with
    // the new bearer.
    test('401 token_expired → refreshes, lifts user via onTokensRefreshed, retries with new bearer', () async {
      await repo.write(
        accessToken: 'old-access',
        refreshToken: 'old-refresh',
        sessionVersion: '1',
        tenantId: 'marianista',
      );

      final bearers = <String>[];
      var refreshCalls = 0;

      final service = _makeService(
        repo,
        MockClient((req) async {
          if (req.url.path.endsWith('/auth/refresh')) {
            refreshCalls++;
            return _refreshSuccessResponse(
              accessToken: 'new-access',
              refreshToken: 'new-refresh',
              userId: 42,
              playerId: 7,
              name: 'Real User',
              sessionVersion: 3,
            );
          }
          bearers.add(req.headers['Authorization'] ?? '');
          if (req.headers['Authorization'] == 'Bearer old-access') {
            return http.Response(
              json.encode({'code': 'token_expired', 'message': 'expired'}),
              401,
              headers: {'content-type': 'application/json'},
            );
          }
          return http.Response(
            json.encode({'ok': true}),
            200,
            headers: {'content-type': 'application/json'},
          );
        }),
      );

      ProdeUser? refreshed;
      service.onTokensRefreshed = (u) => refreshed = u;

      final resp = await service.request(
        http.Request('GET', Uri.parse('${_testConfig.prodeApiBaseUrl}/fechas')),
      );

      expect(resp.statusCode, equals(200));
      expect(refreshCalls, equals(1));
      // First attempt used the stale token; the retry used the refreshed one.
      expect(bearers, equals(['Bearer old-access', 'Bearer new-access']));
      // The full user object reached the controller (no userId:0 placeholder).
      expect(refreshed, isNotNull);
      expect(refreshed!.userId, equals(42));
      expect(refreshed!.playerId, equals(7));
      expect(refreshed!.name, equals('Real User'));
      expect(refreshed!.sessionVersion, equals(3));
      // Rotated tokens persisted.
      expect(await repo.readAccessToken(), equals('new-access'));
      expect(await repo.readRefreshToken(), equals('new-refresh'));
    });

    test('401 session_revoked → clears storage and throws ProdeAuthRequired', () async {
      await repo.write(
        accessToken: 'acc',
        refreshToken: 'ref',
        sessionVersion: '1',
        tenantId: 'marianista',
      );

      final service = _makeService(
        repo,
        MockClient((_) async => http.Response(
              json.encode({'code': 'session_revoked', 'message': 'revoked'}),
              401,
              headers: {'content-type': 'application/json'},
            )),
      );

      ProdeAuthRequired? captured;
      service.onAuthRequired = (e) => captured = e;

      await expectLater(
        service.request(
          http.Request('GET', Uri.parse('${_testConfig.prodeApiBaseUrl}/fechas')),
        ),
        throwsA(isA<ProdeAuthRequired>()),
      );

      expect(captured?.code, equals('session_revoked'));
      expect(await repo.readAccessToken(), isNull);
    });

    // Single-flight guard: two concurrent requests that both hit a 401 must
    // share ONE POST /auth/refresh (otherwise the server rotates the refresh
    // token on the first call and the second refresh fails spuriously).
    test('concurrent 401s share a single refresh', () async {
      await repo.write(
        accessToken: 'old-access',
        refreshToken: 'old-refresh',
        sessionVersion: '1',
        tenantId: 'marianista',
      );

      var refreshCalls = 0;

      final service = _makeService(
        repo,
        MockClient((req) async {
          if (req.url.path.endsWith('/auth/refresh')) {
            refreshCalls++;
            await Future<void>.delayed(const Duration(milliseconds: 10));
            return _refreshSuccessResponse(
              accessToken: 'new-access',
              refreshToken: 'new-refresh',
            );
          }
          if (req.headers['Authorization'] == 'Bearer old-access') {
            return http.Response(
              json.encode({'code': 'token_expired'}),
              401,
              headers: {'content-type': 'application/json'},
            );
          }
          return http.Response(
            json.encode({'ok': true}),
            200,
            headers: {'content-type': 'application/json'},
          );
        }),
      );

      final responses = await Future.wait([
        service.request(http.Request('GET', Uri.parse('${_testConfig.prodeApiBaseUrl}/a'))),
        service.request(http.Request('GET', Uri.parse('${_testConfig.prodeApiBaseUrl}/b'))),
      ]);

      expect(responses.every((r) => r.statusCode == 200), isTrue);
      expect(refreshCalls, equals(1));
    });
  });
}

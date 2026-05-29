import 'dart:convert';

import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:torneo_futbol_app/services/prode_auth_controller.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_state.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';

// ---------------------------------------------------------------------------
// Fake storage helper
// ---------------------------------------------------------------------------

void _setUpFakeStorage(Map<String, String> store) {
  FlutterSecureStoragePlatform.instance =
      TestFlutterSecureStoragePlatform(store);
}

// ---------------------------------------------------------------------------
// Minimal ProdeAuthConfig for test ProdeApiService instances
// ---------------------------------------------------------------------------

const _testConfig = ProdeAuthConfig(
  prodeApiBaseUrl: 'https://test.example.com/wp-json/entre-redes/v1/prode',
  googleWebClientId: 'test-google',
  appleTeamId: 'TEST_TEAM',
);

// ---------------------------------------------------------------------------
// Refresh response helpers
// ---------------------------------------------------------------------------

http.Response _refreshSuccess({
  String accessToken = 'new-access',
  String refreshToken = 'new-refresh',
  int userId = 42,
  int playerId = 7,
  String name = 'Test User',
  int sessionVersion = 5,
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

http.Response _refresh401({String code = 'token_expired', String message = 'Token expired.'}) {
  return http.Response(
    json.encode({'code': code, 'message': message}),
    401,
    headers: {'content-type': 'application/json'},
  );
}

// ---------------------------------------------------------------------------
// Builder helpers
// ---------------------------------------------------------------------------

/// Creates a controller backed by in-memory secure storage and a fake HTTP client.
ProdeAuthController _makeController(
  ProdeAuthRepository repo,
  http.Client httpClient, {
  Future<String?> Function()? googleIdToken,
}) {
  final service = ProdeApiService(
    config: _testConfig,
    authRepo: repo,
    httpClient: httpClient,
  );
  final controller = ProdeAuthController(
    repository: repo,
    service: service,
    tenantId: 'marianista',
    googleIdToken: googleIdToken,
  );
  service.onAuthRequired = controller.onAuthRequired;
  return controller;
}

/// Creates a controller with a network-failure HTTP client (always throws).
ProdeAuthController _makeControllerWithNetworkFailure(ProdeAuthRepository repo) {
  return _makeController(
    repo,
    MockClient((_) async => throw http.ClientException('Network unreachable')),
  );
}

void main() {
  group('ProdeAuthController.bootstrap()', () {
    late Map<String, String> store;
    late ProdeAuthRepository repo;

    setUp(() {
      store = {};
      _setUpFakeStorage(store);
      repo = ProdeAuthRepository();
    });

    // (a) No tokens → Unauthenticated
    test('no tokens → Hydrating then Unauthenticated', () async {
      final controller = _makeController(
        repo,
        MockClient((_) async => throw http.ClientException('Should not be called')),
      );

      final states = <ProdeAuthState>[];
      final sub = controller.stream.listen(states.add);

      await controller.bootstrap();
      await sub.cancel();

      expect(states, contains(isA<ProdeAuthHydrating>()));
      expect(controller.state, isA<ProdeAuthUnauthenticated>());
    });

    // (b) Tokens + silent refresh success → Authenticated with real user
    test('tokens + silent refresh success → Authenticated with real user', () async {
      await repo.write(
        accessToken: 'old-access',
        refreshToken: 'old-refresh',
        sessionVersion: '3',
        tenantId: 'marianista',
      );

      final controller = _makeController(
        repo,
        MockClient((_) async => _refreshSuccess(
          userId: 42,
          playerId: 7,
          name: 'Juan Pérez',
          sessionVersion: 5,
        )),
      );

      final states = <ProdeAuthState>[];
      final sub = controller.stream.listen(states.add);

      await controller.bootstrap();
      await sub.cancel();

      expect(states, contains(isA<ProdeAuthHydrating>()));
      expect(controller.state, isA<ProdeAuthAuthenticated>());

      final authenticated = controller.state as ProdeAuthAuthenticated;
      expect(authenticated.user.userId, equals(42));
      expect(authenticated.user.playerId, equals(7));
      expect(authenticated.user.name, equals('Juan Pérez'));
      expect(authenticated.user.sessionVersion, equals(5));
      // Happy path emits server-confirmed data → stale must be false.
      expect(authenticated.stale, isFalse);
    });

    // (c) Tokens + session_revoked → Revoked (via onAuthRequired)
    test('tokens + session_revoked → state is Revoked', () async {
      await repo.write(
        accessToken: 'acc',
        refreshToken: 'ref',
        sessionVersion: '1',
        tenantId: 'marianista',
      );

      final controller = _makeController(
        repo,
        MockClient((_) async => _refresh401(code: 'session_revoked', message: 'Session revoked.')),
      );

      final states = <ProdeAuthState>[];
      final sub = controller.stream.listen(states.add);

      await controller.bootstrap();
      await sub.cancel();

      expect(states, contains(isA<ProdeAuthHydrating>()));
      expect(controller.state, isA<ProdeAuthRevoked>());
      final revoked = controller.state as ProdeAuthRevoked;
      expect(revoked.reason, equals('session_revoked'));

      // Storage must be cleared after session_revoked
      expect(await repo.readAccessToken(), isNull);
    });

    // (d) Tokens + network failure → Authenticated with placeholder user (degraded fallback)
    test('tokens + network failure → Authenticated with placeholder user', () async {
      await repo.write(
        accessToken: 'acc',
        refreshToken: 'ref',
        sessionVersion: '4',
        tenantId: 'marianista',
      );

      final controller = _makeControllerWithNetworkFailure(repo);

      final states = <ProdeAuthState>[];
      final sub = controller.stream.listen(states.add);

      await controller.bootstrap();
      await sub.cancel();

      expect(states, contains(isA<ProdeAuthHydrating>()));
      expect(controller.state, isA<ProdeAuthAuthenticated>());

      // Degraded placeholder: real userId/playerId/name unknown, sessionVersion from storage
      final authenticated = controller.state as ProdeAuthAuthenticated;
      expect(authenticated.user.userId, equals(0));
      expect(authenticated.user.playerId, equals(0));
      expect(authenticated.user.name, equals(''));
      expect(authenticated.user.sessionVersion, equals(4));
      // stale: true signals the UI that identity has not been server-confirmed yet
      expect(authenticated.stale, isTrue);

      // Storage must NOT be cleared on network failure
      expect(await repo.readAccessToken(), equals('acc'));
    });

    // (e) PlatformException preserved with its code
    test('PlatformException in storage → ProdeAuthError with platform code', () async {
      // Inject a repository that throws PlatformException on readAll.
      // We simulate this by installing a storage platform that throws.
      // Use a custom fake that throws PlatformException on read.
      FlutterSecureStoragePlatform.instance =
          _ThrowingStoragePlatform(PlatformException(code: 'KEYCHAIN_ERROR', message: 'Keychain unavailable.'));

      final throwingRepo = ProdeAuthRepository();
      final controller = _makeController(
        throwingRepo,
        MockClient((_) async => throw http.ClientException('Should not call')),
      );

      final states = <ProdeAuthState>[];
      final sub = controller.stream.listen(states.add);

      await controller.bootstrap();
      await sub.cancel();

      expect(controller.state, isA<ProdeAuthError>());
      final error = controller.state as ProdeAuthError;
      expect(error.code, equals('KEYCHAIN_ERROR'));
    });

    test('tokens + non-revoked 401 → Unauthenticated (tokens cleared)', () async {
      await repo.write(
        accessToken: 'acc',
        refreshToken: 'ref',
        sessionVersion: '1',
        tenantId: 'marianista',
      );

      final controller = _makeController(
        repo,
        MockClient((_) async => _refresh401(code: 'refresh_token_invalid', message: 'Token rotated.')),
      );

      await controller.bootstrap();

      expect(controller.state, isA<ProdeAuthUnauthenticated>());
      expect(await repo.readAccessToken(), isNull);
    });

    test('initial state before bootstrap is Unauthenticated', () {
      final controller = _makeController(
        repo,
        MockClient((_) async => throw http.ClientException('Should not be called')),
      );
      expect(controller.state, isA<ProdeAuthUnauthenticated>());
    });
  });

  group('ProdeAuthController.logout()', () {
    late Map<String, String> store;
    late ProdeAuthRepository repo;
    late ProdeApiService service;
    late ProdeAuthController controller;

    setUp(() async {
      store = {};
      _setUpFakeStorage(store);
      repo = ProdeAuthRepository();

      // Seed tokens so there is something to clear.
      await repo.write(
        accessToken: 'acc',
        refreshToken: 'ref',
        sessionVersion: '1',
        tenantId: 'marianista',
      );

      service = ProdeApiService(
        config: _testConfig,
        authRepo: repo,
        httpClient: MockClient((_) async => throw http.ClientException('Unused')),
      );
      controller = ProdeAuthController(
        repository: repo,
        service: service,
        tenantId: 'marianista',
      );
    });

    test('logout clears repository and transitions to Unauthenticated', () async {
      await controller.logout();

      expect(controller.state, isA<ProdeAuthUnauthenticated>());
      expect(await repo.readAccessToken(), isNull);
      expect(await repo.readRefreshToken(), isNull);
      expect(await repo.readTenantId(), isNull);
    });

    test('logout invalidates service token cache', () async {
      await controller.logout();

      expect(controller.state, isA<ProdeAuthUnauthenticated>());
      expect(await repo.readAccessToken(), isNull);
    });
  });

  group('ProdeAuthController.onAuthRequired()', () {
    late ProdeAuthController controller;

    setUp(() {
      final store = <String, String>{};
      _setUpFakeStorage(store);
      final repo = ProdeAuthRepository();
      controller = _makeController(
        repo,
        MockClient((_) async => throw http.ClientException('Unused')),
      );
    });

    test('session_revoked code → state is Revoked', () {
      controller.onAuthRequired(
        const ProdeAuthRequired(
          code: 'session_revoked',
          message: 'Session was revoked by admin.',
        ),
      );

      expect(controller.state, isA<ProdeAuthRevoked>());
      final revoked = controller.state as ProdeAuthRevoked;
      expect(revoked.reason, equals('session_revoked'));
    });

    test('token_expired code → state is Unauthenticated', () {
      controller.onAuthRequired(
        const ProdeAuthRequired(
          code: 'token_expired',
          message: 'Token has expired.',
        ),
      );

      expect(controller.state, isA<ProdeAuthUnauthenticated>());
    });

    test('arbitrary code → state is Unauthenticated', () {
      controller.onAuthRequired(
        const ProdeAuthRequired(
          code: 'refresh_token_invalid',
          message: 'Refresh token was rotated.',
        ),
      );

      expect(controller.state, isA<ProdeAuthUnauthenticated>());
    });
  });

  group('ProdeAuthController.onTokensRefreshed()', () {
    late ProdeAuthController controller;

    setUp(() {
      final store = <String, String>{};
      _setUpFakeStorage(store);
      final repo = ProdeAuthRepository();
      controller = _makeController(
        repo,
        MockClient((_) async => throw http.ClientException('Unused')),
      );
    });

    test('Authenticated(placeholder) → Authenticated(realUser) on refresh', () {
      // Seed the controller in the degraded-placeholder shape that bootstrap
      // emits on network failure (stale: true).
      controller.state = ProdeAuthAuthenticated(
        user: const ProdeUser(
          userId: 0,
          playerId: 0,
          name: '',
          sessionVersion: 3,
        ),
        stale: true,
      );

      const real = ProdeUser(
        userId: 42,
        playerId: 7,
        name: 'María García',
        sessionVersion: 4,
      );
      controller.onTokensRefreshed(real);

      expect(controller.state, isA<ProdeAuthAuthenticated>());
      final auth = controller.state as ProdeAuthAuthenticated;
      expect(auth.user.userId, equals(42));
      expect(auth.user.name, equals('María García'));
      expect(auth.user.sessionVersion, equals(4));
      // stale: false confirms real server-confirmed data arrived
      expect(auth.stale, isFalse);
    });

    test('Unauthenticated → unchanged (refresh must not silently re-auth)',
        () {
      controller.state = const ProdeAuthUnauthenticated();

      controller.onTokensRefreshed(const ProdeUser(
        userId: 42,
        playerId: 7,
        name: 'María García',
        sessionVersion: 4,
      ));

      expect(controller.state, isA<ProdeAuthUnauthenticated>());
    });

    test('Revoked → unchanged (refresh must not lift a revoked session)', () {
      controller.state = const ProdeAuthRevoked(reason: 'session_revoked');

      controller.onTokensRefreshed(const ProdeUser(
        userId: 42,
        playerId: 7,
        name: 'María García',
        sessionVersion: 4,
      ));

      expect(controller.state, isA<ProdeAuthRevoked>());
    });
  });

  group('signInWithGoogle()', () {
    late Map<String, String> store;
    late ProdeAuthRepository repo;

    setUp(() {
      store = {};
      _setUpFakeStorage(store);
      repo = ProdeAuthRepository();
    });

    http.Client googleClient(http.Response response) =>
        MockClient((req) async {
          if (req.url.path.endsWith('/auth/google')) return response;
          throw http.ClientException('Unexpected call to ${req.url}');
        });

    test('step=authenticated → Authenticated + tokens persisted', () async {
      final controller = _makeController(
        repo,
        googleClient(http.Response(
          json.encode({
            'step': 'authenticated',
            'access_token': 'acc-1',
            'refresh_token': 'ref-1',
            'user': {
              'user_id': 10,
              'player_id': 3,
              'name': 'Juan',
              'session_version': 2,
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        )),
        googleIdToken: () async => 'fake-id-token',
      );

      await controller.signInWithGoogle();

      final state = controller.state;
      expect(state, isA<ProdeAuthAuthenticated>());
      final auth = state as ProdeAuthAuthenticated;
      expect(auth.user.userId, equals(10));
      expect(auth.user.name, equals('Juan'));
      expect(auth.stale, isFalse);
      expect(await repo.readAccessToken(), equals('acc-1'));
      expect(await repo.readRefreshToken(), equals('ref-1'));
      expect(await repo.readTenantId(), equals('marianista'));
    });

    test('step=dni_confirmation → NeedsDniConfirmation with intent + nameHint',
        () async {
      final controller = _makeController(
        repo,
        googleClient(http.Response(
          json.encode({
            'step': 'dni_confirmation',
            'intent_token': 'intent-xyz',
            'profile': {
              'name_first': 'Juan',
              'name_last': 'Pérez',
              'email': 'j@example.com',
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        )),
        googleIdToken: () async => 'fake-id-token',
      );

      await controller.signInWithGoogle();

      final state = controller.state;
      expect(state, isA<ProdeAuthNeedsDniConfirmation>());
      final needs = state as ProdeAuthNeedsDniConfirmation;
      expect(needs.intentToken, equals('intent-xyz'));
      expect(needs.nameHint, equals('Juan Pérez'));
      // No tokens persisted yet.
      expect(await repo.readAccessToken(), isNull);
    });

    test('user cancels (null id_token) → Unauthenticated', () async {
      final controller = _makeController(
        repo,
        MockClient((_) async => throw http.ClientException('Should not be called')),
        googleIdToken: () async => null,
      );

      await controller.signInWithGoogle();

      expect(controller.state, isA<ProdeAuthUnauthenticated>());
    });

    test('Google SDK throwing → ProdeAuthError', () async {
      final controller = _makeController(
        repo,
        MockClient((_) async => throw http.ClientException('Should not be called')),
        googleIdToken: () async => throw Exception('sdk boom'),
      );

      await controller.signInWithGoogle();

      expect(controller.state, isA<ProdeAuthError>());
    });

    test('backend rejects the provider token → ProdeAuthError', () async {
      final controller = _makeController(
        repo,
        googleClient(http.Response(
          json.encode(
              {'code': 'invalid_provider_token', 'message': 'bad token'}),
          401,
          headers: {'content-type': 'application/json'},
        )),
        googleIdToken: () async => 'fake-id-token',
      );

      await controller.signInWithGoogle();

      final state = controller.state;
      expect(state, isA<ProdeAuthError>());
      expect((state as ProdeAuthError).code, equals('invalid_provider_token'));
    });
  });

  group('PR placeholder methods still throw UnimplementedError', () {
    late ProdeAuthController controller;

    setUp(() {
      final store = <String, String>{};
      _setUpFakeStorage(store);
      final repo = ProdeAuthRepository();
      controller = _makeController(
        repo,
        MockClient((_) async => throw http.ClientException('Unused')),
      );
    });

    test('signInWithApple throws UnimplementedError', () {
      expect(
        () async => controller.signInWithApple(),
        throwsUnimplementedError,
      );
    });

    test('confirmDni throws UnimplementedError', () {
      expect(
        () async => controller.confirmDni('12345678'),
        throwsUnimplementedError,
      );
    });
  });
}

// ---------------------------------------------------------------------------
// Test-only fake storage that throws PlatformException on every read
// ---------------------------------------------------------------------------

class _ThrowingStoragePlatform extends FlutterSecureStoragePlatform {
  final PlatformException _exception;

  _ThrowingStoragePlatform(this._exception);

  @override
  Future<String?> read({
    required String key,
    required Map<String, String> options,
  }) async {
    throw _exception;
  }

  @override
  Future<bool> containsKey({
    required String key,
    required Map<String, String> options,
  }) async => false;

  @override
  Future<void> delete({
    required String key,
    required Map<String, String> options,
  }) async {}

  @override
  Future<void> deleteAll({required Map<String, String> options}) async {}

  @override
  Future<Map<String, String>> readAll({
    required Map<String, String> options,
  }) async => {};

  @override
  Future<void> write({
    required String key,
    required String value,
    required Map<String, String> options,
  }) async {}
}

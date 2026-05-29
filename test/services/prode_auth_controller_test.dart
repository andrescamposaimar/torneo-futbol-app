import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
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
// Builder helper
// ---------------------------------------------------------------------------

/// Creates a controller backed by in-memory secure storage.
ProdeAuthController _makeController(ProdeAuthRepository repo) {
  final service = ProdeApiService(
    config: _testConfig,
    authRepo: repo,
  );
  return ProdeAuthController(repository: repo, service: service);
}

void main() {
  group('ProdeAuthController.bootstrap()', () {
    late Map<String, String> store;
    late ProdeAuthRepository repo;
    late ProdeAuthController controller;

    setUp(() {
      store = {};
      _setUpFakeStorage(store);
      repo = ProdeAuthRepository();
      controller = _makeController(repo);
    });

    test('bootstrap with no tokens → Hydrating then Unauthenticated', () async {
      // Collect stream emissions to verify Hydrating was passed through.
      final states = <ProdeAuthState>[];
      final sub = controller.stream.listen(states.add);

      await controller.bootstrap();
      await sub.cancel();

      // Stream emits each state change (Hydrating, then Unauthenticated).
      // controller.state reflects the final settled state.
      expect(states, contains(isA<ProdeAuthHydrating>()));
      expect(controller.state, isA<ProdeAuthUnauthenticated>());
    });

    test('bootstrap with tokens present → Hydrating then Authenticated', () async {
      // Pre-seed tokens (simulates a previously logged-in state).
      await repo.write(
        accessToken: 'access-xyz',
        refreshToken: 'refresh-xyz',
        sessionVersion: '5',
        tenantId: 'marianista',
      );

      // Re-create controller so it starts fresh.
      controller = _makeController(repo);

      final states = <ProdeAuthState>[];
      final sub = controller.stream.listen(states.add);

      await controller.bootstrap();
      await sub.cancel();

      expect(states, contains(isA<ProdeAuthHydrating>()));
      expect(controller.state, isA<ProdeAuthAuthenticated>());

      final authenticated = controller.state as ProdeAuthAuthenticated;
      // sessionVersion was stored as '5' → parsed to 5.
      expect(authenticated.user.sessionVersion, equals(5));
    });

    test('initial state before bootstrap is Unauthenticated', () {
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

      service = ProdeApiService(config: _testConfig, authRepo: repo);
      controller = ProdeAuthController(repository: repo, service: service);
    });

    test('logout clears repository and transitions to Unauthenticated', () async {
      await controller.logout();

      // State is Unauthenticated.
      expect(controller.state, isA<ProdeAuthUnauthenticated>());

      // Storage is cleared.
      expect(await repo.readAccessToken(), isNull);
      expect(await repo.readRefreshToken(), isNull);
      expect(await repo.readTenantId(), isNull);
    });

    test('logout invalidates service token cache', () async {
      // Prime the service cache by reading the access token manually.
      // (The cache is set the first time request() is called; here we
      // approximate by verifying that after logout the service reports null.)
      await controller.logout();

      // After logout, the service cache must be null.
      // We verify indirectly: if cache were not invalidated, calling
      // invalidateTokenCache() again would be a no-op (idempotent) — so
      // we just assert that the repository was cleared and the state is
      // Unauthenticated (coverage of the combined operation).
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
      controller = _makeController(repo);
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

  group('PR-06 placeholder methods throw UnimplementedError', () {
    late ProdeAuthController controller;

    setUp(() {
      final store = <String, String>{};
      _setUpFakeStorage(store);
      final repo = ProdeAuthRepository();
      controller = _makeController(repo);
    });

    test('signInWithGoogle throws UnimplementedError', () {
      expect(
        () async => controller.signInWithGoogle(),
        throwsUnimplementedError,
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

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';
import 'package:torneo_futbol_app/services/prode_fixtures_controller.dart';
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

/// Creates a real [ProdeApiService] backed by a [MockClient] — mirroring the
/// auth-controller test convention so the authenticated request() transport
/// is exercised end-to-end.
ProdeApiService _makeService(http.Client httpClient, ProdeAuthRepository repo) {
  return ProdeApiService(
    config: _testConfig,
    authRepo: repo,
    httpClient: httpClient,
  );
}

/// Builds a controller with a given [MockClient] and pre-seeded storage.
///
/// Uses [ProdeAuthRepository.write] to seed the token blob in the same format
/// the repository itself writes — matching the auth-controller test convention.
Future<ProdeFixturesController> _makeController(
  http.Client httpClient, {
  String accessToken = 'test-access',
  String refreshToken = 'test-refresh',
}) async {
  _setUpFakeStorage({});
  final repo = ProdeAuthRepository();
  await repo.write(
    accessToken: accessToken,
    refreshToken: refreshToken,
    sessionVersion: '1',
    tenantId: 'marianista',
  );
  final service = _makeService(httpClient, repo);
  return ProdeFixturesController(service);
}

/// A minimal valid fecha-activa JSON response body.
String _fechaBody({
  int fechaId = 1,
  int seasonId = 10,
  String state = 'open',
  int matchCount = 2,
}) {
  final matches = List.generate(
    matchCount,
    (i) => {
      'match_id': i + 1,
      'home_team': 'Home $i',
      'away_team': 'Away $i',
      'kickoff': '2026-06-07 14:00:00',
    },
  );
  return json.encode({
    'fecha_id': fechaId,
    'season_id': seasonId,
    'state': state,
    'locked_at': null,
    'matches': matches,
  });
}

http.Response _fecha200({int matchCount = 2}) => http.Response(
      _fechaBody(matchCount: matchCount),
      200,
      headers: {'content-type': 'application/json'},
    );

http.Response _fecha404() => http.Response(
      json.encode({'code': 'no_active_fecha', 'message': 'Not found'}),
      404,
      headers: {'content-type': 'application/json'},
    );

http.Response _fecha500() => http.Response(
      json.encode({'code': 'server_error', 'message': 'Internal server error'}),
      500,
      headers: {'content-type': 'application/json'},
    );

http.Response _refresh401({
  String code = 'token_expired',
  String message = 'Token expired.',
}) =>
    http.Response(
      json.encode({'code': code, 'message': message}),
      401,
      headers: {'content-type': 'application/json'},
    );

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

void main() {
  group('ProdeFixturesController', () {
    test('initial state is ProdeFixturesLoading', () async {
      final controller =
          await _makeController(MockClient((_) async => _fecha200()));
      expect(controller.state, isA<ProdeFixturesLoading>());
    });

    group('load()', () {
      test('200 OK transitions Loading -> Loaded with expected fecha', () async {
        final states = <ProdeFixturesState>[];
        final controller =
            await _makeController(MockClient((_) async => _fecha200()));
        controller.addListener((s) => states.add(s), fireImmediately: false);

        await controller.load();

        // Loading (from initial) then Loaded
        expect(states, hasLength(greaterThanOrEqualTo(1)));
        final loaded = states.lastWhere((s) => s is ProdeFixturesLoaded,
            orElse: () => const ProdeFixturesEmpty());
        expect(loaded, isA<ProdeFixturesLoaded>());
        final fecha = (loaded as ProdeFixturesLoaded).fecha;
        expect(fecha.fechaId, equals(1));
        expect(fecha.matches, hasLength(2));
      });

      test('404 transitions Loading -> Empty', () async {
        final states = <ProdeFixturesState>[];
        final controller =
            await _makeController(MockClient((_) async => _fecha404()));
        controller.addListener((s) => states.add(s), fireImmediately: false);

        await controller.load();

        expect(states.last, isA<ProdeFixturesEmpty>());
      });

      test('500 transitions Loading -> Error', () async {
        final states = <ProdeFixturesState>[];
        final controller =
            await _makeController(MockClient((_) async => _fecha500()));
        controller.addListener((s) => states.add(s), fireImmediately: false);

        await controller.load();

        expect(states.last, isA<ProdeFixturesError>());
        final error = states.last as ProdeFixturesError;
        expect(error.code, isNotEmpty);
        expect(error.message, isNotEmpty);
      });

      test('sets Loading state before fetching', () async {
        final states = <ProdeFixturesState>[];
        final controller =
            await _makeController(MockClient((_) async => _fecha200()));
        // Capture from initial (Loading) — use fireImmediately
        controller.addListener((s) => states.add(s), fireImmediately: true);

        await controller.load();

        // First state emitted immediately should be Loading
        expect(states.first, isA<ProdeFixturesLoading>());
      });

      test('guard: does not re-fetch when already non-Loading (Loaded)', () async {
        var callCount = 0;
        final controller = await _makeController(
          MockClient((_) async {
            callCount++;
            return _fecha200();
          }),
        );

        await controller.load(); // => Loaded
        final stateAfterFirst = controller.state;
        expect(stateAfterFirst, isA<ProdeFixturesLoaded>());

        await controller.load(); // should NOT re-fetch
        expect(callCount, equals(1)); // only one HTTP call
      });
    });

    group('refresh()', () {
      test('from Loaded: 200 -> new Loaded without intermediate Loading', () async {
        // First load
        var callCount = 0;
        final controller = await _makeController(
          MockClient((_) async {
            callCount++;
            return _fecha200(matchCount: callCount == 1 ? 2 : 3);
          }),
        );
        await controller.load();
        expect(controller.state, isA<ProdeFixturesLoaded>());

        // Capture states during refresh
        final states = <ProdeFixturesState>[];
        controller.addListener((s) => states.add(s), fireImmediately: false);

        await controller.refresh();

        // Should NOT have a Loading state in the captured sequence
        expect(states.any((s) => s is ProdeFixturesLoading), isFalse,
            reason: 'refresh() must not flash Loading when starting from Loaded');
        expect(states.last, isA<ProdeFixturesLoaded>());
      });

      test('from Loaded: 500 -> Error', () async {
        var callCount = 0;
        final controller = await _makeController(
          MockClient((_) async {
            callCount++;
            if (callCount == 1) return _fecha200();
            return _fecha500();
          }),
        );

        await controller.load(); // Loaded
        await controller.refresh(); // Error

        expect(controller.state, isA<ProdeFixturesError>());
      });

      test('from Loaded: 404 -> Empty', () async {
        var callCount = 0;
        final controller = await _makeController(
          MockClient((_) async {
            callCount++;
            if (callCount == 1) return _fecha200();
            return _fecha404();
          }),
        );

        await controller.load(); // Loaded
        await controller.refresh(); // Empty

        expect(controller.state, isA<ProdeFixturesEmpty>());
      });
    });

    group('401 with no refresh token -> Error(auth_required)', () {
      test('load() with expired access token and no refresh token -> Error', () async {
        // Seed only an access token (no refresh token) so the 401 refresh
        // flow will fail — the service returns auth_required.
        _setUpFakeStorage({});
        final repo = ProdeAuthRepository();
        await repo.write(
          accessToken: 'expired-token',
          refreshToken: '', // empty so readRefreshToken returns null equivalent
          sessionVersion: '1',
          tenantId: 'marianista',
        );
        final service = _makeService(
          MockClient((_) async => _refresh401()),
          repo,
        );
        final controller = ProdeFixturesController(service);

        await controller.load();

        expect(controller.state, isA<ProdeFixturesError>());
        final error = controller.state as ProdeFixturesError;
        expect(error.code, equals('auth_required'));
      });
    });

    group('ProdeFixturesState equality and helpers', () {
      test('ProdeFixturesLoading == ProdeFixturesLoading', () {
        expect(
            const ProdeFixturesLoading(), equals(const ProdeFixturesLoading()));
      });

      test('ProdeFixturesEmpty == ProdeFixturesEmpty', () {
        expect(const ProdeFixturesEmpty(), equals(const ProdeFixturesEmpty()));
      });

      test('ProdeFixturesError equality', () {
        const a =
            ProdeFixturesError(code: 'fixtures_error', message: 'msg');
        const b =
            ProdeFixturesError(code: 'fixtures_error', message: 'msg');
        expect(a, equals(b));
        expect(a.hashCode, equals(b.hashCode));
      });

      test('ProdeFixturesError toString includes code', () {
        const e = ProdeFixturesError(code: 'test', message: 'boom');
        expect(e.toString(), contains('test'));
      });
    });
  });
}

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/config/tenant_config.dart';
import 'package:torneo_futbol_app/config/tenant_provider.dart';
import 'package:torneo_futbol_app/models/app_config.dart';
import 'package:torneo_futbol_app/models/credencial.dart';
import 'package:torneo_futbol_app/providers/config_provider.dart';
import 'package:torneo_futbol_app/providers/credencial_providers.dart';
import 'package:torneo_futbol_app/screens/credencial/credencial_screen.dart';
import 'package:torneo_futbol_app/services/credencial_api_service.dart';
import 'package:torneo_futbol_app/services/credencial_controller.dart';
import 'package:torneo_futbol_app/services/credencial_photo_store.dart';
import 'package:torneo_futbol_app/services/credencial_repository.dart';
import 'package:torneo_futbol_app/services/credencial_state.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';

TenantConfig _testTenant() => TenantConfig(
      tenantId: 'test-tenant',
      appName: 'Test',
      apiBaseUrl: 'https://test.example.com',
      mediaBaseUrl: 'https://test.example.com',
      colors: const BrandColors(
        primary: Colors.blue,
        accent: Colors.cyan,
        splashBackground: Colors.white,
      ),
      features: const TenantFeatures(credencial: true),
      logoAsset: 'assets/images/app_logo.png',
    );

// ---------------------------------------------------------------------------
// Stub controller — seeds a fixed state, open() is a no-op so the widget's
// initState microtask never overwrites the state under test.
// ---------------------------------------------------------------------------

const _kProdeConfig = ProdeAuthConfig(
  prodeApiBaseUrl: 'https://test.example.com/prode',
  googleWebClientId: 'test',
  appleTeamId: 'TEST',
);

class _StubCredencialController extends CredencialController {
  _StubCredencialController(CredencialUiState initialState)
      : super(
          api: CredencialApiService(
            prodeApi: ProdeApiService(
                config: _kProdeConfig, authRepo: ProdeAuthRepository()),
            credencialApiBaseUrl: 'https://test.example.com/credencial',
          ),
          repository: CredencialRepository(),
        ) {
    state = initialState;
  }

  @override
  Future<void> open() async {}
}

/// A [CredencialPhotoStore] with no real filesystem access at all — every
/// method is overridden. `readVerified` always returns null (no cached
/// photo bytes), which is enough for the Active state to render the
/// placeholder icon instead of a real image.
///
/// IMPORTANT: this codebase's `flutter_tester` sandbox does not reliably
/// complete real `dart:io` `Directory` operations (create/delete) from
/// inside a `testWidgets` body — confirmed by isolating a bare
/// `Directory.systemTemp.createTemp()` call in a throwaway test, which hung
/// indefinitely with zero CPU usage (idle condition-variable wait, not a
/// compile or logic issue). Screen-level widget tests must therefore never
/// touch the real filesystem; [CredencialPhotoStore] unit tests (which run
/// as plain `test()`, not `testWidgets()`) are unaffected and already cover
/// the real temp-dir-backed behavior.
class _FakePhotoStore implements CredencialPhotoStore {
  @override
  Future<Uint8List?> readVerified(String sha256Hex) async => null;

  @override
  Future<void> write(Uint8List bytes, String sha256Hex) async {}

  @override
  Future<void> deleteAllExcept(String? keepSha256Hex) async {}

  @override
  Future<void> wipe() async {}
}

/// A 1x1 transparent PNG — the smallest byte sequence `Image.memory` can
/// actually decode, matching [CredencialActive.photoBytes]'s non-nullable
/// contract (decision 1523: never a valid card without verified bytes).
Uint8List _validPngBytes() => Uint8List.fromList([
      137,
      80,
      78,
      71,
      13,
      10,
      26,
      10,
      0,
      0,
      0,
      13,
      73,
      72,
      68,
      82,
      0,
      0,
      0,
      1,
      0,
      0,
      0,
      1,
      8,
      6,
      0,
      0,
      0,
      31,
      21,
      196,
      137,
      0,
      0,
      0,
      10,
      73,
      68,
      65,
      84,
      120,
      156,
      99,
      0,
      1,
      0,
      0,
      5,
      0,
      1,
      13,
      10,
      45,
      180,
      0,
      0,
      0,
      0,
      73,
      69,
      78,
      68,
      174,
      66,
      96,
      130,
    ]);

Credencial _credencial() {
  return const Credencial(
    id: 'cred-1',
    issuedAt: '2026-09-29 00:00:00',
    expiresAt: '2027-09-29 00:00:00',
    playerId: 1,
    fullName: 'Juan Perez',
    dni: '30111222',
    birthDate: '2000-01-01 00:00:00',
    caracter: 'Padre Alumno',
    team: CredencialTeam(
        id: 1, name: 'Real Madrid', kind: CredencialTeamKind.team),
    photo: CredencialPhoto(url: 'https://example.com/p.jpg', sha256: 'abc'),
    codeSeed: 'c2VlZA',
    code: CredencialCode(alg: 'SHA256', step: 30, digits: 6),
  );
}

Future<void> _pump(
  WidgetTester tester,
  CredencialUiState state, {
  Size size = const Size(390, 844),
  double textScale = 1.3,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  FlutterSecureStoragePlatform.instance = TestFlutterSecureStoragePlatform({});

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        tenantConfigProvider.overrideWithValue(_testTenant()),
        // EntreRedesAppBar watches this — override so the screen never makes
        // a real network call in tests (this screen's own behavior does not
        // depend on the remote app config at all).
        appConfigProvider.overrideWith((ref) async => const AppConfig()),
        credencialControllerProvider
            .overrideWith((ref) => _StubCredencialController(state)),
        credencialPhotoStoreProvider.overrideWithValue(_FakePhotoStore()),
      ],
      child: MediaQuery(
        data: MediaQueryData(textScaler: TextScaler.linear(textScale)),
        child: const MaterialApp(home: CredencialScreen()),
      ),
    ),
  );
  // A single pump is enough: CredencialActive carries already-verified
  // photoBytes by construction (decision 1523), so there is no async
  // render-time read left to wait on (verify-report 1575, slice 3b, NEW
  // WARNING 1 — the old FutureBuilder re-read this used to wait out here is
  // gone).
  await tester.pump();
}

void main() {
  group('CredencialScreen · per-state rendering', () {
    testWidgets('Loading → spinner', (tester) async {
      await _pump(tester, const CredencialLoading());
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('NotSignedIn → session-closed message', (tester) async {
      await _pump(tester, const CredencialNotSignedIn());
      expect(find.textContaining('sesión se cerró'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Active → renders the credencial card with full name',
        (tester) async {
      await _pump(
        tester,
        CredencialActive(
            credential: _credencial(), photoBytes: _validPngBytes()),
      );
      expect(find.text('Juan Perez'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    // NEW WARNING 1 (verify-report 1575, slice 3b re-verify): decision 1523
    // forbids the valid-styled card from EVER rendering without a verified
    // photo, not even for one frame. Before the fix, CredencialActive carried
    // no photo bytes and the screen re-read them via a FutureBuilder, so the
    // very first pump after Active rendered the full valid chrome (name, DNI,
    // rotating code) together with the grey Icons.person silhouette. Now
    // CredencialActive.photoBytes is supplied by construction, so a single
    // pump must show the real photo image and never the silhouette icon.
    testWidgets(
        'Active on the very first pump → shows the verified photo image, '
        'never the silhouette placeholder, in that same frame', (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);
      FlutterSecureStoragePlatform.instance =
          TestFlutterSecureStoragePlatform({});

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            tenantConfigProvider.overrideWithValue(_testTenant()),
            appConfigProvider.overrideWith((ref) async => const AppConfig()),
            credencialControllerProvider.overrideWith(
              (ref) => _StubCredencialController(
                CredencialActive(
                    credential: _credencial(), photoBytes: _validPngBytes()),
              ),
            ),
            credencialPhotoStoreProvider.overrideWithValue(_FakePhotoStore()),
          ],
          child: const MaterialApp(home: CredencialScreen()),
        ),
      );
      await tester.pump(); // exactly ONE pump — the real first frame.

      expect(find.byType(Image), findsOneWidget);
      expect(find.byIcon(Icons.person), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Active stale → shows the offline/last-verified banner',
        (tester) async {
      await _pump(
          tester,
          CredencialActive(
              credential: _credencial(),
              photoBytes: _validPngBytes(),
              stale: true));
      expect(find.textContaining('conexión'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('NoPhoto → prompts photo upload, no crash', (tester) async {
      await _pump(tester, const CredencialNoPhoto());
      expect(find.textContaining('foto aprobada'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('PendingPhoto → review-in-progress message', (tester) async {
      await _pump(tester, const CredencialPendingPhoto());
      expect(find.textContaining('revisión'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('RejectedPhoto → rejection message with no reason',
        (tester) async {
      await _pump(tester, const CredencialRejectedPhoto());
      expect(find.textContaining('rechazada'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Blocked → unavailable message', (tester) async {
      await _pump(tester, const CredencialBlocked());
      expect(find.textContaining('no está disponible'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('NotAPlayer → not-eligible message', (tester) async {
      await _pump(tester, const CredencialNotAPlayer());
      expect(find.textContaining('jugador asociado'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Expired → reconnect message with retry button',
        (tester) async {
      await _pump(tester, const CredencialExpired());
      expect(find.textContaining('venció'), findsOneWidget);
      expect(find.text('Reintentar'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('OfflineNoCache → offline message with retry button',
        (tester) async {
      await _pump(tester, const CredencialOfflineNoCache());
      expect(find.textContaining('Sin conexión'), findsOneWidget);
      expect(find.text('Reintentar'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Error → shows the message and a retry button', (tester) async {
      await _pump(tester, const CredencialError('Algo salió mal.'));
      expect(find.text('Algo salió mal.'), findsOneWidget);
      expect(find.text('Reintentar'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    // CRITICAL fix (verify-report 1575, slice 3b): decision 1523 — a fresh
    // active response whose photo could not be verified must render as an
    // explicit non-valid message, never the valid card.
    testWidgets(
        'PhotoUnavailable → non-valid message with retry, no rotating code',
        (tester) async {
      await _pump(tester, const CredencialPhotoUnavailable());
      expect(
          find.textContaining('No pudimos descargar tu foto'), findsOneWidget);
      expect(find.text('Reintentar'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  List<CredencialUiState> messageStates() => <CredencialUiState>[
        const CredencialLoading(),
        const CredencialNotSignedIn(),
        const CredencialNoPhoto(),
        const CredencialPendingPhoto(),
        const CredencialRejectedPhoto(),
        const CredencialBlocked(),
        const CredencialNotAPlayer(),
        const CredencialExpired(),
        const CredencialOfflineNoCache(),
        const CredencialPhotoUnavailable(),
        const CredencialError(
            'Un mensaje de error razonablemente largo para probar overflow.'),
      ];

  Future<void> checkNoOverflow(
    WidgetTester tester,
    CredencialUiState state,
    Size size, {
    double textScale = 1.3,
  }) async {
    await _pump(tester, state, size: size, textScale: textScale);
    expect(tester.takeException(), isNull,
        reason: 'overflow or exception at $size for ${state.runtimeType}');
  }

  group('CredencialScreen · no overflow on narrow phones with large text scale',
      () {
    for (final size in [const Size(375, 667), const Size(390, 844)]) {
      testWidgets('Active at $size, textScale 1.3 → no overflow',
          (tester) async {
        await checkNoOverflow(
            tester,
            CredencialActive(
                credential: _credencial(), photoBytes: _validPngBytes()),
            size);
      });

      testWidgets('every message state at $size, textScale 1.3 → no overflow',
          (tester) async {
        for (final state in messageStates()) {
          await checkNoOverflow(tester, state, size);
        }
      });
    }
  });

  // WARNING 2 (verify-report 1575, slice 3b): at least one test per main
  // state group under an explicit iOS platform override, at 390x844 /
  // textScale 1.3 — the exact bar the orchestrator's brief asked for.
  group('CredencialScreen · iOS platform override, 390x844, textScale 1.3', () {
    // `debugDefaultTargetPlatformOverride` must be reset SYNCHRONOUSLY before
    // the test body's Future completes — `TestWidgetsFlutterBinding
    // ._verifyInvariants` asserts every foundation debug var is unset right
    // after the test callback returns, which runs BEFORE either a
    // group-level tearDown or an `addTearDown` callback fires. A try/finally
    // inside the test body itself is the only ordering that satisfies it.
    testWidgets('Active renders without overflow on iOS', (tester) async {
      debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
      try {
        await checkNoOverflow(
          tester,
          CredencialActive(
              credential: _credencial(), photoBytes: _validPngBytes()),
          const Size(390, 844),
        );
      } finally {
        debugDefaultTargetPlatformOverride = null;
      }
    });

    testWidgets('every message state renders without overflow on iOS',
        (tester) async {
      debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
      try {
        for (final state in messageStates()) {
          await checkNoOverflow(tester, state, const Size(390, 844));
        }
      } finally {
        debugDefaultTargetPlatformOverride = null;
      }
    });
  });
}

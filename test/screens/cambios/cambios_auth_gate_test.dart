import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/config/tenant_config.dart';
import 'package:torneo_futbol_app/config/tenant_provider.dart';
import 'package:torneo_futbol_app/config/tenants/marianista.dart';
import 'package:torneo_futbol_app/providers/cambios_providers.dart';
import 'package:torneo_futbol_app/providers/prode_providers.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_auth_gate.dart';
import 'package:torneo_futbol_app/services/cambios_api_service.dart';
import 'package:torneo_futbol_app/services/cambios_context_controller.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_controller.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';
import 'package:torneo_futbol_app/services/prode_auth_state.dart';

/// Mirrors prode_auth_gate_test.dart's fixture: a prode-enabled tenant, since
/// Cambios authenticates with the same Prode session (see CambiosAuthGate's
/// own docblock) and the shipped marianista tenant has prode disabled.
final _prodeTestTenant = TenantConfig(
  tenantId: 'test-prode',
  appName: 'Test',
  apiBaseUrl: 'https://example.com',
  mediaBaseUrl: 'https://example.com/media',
  colors: const BrandColors(
    primary: Color(0xFF000000),
    accent: Color(0xFF000000),
    splashBackground: Color(0xFF000000),
  ),
  features: const TenantFeatures(prode: true),
  integrations: marianistaTenant.integrations,
  logoAsset: 'assets/logo.png',
);

// ---------------------------------------------------------------------------
// Fixtures for the Authenticated arm (FIX 4): a captain who is already
// signed in, so CambiosAuthGate renders CambiosContextScreen — needs its own
// controller stubbed (no network) plus a stubbed ProdeAuthController that
// starts Authenticated and records logout() calls without touching secure
// storage or the network.
// ---------------------------------------------------------------------------

class _StubAuthController extends ProdeAuthController {
  int logoutCalls = 0;

  _StubAuthController(ProdeAuthState initialState)
      : super(
          repository: ProdeAuthRepository(),
          service: ProdeApiService(
            config: const ProdeAuthConfig(
              prodeApiBaseUrl: 'https://nowhere.test',
              googleWebClientId: 'test',
              appleTeamId: 'TEST',
            ),
            authRepo: ProdeAuthRepository(),
          ),
          tenantId: 'test',
        ) {
    state = initialState;
  }

  @override
  Future<void> bootstrap() async {}

  @override
  Future<void> logout() async {
    logoutCalls++;
  }
}

class _StubContextController extends CambiosContextController {
  _StubContextController(CambiosContextState initialState) : super(_fakeCambiosService()) {
    state = initialState;
  }

  @override
  Future<void> load() async {}

  @override
  Future<void> refresh() async {}
}

CambiosApiService _fakeCambiosService() {
  return CambiosApiService(
    baseUrl: 'https://nowhere.test/cambios',
    prodeApi: ProdeApiService(
      config: const ProdeAuthConfig(
        prodeApiBaseUrl: 'https://nowhere.test/prode',
        googleWebClientId: 'test',
        appleTeamId: 'TEST',
      ),
      authRepo: ProdeAuthRepository(),
    ),
  );
}

void main() {
  testWidgets('not authenticated -> reuses the same Prode sign-in view, no crash',
      (tester) async {
    FlutterSecureStoragePlatform.instance =
        TestFlutterSecureStoragePlatform(<String, String>{});

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          tenantConfigProvider.overrideWithValue(_prodeTestTenant),
        ],
        child: const MaterialApp(home: CambiosAuthGate()),
      ),
    );
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.text('Cambios'), findsOneWidget); // app bar title
    expect(find.text('Continuar con Google'), findsOneWidget); // shared sign-in view
  });

  group('Authenticated arm (FIX 4 — logout affordance)', () {
    Future<void> pumpAuthenticated(WidgetTester tester, _StubAuthController authController) async {
      FlutterSecureStoragePlatform.instance =
          TestFlutterSecureStoragePlatform(<String, String>{});

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            tenantConfigProvider.overrideWithValue(_prodeTestTenant),
            prodeAuthControllerProvider.overrideWith((ref) => authController),
            cambiosContextControllerProvider
                .overrideWith((ref) => _StubContextController(const CambiosContextNotCaptain())),
          ],
          child: const MaterialApp(home: CambiosAuthGate()),
        ),
      );
      await tester.pump();
    }

    testWidgets('shows a "Cerrar sesión" AppBar action once authenticated', (tester) async {
      final authController = _StubAuthController(
        const ProdeAuthAuthenticated(
          user: ProdeUser(userId: 1, playerId: 2, name: 'Ana', sessionVersion: 1),
        ),
      );
      await pumpAuthenticated(tester, authController);

      expect(tester.takeException(), isNull);
      expect(find.byKey(const Key('cambios_logout_button')), findsOneWidget);
    });

    testWidgets('tapping it reaches ProdeAuthController.logout()', (tester) async {
      final authController = _StubAuthController(
        const ProdeAuthAuthenticated(
          user: ProdeUser(userId: 1, playerId: 2, name: 'Ana', sessionVersion: 1),
        ),
      );
      await pumpAuthenticated(tester, authController);

      await tester.tap(find.byKey(const Key('cambios_logout_button')));
      expect(authController.logoutCalls, equals(1));
    });

    testWidgets('the logout action is absent before authentication (Unauthenticated)',
        (tester) async {
      final authController = _StubAuthController(const ProdeAuthUnauthenticated());
      await pumpAuthenticated(tester, authController);

      expect(find.byKey(const Key('cambios_logout_button')), findsNothing);
    });
  });
}

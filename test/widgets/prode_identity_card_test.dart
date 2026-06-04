import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/config/tenant_config.dart';
import 'package:torneo_futbol_app/config/tenant_provider.dart';
import 'package:torneo_futbol_app/providers/prode_providers.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_controller.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';
import 'package:torneo_futbol_app/services/prode_auth_state.dart';
import 'package:torneo_futbol_app/widgets/prode_identity_card.dart';

// ---------------------------------------------------------------------------
// Fake API service (no platform channels)
// ---------------------------------------------------------------------------

const _kProdeConfig = ProdeAuthConfig(
  prodeApiBaseUrl: 'https://nowhere.test',
  googleWebClientId: 'test',
  appleTeamId: 'TEST',
);

class _FakeApiService extends ProdeApiService {
  _FakeApiService()
      : super(config: _kProdeConfig, authRepo: ProdeAuthRepository());
}

// ---------------------------------------------------------------------------
// Stub ProdeAuthController — seeds state, all async ops are no-ops
// ---------------------------------------------------------------------------

class _StubAuthController extends ProdeAuthController {
  _StubAuthController(ProdeAuthState initialState)
      : super(
          repository: ProdeAuthRepository(),
          service: _FakeApiService(),
          tenantId: 'test',
        ) {
    state = initialState;
  }

  @override
  Future<void> bootstrap() async {}

  @override
  Future<void> logout() async {}

  @override
  Future<void> signInWithGoogle() async {}

  @override
  Future<void> signInWithApple() async {}
}

// ---------------------------------------------------------------------------
// Tenant fixtures
// ---------------------------------------------------------------------------

TenantConfig _makeTenant({bool prode = true}) => TenantConfig(
      tenantId: 'test-tenant',
      appName: 'Test',
      apiBaseUrl: 'https://test.example.com',
      mediaBaseUrl: 'https://test.example.com',
      colors: const BrandColors(
        primary: Colors.blue,
        accent: Colors.cyan,
        splashBackground: Colors.white,
      ),
      features: TenantFeatures(prode: prode),
      integrations: const TenantIntegrations(prodeAuth: _kProdeConfig),
      logoAsset: 'assets/images/app_logo.png',
    );

// ---------------------------------------------------------------------------
// Pump helper
// ---------------------------------------------------------------------------

Future<void> _pump(
  WidgetTester tester,
  ProdeAuthState authState, {
  bool prode = true,
}) async {
  final tenant = _makeTenant(prode: prode);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        tenantConfigProvider.overrideWithValue(tenant),
        prodeApiServiceProvider.overrideWithValue(
          _FakeApiService(),
        ),
        prodeAuthControllerProvider.overrideWith(
          (ref) => _StubAuthController(authState),
        ),
      ],
      child: const MaterialApp(
        home: Scaffold(body: ProdeIdentityCard()),
      ),
    ),
  );
  // Settle microtask from initState bootstrap guard
  await tester.pump();
}

// ---------------------------------------------------------------------------
// Tests (AC-53a..h)
// ---------------------------------------------------------------------------

void main() {
  group('ProdeIdentityCard', () {
    // AC-53a / AC-10
    testWidgets('Unauthenticated → sign-in buttons visible', (tester) async {
      await _pump(tester, const ProdeAuthUnauthenticated());
      expect(find.text('Continuar con Google'), findsOneWidget);
    });

    // AC-53b / AC-13, AC-18
    testWidgets('Authenticating → spinner visible; Google button absent',
        (tester) async {
      await _pump(
          tester, const ProdeAuthAuthenticating(provider: 'google'));
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      expect(find.text('Continuar con Google'), findsNothing);
    });

    // AC-53c / AC-14
    testWidgets(
        'NeedsDniConfirmation → "Completá tu registro" tile visible',
        (tester) async {
      await _pump(
          tester,
          const ProdeAuthNeedsDniConfirmation(
              intentToken: 'tok', nameHint: 'Juan'));
      expect(find.text('Completá tu registro'), findsOneWidget);
    });

    // AC-53d / AC-15
    testWidgets(
        'Authenticated (name non-empty) → player name visible',
        (tester) async {
      const user = ProdeUser(
        userId: 1,
        playerId: 10,
        name: 'Ana García',
        sessionVersion: 1,
      );
      await _pump(
          tester, ProdeAuthAuthenticated(user: user, stale: false));
      expect(find.text('Ana García'), findsOneWidget);
    });

    // AC-53e / AC-17
    testWidgets(
        'Authenticated stale + empty name → "Sincronizando..." visible',
        (tester) async {
      const user = ProdeUser(
        userId: 0,
        playerId: 0,
        name: '',
        sessionVersion: 1,
      );
      await _pump(tester, ProdeAuthAuthenticated(user: user, stale: true));
      expect(find.text('Sincronizando...'), findsOneWidget);
    });

    // AC-53f / AC-19
    testWidgets(
        'Revoked → lock icon and "Volver a ingresar" button visible',
        (tester) async {
      await _pump(
          tester, const ProdeAuthRevoked(reason: 'session_revoked'));
      expect(find.byIcon(Icons.lock_outline), findsOneWidget);
      expect(find.text('Volver a ingresar'), findsOneWidget);
    });

    // AC-53g / AC-20
    testWidgets(
        'ProdeAuthError → error icon and "Reintentar" button visible',
        (tester) async {
      await _pump(
          tester,
          const ProdeAuthError(
              code: 'bootstrap_error', message: 'oops'));
      expect(find.byIcon(Icons.error_outline), findsOneWidget);
      expect(find.text('Reintentar'), findsOneWidget);
    });

    // AC-53h / AC-07, AC-48
    testWidgets(
        'prode=false guard → SizedBox.shrink() only; no StateError',
        (tester) async {
      // When prode=false we do NOT override prodeApiServiceProvider because
      // the card must return SizedBox.shrink() BEFORE touching that provider.
      final tenant = _makeTenant(prode: false);
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            tenantConfigProvider.overrideWithValue(tenant),
            // Intentionally NOT overriding prodeApiServiceProvider —
            // accessing it would throw StateError; the guard must prevent that.
          ],
          child: const MaterialApp(
            home: Scaffold(body: ProdeIdentityCard()),
          ),
        ),
      );
      await tester.pump();

      // No exception thrown
      expect(tester.takeException(), isNull);

      // No sign-in copy, no spinner — card is invisible
      expect(find.text('Continuar con Google'), findsNothing);
      expect(find.byType(CircularProgressIndicator), findsNothing);
    });
  });
}

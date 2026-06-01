import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/tenant_config.dart';
import 'package:torneo_futbol_app/config/tenant_provider.dart';
import 'package:torneo_futbol_app/config/tenants/marianista.dart';
import 'package:torneo_futbol_app/screens/prode/prode_auth_gate.dart';

/// A prode-enabled tenant fixture. The production tenant (marianista) ships
/// with prode disabled ("ship dark") until the backend is deployed, so this
/// test uses a dedicated fixture with prode:true (reusing marianista's real
/// prodeAuth config) to exercise the gate independently of the shipped flag.
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

void main() {
  // Regression test for the bug where ProdeAuthGate.initState called
  // bootstrap() synchronously, mutating the provider during the build phase
  // ("Tried to modify a provider while the widget tree was building").
  // Empty secure storage → bootstrap resolves to Unauthenticated with no
  // network call, so the gate must mount cleanly and show the sign-in view.
  testWidgets('mounts without modifying a provider during build, then shows sign-in',
      (tester) async {
    FlutterSecureStoragePlatform.instance =
        TestFlutterSecureStoragePlatform(<String, String>{});

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          tenantConfigProvider.overrideWithValue(_prodeTestTenant),
        ],
        child: const MaterialApp(home: ProdeAuthGate()),
      ),
    );
    await tester.pumpAndSettle();

    // The key assertion: mounting did NOT throw the build-phase provider error.
    expect(tester.takeException(), isNull);

    // Empty storage → bootstrap ends in Unauthenticated → the sign-in view.
    expect(find.text('Continuar con Google'), findsOneWidget);
  });
}

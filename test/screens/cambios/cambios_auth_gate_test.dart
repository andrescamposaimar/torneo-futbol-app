import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/tenant_config.dart';
import 'package:torneo_futbol_app/config/tenant_provider.dart';
import 'package:torneo_futbol_app/config/tenants/marianista.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_auth_gate.dart';

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
}

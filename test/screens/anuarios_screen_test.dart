import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/tenant_config.dart';
import 'package:torneo_futbol_app/config/tenant_provider.dart';
import 'package:torneo_futbol_app/screens/anuarios_screen.dart';

// ---------------------------------------------------------------------------
// Pump helper
// ---------------------------------------------------------------------------

/// Wraps [AnuariosScreen] in a minimal [MaterialApp] + [ProviderScope].
///
/// [EntreRedesAppBar] needs [tenantConfigProvider] (via [appConfigProvider]);
/// we override it with a minimal tenant that satisfies the lookup.
Future<void> _pump(
  WidgetTester tester,
  List<TenantAnuario> anuarios,
) async {
  const tenantCfg = TenantConfig(
    tenantId: 'test',
    appName: 'Test',
    apiBaseUrl: 'https://test.example.com',
    mediaBaseUrl: 'https://test.example.com',
    colors: BrandColors(
      primary: Colors.blue,
      accent: Colors.cyan,
      splashBackground: Colors.white,
    ),
    features: TenantFeatures(),
    logoAsset: 'assets/images/app_logo.png',
  );

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        tenantConfigProvider.overrideWithValue(tenantCfg),
      ],
      child: MaterialApp(
        home: AnuariosScreen(anuarios: anuarios),
      ),
    ),
  );
  await tester.pump();
}

// ---------------------------------------------------------------------------
// Tests (AC-34, AC-32 / AC-49)
// ---------------------------------------------------------------------------

void main() {
  group('AnuariosScreen', () {
    // AC-34: 4 anuarios → 4 tiles with correct labels
    testWidgets('4 anuarios → 4 list tiles rendered with correct labels',
        (tester) async {
      final anuarios = [
        const TenantAnuario(label: 'Anuario 2020', url: 'https://example.com/2020.pdf'),
        const TenantAnuario(label: 'Anuario 2021', url: 'https://example.com/2021.pdf'),
        const TenantAnuario(label: 'Anuario 2022', url: 'https://example.com/2022.pdf'),
        const TenantAnuario(label: 'Anuario 2023', url: 'https://example.com/2023.pdf'),
      ];

      await _pump(tester, anuarios);

      expect(find.text('Anuario 2020'), findsOneWidget);
      expect(find.text('Anuario 2021'), findsOneWidget);
      expect(find.text('Anuario 2022'), findsOneWidget);
      expect(find.text('Anuario 2023'), findsOneWidget);
    });

    // AC-32, AC-49: empty list → no tiles
    testWidgets('empty anuarios list → no list tiles rendered', (tester) async {
      await _pump(tester, const []);

      // No tile text other than the app bar title
      expect(find.byType(ListTile), findsNothing);
    });

    // AC-34: screen uses EntreRedesAppBar with title 'Anuarios'
    testWidgets('screen title is "Anuarios"', (tester) async {
      await _pump(tester, const []);
      expect(find.text('Anuarios'), findsOneWidget);
    });

    // AC-35: AnuariosScreen does NOT watch tenantConfigProvider for docs
    //        — it receives the list as a constructor param.
    //        This is structural; validated by the fact that the pump helper
    //        does not set up docs and all other tests pass.
    testWidgets(
        'screen renders without needing tenantConfigProvider docs field',
        (tester) async {
      const singleAnuario =
          TenantAnuario(label: 'Anuario único', url: 'https://example.com/u.pdf');

      await _pump(tester, const [singleAnuario]);
      expect(find.text('Anuario único'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });
}

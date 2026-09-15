import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:torneo_futbol_app/config/tenant_config.dart';
import 'package:torneo_futbol_app/config/tenant_provider.dart';
import 'package:torneo_futbol_app/providers/service_providers.dart';
import 'package:torneo_futbol_app/services/remote_data_service.dart';
import 'package:torneo_futbol_app/widgets/zocalo_publicitario.dart';

// ---------------------------------------------------------------------------
// Fake remote data service — returns a fixed ad list, no network involved.
// ---------------------------------------------------------------------------

class _FakeRemoteDataService extends RemoteDataService {
  final List<AdItem> adsToReturn;

  _FakeRemoteDataService(this.adsToReturn)
      : super(mediaBaseUrl: 'https://media.test', apiBaseUrl: 'https://api.test');

  @override
  Future<List<AdItem>> fetchZocaloAds() async => adsToReturn;
}

// ---------------------------------------------------------------------------
// Pump helper
// ---------------------------------------------------------------------------

const _tenantCfg = TenantConfig(
  tenantId: 'test',
  appName: 'Test',
  apiBaseUrl: 'https://api.test',
  mediaBaseUrl: 'https://media.test',
  colors: BrandColors(
    primary: Colors.blue,
    accent: Colors.cyan,
    splashBackground: Colors.white,
  ),
  features: TenantFeatures(ads: true),
  logoAsset: 'assets/images/app_logo.png',
);

Future<void> _pump(WidgetTester tester, List<AdItem> ads) async {
  SharedPreferences.setMockInitialValues({});

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        tenantConfigProvider.overrideWithValue(_tenantCfg),
        remoteDataServiceProvider.overrideWithValue(_FakeRemoteDataService(ads)),
      ],
      child: const MaterialApp(
        home: Scaffold(body: ZocaloPublicitario()),
      ),
    ),
  );
  // _loadAds() chains SharedPreferences.getInstance() -> prefs.remove() ->
  // fetchZocaloAds() -> setState. Two pumps reliably flush that microtask
  // chain and apply the resulting rebuild.
  await tester.pump();
  await tester.pump();
}

/// The banner starts a `Timer.periodic` once ads are loaded. It only stops
/// on dispose, so any test that doesn't close the ad must unmount the tree
/// itself before finishing, or flutter_test's pending-timer check fails.
Future<void> _unmount(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox.shrink());
}

void main() {
  group('ZocaloPublicitario', () {
    testWidgets('an ad with an image and a link renders and is tappable',
        (tester) async {
      await _pump(tester, const [
        AdItem(imageUrl: 'https://example.com/ad.png', link: 'https://example.com'),
      ]);

      expect(find.byType(Image), findsOneWidget);

      final detector = tester.widget<GestureDetector>(
        find.byKey(const Key('zocalo_ad_tap_target')),
      );
      expect(detector.onTap, isNotNull);

      await _unmount(tester);
    });

    testWidgets(
        'an ad with an image and no link still renders but is not tappable',
        (tester) async {
      await _pump(tester, const [
        AdItem(imageUrl: 'https://example.com/ad.png', link: ''),
      ]);

      // The defect being fixed: a linkless ad must still be shown, not
      // silently dropped from the carousel.
      expect(find.byType(Image), findsOneWidget);

      final detector = tester.widget<GestureDetector>(
        find.byKey(const Key('zocalo_ad_tap_target')),
      );
      expect(detector.onTap, isNull);

      await _unmount(tester);
    });

    testWidgets('close button dismisses the banner when the ad has a link',
        (tester) async {
      await _pump(tester, const [
        AdItem(imageUrl: 'https://example.com/ad.png', link: 'https://example.com'),
      ]);
      expect(find.byKey(const Key('zocalo_ad_tap_target')), findsOneWidget);

      await tester.tap(find.byKey(const Key('zocalo_ad_close_button')));
      await tester.pump();
      await tester.pump();

      expect(find.byKey(const Key('zocalo_ad_tap_target')), findsNothing);
      expect(find.byType(Image), findsNothing);
    });

    testWidgets('close button dismisses the banner when the ad has no link',
        (tester) async {
      await _pump(tester, const [
        AdItem(imageUrl: 'https://example.com/ad.png', link: ''),
      ]);
      expect(find.byKey(const Key('zocalo_ad_tap_target')), findsOneWidget);

      await tester.tap(find.byKey(const Key('zocalo_ad_close_button')));
      await tester.pump();
      await tester.pump();

      expect(find.byKey(const Key('zocalo_ad_tap_target')), findsNothing);
      expect(find.byType(Image), findsNothing);
    });

    testWidgets('an empty ad list renders nothing', (tester) async {
      await _pump(tester, const []);

      expect(find.byType(Image), findsNothing);
      expect(find.byKey(const Key('zocalo_ad_tap_target')), findsNothing);
      // No ads were loaded, so no carousel timer was ever started —
      // nothing to unmount/cancel here.
    });
  });
}

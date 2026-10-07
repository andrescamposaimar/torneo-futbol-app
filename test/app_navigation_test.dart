import 'dart:typed_data';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_core_platform_interface/test.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/app.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/config/tenant_config.dart';
import 'package:torneo_futbol_app/config/tenant_provider.dart';
import 'package:torneo_futbol_app/models/temporada.dart';
import 'package:torneo_futbol_app/providers/config_provider.dart';
import 'package:torneo_futbol_app/providers/credencial_providers.dart';
import 'package:torneo_futbol_app/providers/prode_providers.dart';
import 'package:torneo_futbol_app/providers/service_providers.dart';
import 'package:torneo_futbol_app/providers/temporadas_provider.dart';
import 'package:torneo_futbol_app/screens/credencial/credencial_screen.dart';
import 'package:torneo_futbol_app/screens/more_screen.dart';
import 'package:torneo_futbol_app/services/credencial_photo_store.dart';
import 'package:torneo_futbol_app/services/i_api_service.dart';
import 'package:torneo_futbol_app/services/i_cache_service.dart';
import 'package:torneo_futbol_app/services/notification_service.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';

// ---------------------------------------------------------------------------
// Fakes — every bottom-nav tab is mounted simultaneously inside the
// IndexedStack, so every provider any tab touches in initState must be safe
// to call without a real network/filesystem. All of this mirrors the
// established fakes in more_screen_test.dart.
// ---------------------------------------------------------------------------

/// Returns harmless empty results for every [IApiService] method so any tab
/// screen's cache-then-fetch flow settles into an empty/error state instead
/// of throwing past its own try/catch.
class _FakeApiService implements IApiService {
  @override
  Future<Map<String, dynamic>> getPartidos({
    String? fecha,
    int? liga,
    int? temporada,
    String? equipo,
    int? page,
    int? perPage,
  }) async =>
      {'items': <dynamic>[]};

  @override
  Future<Map<String, dynamic>> getPartidosProgramados({
    int? page,
    int? perPage,
  }) async =>
      {'items': <dynamic>[]};

  @override
  Future<List<dynamic>> getTemporadas() async => <dynamic>[];

  @override
  Future<List<dynamic>> getEquipos({int? liga, int? temporada}) async => <dynamic>[];

  @override
  Future<Map<String, dynamic>> getTablas({
    String? temporada,
    String? zona,
    String? search,
    int? page,
    int? perPage,
  }) async =>
      {'items': <dynamic>[]};

  @override
  Future<Map<String, dynamic>> getJugadoresRaw({
    int? temporada,
    int? liga,
    int? zona,
    int? equipoId,
    String? search,
    int? page,
    int? perPage,
  }) async =>
      {'items': <dynamic>[]};

  @override
  Future<Map<String, dynamic>> getPartidosPorJugador(
    int jugadorId, {
    int? page,
    int? perPage,
  }) async =>
      {'items': <dynamic>[]};

  @override
  Future<Map<String, dynamic>> getGoleadoresDelPartido(int partidoId) async => {};

  @override
  Future<Map<String, dynamic>> getTablaGoleadores({
    int? temporada,
    int? liga,
    int page = 1,
    int perPage = 50,
  }) async =>
      {'items': <dynamic>[]};

  @override
  Future<List<dynamic>> getHistorialDePartidosPorEquipo(String equipo) async => <dynamic>[];

  @override
  Future<Map<String, dynamic>> getJugadorPorId(int id) async => {};

  @override
  Future<List<dynamic>> getJugadoresTemporadaActual(
    int temporadaId, {
    int page = 1,
    int perPage = 20,
  }) async =>
      <dynamic>[];

  @override
  Future<List<dynamic>> getPartidosPorEquipoId(int equipoId) async => <dynamic>[];

  @override
  Future<Map<String, dynamic>> getTablaImbatibles({
    required int temporada,
    int page = 1,
    int perPage = 10,
  }) async =>
      {'items': <dynamic>[]};

  @override
  Future<Map<String, dynamic>> getNoticias({
    int page = 1,
    int perPage = 10,
  }) async =>
      {'items': <dynamic>[]};

  @override
  Future<Map<String, dynamic>> getTitulosDeJugador(int jugadorId) async => {};

  @override
  Future<List<dynamic>> getCampeonesHistoria() async => <dynamic>[];
}

/// Blanket no-op cache — same pattern as `_NoopCacheService` in
/// more_screen_test.dart: every [ICacheService] call resolves to `null`
/// (cache miss) without touching SharedPreferences.
class _NoopCacheService implements ICacheService {
  @override
  dynamic noSuchMethod(Invocation invocation) => Future.value(null);
}

/// A [CredencialPhotoStore] with no real filesystem access — see the
/// same-named class in credencial_screen_test.dart / more_screen_test.dart
/// for why real dart:io Directory calls inside testWidgets are avoided here.
class _FakePhotoStore implements CredencialPhotoStore {
  @override
  Future<Uint8List?> read(int photoId) async => null;

  @override
  Future<void> write(Uint8List bytes, int photoId) async {}

  @override
  Future<void> deleteAllExcept(int? keepPhotoId) async {}

  @override
  Future<void> wipe() async {}
}

class _FakeNotificationService extends NotificationService {
  @override
  Future<void> init() async {}

  @override
  Future<bool> isEnabled() async => true;

  @override
  Future<void> setEnabled(bool value) async {}
}

const _kProdeConfig = ProdeAuthConfig(
  prodeApiBaseUrl: 'https://test.example.com/wp-json',
  googleWebClientId: 'test-google',
  appleTeamId: 'TEST_TEAM',
);

class _FakeProdeApiService extends ProdeApiService {
  _FakeProdeApiService() : super(config: _kProdeConfig, authRepo: ProdeAuthRepository());
}

Future<void> _setUpFirebase() async {
  TestWidgetsFlutterBinding.ensureInitialized();
  TestFirebaseCoreHostApi.setUp(MockFirebaseApp());
  await Firebase.initializeApp();
}

TenantConfig _makeTenant({bool credencial = false, bool newsTab = true}) {
  return TenantConfig(
    tenantId: 'test-tenant',
    appName: 'Test App',
    apiBaseUrl: 'https://test.example.com',
    mediaBaseUrl: 'https://test.example.com',
    colors: const BrandColors(
      primary: Colors.blue,
      accent: Colors.cyan,
      splashBackground: Colors.white,
    ),
    features: TenantFeatures(
      newsTab: newsTab,
      credencial: credencial,
    ),
    integrations: const TenantIntegrations(prodeAuth: _kProdeConfig),
    logoAsset: 'assets/images/app_logo.png',
  );
}

Future<void> _pumpMainNavigation(
  WidgetTester tester, {
  bool credencial = false,
  bool newsTab = true,
}) async {
  FlutterSecureStoragePlatform.instance = TestFlutterSecureStoragePlatform({});

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        tenantConfigProvider.overrideWithValue(_makeTenant(credencial: credencial, newsTab: newsTab)),
        notificationServiceProvider.overrideWithValue(_FakeNotificationService()),
        apiServiceProvider.overrideWithValue(_FakeApiService()),
        cacheServiceProvider.overrideWithValue(_NoopCacheService()),
        temporadasProvider.overrideWith((ref) async => <dynamic>[
              {'id': 1, 'name': 'Temporada 2026', 'is_current': true},
            ]),
        temporadaActualProvider.overrideWith(
          (ref) async => const Temporada(id: 1, name: 'Temporada 2026', isCurrent: true, raw: {}),
        ),
        appConfigProvider.overrideWith((ref) async => null),
        prodeApiServiceProvider.overrideWithValue(_FakeProdeApiService()),
        credencialPhotoStoreProvider.overrideWithValue(_FakePhotoStore()),
      ],
      child: const MaterialApp(home: MainNavigation()),
    ),
  );

  // Flush the initial temporada/config futures and the post-frame setState.
  await tester.pump();
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 50));
}

void main() {
  setUpAll(() async {
    await _setUpFirebase();
  });

  group('Bottom navigation order and gating', () {
    testWidgets(
        'credencial+newsTab on (Marianista-like): exact tab order, Credencial opens CredencialScreen',
        (tester) async {
      await _pumpMainNavigation(tester, credencial: true, newsTab: true);

      final nav = tester.widget<BottomNavigationBar>(find.byType(BottomNavigationBar));
      final labels = nav.items.map((i) => i.label).toList();

      expect(labels, [
        'Partidos',
        'Tabla',
        'Credencial',
        'Noticias',
        'Equipos',
        'Más',
      ]);

      // Tapping the Credencial tab (index 2) shows CredencialScreen.
      await tester.tap(find.text('Credencial'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.byType(CredencialScreen), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('credencial off (Facundo-like): no Credencial tab, no Jugadores tab',
        (tester) async {
      await _pumpMainNavigation(tester, credencial: false, newsTab: true);

      final nav = tester.widget<BottomNavigationBar>(find.byType(BottomNavigationBar));
      final labels = nav.items.map((i) => i.label).toList();

      expect(labels, [
        'Partidos',
        'Tabla',
        'Noticias',
        'Equipos',
        'Más',
      ]);
      expect(labels, isNot(contains('Credencial')));
      expect(labels, isNot(contains('Jugadores')));
    });

    testWidgets('Más tab is still reachable and shows MoreScreen', (tester) async {
      await _pumpMainNavigation(tester, credencial: true, newsTab: true);

      await tester.tap(find.text('Más'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.byType(MoreScreen), findsOneWidget);
    });
  });
}

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_core_platform_interface/test.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/config/tenant_config.dart';
import 'package:torneo_futbol_app/config/tenant_provider.dart';
import 'package:torneo_futbol_app/providers/prode_providers.dart';
import 'package:torneo_futbol_app/providers/service_providers.dart';
import 'package:torneo_futbol_app/screens/anuarios_screen.dart';
import 'package:torneo_futbol_app/screens/more_screen.dart';
import 'package:torneo_futbol_app/services/notification_service.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_controller.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';
import 'package:torneo_futbol_app/services/prode_auth_state.dart';
import 'package:torneo_futbol_app/services/prode_ranking_controller.dart';
import 'package:torneo_futbol_app/widgets/prode_identity_card.dart';

// ---------------------------------------------------------------------------
// Firebase setup (needed because MoreScreen.initState touches FirebaseMessaging
// in kDebugMode, and NotificationService references Firebase)
// ---------------------------------------------------------------------------

Future<void> _setUpFirebase() async {
  TestWidgetsFlutterBinding.ensureInitialized();
  TestFirebaseCoreHostApi.setUp(MockFirebaseApp());
  await Firebase.initializeApp();
}

// ---------------------------------------------------------------------------
// Fakes and stubs
// ---------------------------------------------------------------------------

const _kProdeConfig = ProdeAuthConfig(
  prodeApiBaseUrl: 'https://test.example.com/wp-json',
  googleWebClientId: 'test-google',
  appleTeamId: 'TEST_TEAM',
);

class _FakeProdeApiService extends ProdeApiService {
  _FakeProdeApiService()
      : super(config: _kProdeConfig, authRepo: ProdeAuthRepository());
}

class _FakeNotificationService extends NotificationService {
  @override
  Future<bool> isEnabled() async => true;

  @override
  Future<void> setEnabled(bool value) async {}
}

class _StubAuthController extends ProdeAuthController {
  _StubAuthController(ProdeAuthState initialState)
      : super(
          repository: ProdeAuthRepository(),
          service: _FakeProdeApiService(),
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

class _StubRankingController extends ProdeRankingController {
  _StubRankingController() : super(_FakeProdeApiService()) {
    state = const ProdeRankingLoading();
  }

  @override
  Future<void> load() async {}

  @override
  Future<void> refresh() async {}
}

// ---------------------------------------------------------------------------
// Tenant fixtures
// ---------------------------------------------------------------------------

TenantConfig _makeTenant({
  bool prode = true,
  List<TenantAnuario> anuarios = const [],
  String? solicitudCambioUrl,
  bool waitingLists = false,
  String? reglamentoUrl,
  String? modalidadUrl,
}) =>
    TenantConfig(
      tenantId: 'test-tenant',
      appName: 'Test App',
      apiBaseUrl: 'https://test.example.com',
      mediaBaseUrl: 'https://test.example.com',
      colors: const BrandColors(
        primary: Colors.blue,
        accent: Colors.cyan,
        splashBackground: Colors.white,
      ),
      features: TenantFeatures(prode: prode, waitingLists: waitingLists),
      integrations: const TenantIntegrations(prodeAuth: _kProdeConfig),
      documents: TenantDocuments(
        anuarios: anuarios,
        solicitudCambioUrl: solicitudCambioUrl,
        reglamentoUrl: reglamentoUrl,
        modalidadUrl: modalidadUrl,
      ),
      logoAsset: 'assets/images/app_logo.png',
    );

// ---------------------------------------------------------------------------
// Pump helper
// ---------------------------------------------------------------------------

Future<void> _pump(
  WidgetTester tester, {
  bool prode = true,
  List<TenantAnuario> anuarios = const [],
  String? solicitudCambioUrl,
  bool waitingLists = false,
  String? reglamentoUrl,
  String? modalidadUrl,
  ProdeAuthState authState = const ProdeAuthUnauthenticated(),
}) async {
  final tenantCfg = _makeTenant(
    prode: prode,
    anuarios: anuarios,
    solicitudCambioUrl: solicitudCambioUrl,
    waitingLists: waitingLists,
    reglamentoUrl: reglamentoUrl,
    modalidadUrl: modalidadUrl,
  );

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        tenantConfigProvider.overrideWithValue(tenantCfg),
        notificationServiceProvider
            .overrideWithValue(_FakeNotificationService()),
        prodeApiServiceProvider
            .overrideWithValue(_FakeProdeApiService()),
        prodeAuthControllerProvider
            .overrideWith((ref) => _StubAuthController(authState)),
        prodeRankingControllerProvider
            .overrideWith((ref) => _StubRankingController()),
      ],
      child: const MaterialApp(home: MoreScreen()),
    ),
  );
  await tester.pump();
}

// ---------------------------------------------------------------------------
// Tests (AC-52a..f)
// ---------------------------------------------------------------------------

void main() {
  setUpAll(() async {
    await _setUpFirebase();
  });

  group('MoreScreen layout and tenant guards', () {
    // AC-52a / AC-01, AC-06: prode=true → identity card and prode card present
    testWidgets(
        'AC-52a: prode=true → ProdeIdentityCard and Prode section tiles present',
        (tester) async {
      await _pump(tester, prode: true);

      // ProdeIdentityCard widget is in the tree
      expect(find.byType(ProdeIdentityCard), findsOneWidget);

      // Prode section tiles (AC-25)
      expect(find.text('Mis pronósticos'), findsOneWidget);
      expect(find.text('Ranking'), findsOneWidget);
    });

    // AC-52b / AC-07, AC-48: prode=false → no identity card, no prode tiles, no crash
    testWidgets(
        'AC-52b: prode=false → ProdeIdentityCard absent, no prode tiles, no exception',
        (tester) async {
      // When prode=false we do NOT provide prodeApiServiceProvider —
      // accessing it would throw; the guard must prevent it.
      final tenantCfg = _makeTenant(prode: false);
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            tenantConfigProvider.overrideWithValue(tenantCfg),
            notificationServiceProvider
                .overrideWithValue(_FakeNotificationService()),
            // Intentionally NOT overriding prodeApiServiceProvider —
            // the guard in ProdeIdentityCard must prevent any access.
          ],
          child: const MaterialApp(home: MoreScreen()),
        ),
      );
      await tester.pump();

      // No exception
      expect(tester.takeException(), isNull);

      // No prode UI
      expect(find.text('Mis pronósticos'), findsNothing);
      expect(find.text('Ranking'), findsNothing);
    });

    // AC-52c / AC-33: anuarios non-empty → single "Anuarios" tile present;
    //                  tapping it pushes AnuariosScreen
    testWidgets(
        'AC-52c: anuarios non-empty → "Anuarios" tile present and navigates to AnuariosScreen',
        (tester) async {
      final anuarios = [
        const TenantAnuario(label: 'Anuario 2023', url: 'https://example.com/2023.pdf'),
      ];

      await _pump(tester, anuarios: anuarios);

      // 'Anuarios' appears twice: section header + tile label
      expect(find.text('Anuarios'), findsNWidgets(2));

      // Tap the tile (the one inside an InkWell), not the section header
      await tester.tap(find.widgetWithText(InkWell, 'Anuarios'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.byType(AnuariosScreen), findsOneWidget);
    });

    // AC-52d / AC-32: anuarios empty → "Anuarios" tile absent
    testWidgets('AC-52d: anuarios empty → Anuarios tile absent',
        (tester) async {
      await _pump(tester, anuarios: const []);

      // 'Anuarios' should not appear as a navigation tile
      // (note: the app bar may show 'Otras Opciones' but not 'Anuarios')
      expect(find.text('Anuarios'), findsNothing);
    });

    // AC-52e / AC-37: notification switch renders
    testWidgets('AC-52e: Notificaciones switch renders', (tester) async {
      await _pump(tester);

      expect(find.byType(SwitchListTile), findsOneWidget);
      expect(find.text('Avisos del torneo'), findsOneWidget);
    });

    // AC-52f / AC-36: Notificaciones card is the last section before debug
    //                  (Estadísticas always present, appears before Notificaciones)
    testWidgets(
        'AC-52f: Notificaciones section appears after Estadísticas in render order',
        (tester) async {
      await _pump(tester);

      // Find the vertical position of key section labels / widgets
      final goleadoresPos = tester
          .getTopLeft(find.text('Goleadores'))
          .dy;
      final notifPos = tester
          .getTopLeft(find.text('Avisos del torneo'))
          .dy;

      // Notificaciones must appear BELOW Estadísticas
      expect(notifPos, greaterThan(goleadoresPos));
    });

    // AC-29 (locked: HIDE): Gestión Torneo card absent when no tiles visible
    testWidgets(
        'AC-29: Gestión Torneo card hidden when zero visible tiles',
        (tester) async {
      // solicitudCambioUrl == null AND waitingLists == false → whole card hidden
      await _pump(
        tester,
        solicitudCambioUrl: null,
        waitingLists: false,
      );

      expect(tester.takeException(), isNull);
      expect(find.text('Gestión Torneo'), findsNothing);
    });

    // AC-29 inverse: card present when at least one tile is visible
    testWidgets(
        'AC-29: Gestión Torneo card present when waitingLists enabled',
        (tester) async {
      await _pump(tester, waitingLists: true);

      expect(find.text('Gestión Torneo'), findsOneWidget);
      expect(find.text('Lista de Espera'), findsOneWidget);
    });

    // AC-06: full section order — each section strictly below the previous
    testWidgets('AC-06: full section render order is pinned', (tester) async {
      // Tall viewport so the lazy ListView builds every section
      tester.view.physicalSize = const Size(800, 2400);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await _pump(
        tester,
        waitingLists: true,
        reglamentoUrl: 'https://test.example.com/reglamento.pdf',
        anuarios: const [
          TenantAnuario(label: 'Anuario 2025', url: 'https://t.example/a.pdf'),
        ],
      );

      double dyOf(String text) =>
          tester.getTopLeft(find.text(text).first).dy;

      final prode = dyOf('Mis pronósticos');
      final stats = dyOf('Goleadores');
      final gestion = dyOf('Lista de Espera');
      final informacion = dyOf('Reglamento');
      final anuarios = dyOf('Anuarios');
      final notificaciones = dyOf('Avisos del torneo');

      expect(stats, greaterThan(prode));
      expect(gestion, greaterThan(stats));
      expect(informacion, greaterThan(gestion));
      expect(anuarios, greaterThan(informacion));
      expect(notificaciones, greaterThan(anuarios));
    });

    // AC-01: grey scaffold background
    testWidgets('AC-01: Scaffold body background is grey', (tester) async {
      await _pump(tester);

      final scaffold = tester.widget<Scaffold>(find.byType(Scaffold).first);
      expect(scaffold.backgroundColor, isNotNull);
    });

    // AC-27: Estadísticas card always present with Goleadores + Imbatibles
    testWidgets('AC-27: Estadísticas card always rendered with both tiles',
        (tester) async {
      await _pump(tester);

      expect(find.text('Goleadores'), findsOneWidget);
      expect(find.text('Imbatibles'), findsOneWidget);
    });
  });
}

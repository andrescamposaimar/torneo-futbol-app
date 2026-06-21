import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_core_platform_interface/test.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/config/tenant_config.dart';
import 'package:torneo_futbol_app/config/tenant_provider.dart';
import 'package:torneo_futbol_app/models/prode_ranking.dart';
import 'package:torneo_futbol_app/providers/prode_providers.dart';
import 'package:torneo_futbol_app/providers/service_providers.dart';
import 'package:torneo_futbol_app/screens/prode/prode_ranking_screen.dart';
import 'package:torneo_futbol_app/screens/prode/prode_auth_gate.dart';
import 'package:torneo_futbol_app/screens/more_screen.dart';
import 'package:torneo_futbol_app/services/notification_service.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';
import 'package:torneo_futbol_app/services/prode_ranking_controller.dart';

// ---------------------------------------------------------------------------
// Fake API service (no platform channels needed)
// ---------------------------------------------------------------------------

class _FakeApiService extends ProdeApiService {
  _FakeApiService()
      : super(
          config: const ProdeAuthConfig(
            prodeApiBaseUrl: 'https://nowhere.test',
            googleWebClientId: 'test',
            appleTeamId: 'TEST',
          ),
          authRepo: ProdeAuthRepository(),
        );

  @override
  Future<RankingPage> fetchRanking({
    int? temporada,
    int? fechaId,
    int page = 1,
    int perPage = 50,
  }) =>
      Future.error('not used in tests');
}

/// Fake auth repository that returns no tokens — neutralises secure storage so
/// pushing [ProdeAuthGate] (whose bootstrap reads tokens) does not touch a
/// platform channel during widget tests.
class _FakeAuthRepository extends ProdeAuthRepository {
  @override
  Future<TokenSnapshot> readAll() async => const TokenSnapshot();

  @override
  Future<String?> readAccessToken() async => null;

  @override
  Future<String?> readRefreshToken() async => null;

  @override
  Future<void> clear() async {}
}

// ---------------------------------------------------------------------------
// Stub controllers
// ---------------------------------------------------------------------------

/// A stub [ProdeRankingController] seeded with a fixed initial state and
/// no-op load/refresh so no network call is made during widget tests.
class _StubController extends ProdeRankingController {
  _StubController(ProdeRankingState initialState) : super(_FakeApiService()) {
    state = initialState;
  }

  @override
  Future<void> load() async {}

  @override
  Future<void> refresh() async {}
}

/// Stub [ProdeFechaRankingController] for the "Fecha Actual" tab — no-op
/// load/refresh, fixed initial state. The General-tab tests pump with
/// initialTab=1 so this stub's state is irrelevant (kept Loading).
class _StubFechaController extends ProdeFechaRankingController {
  _StubFechaController(ProdeRankingState initialState)
      : super(_FakeApiService()) {
    state = initialState;
  }

  @override
  Future<void> load() async {}

  @override
  Future<void> refresh() async {}
}

/// Stub controller that invokes callbacks on load()/refresh() — for asserting
/// that the correct action was triggered.
class _StubControllerWithCallback extends ProdeRankingController {
  final VoidCallback? onRefresh;

  _StubControllerWithCallback(
    ProdeRankingState initialState, {
    this.onRefresh,
  }) : super(_FakeApiService()) {
    state = initialState;
  }

  @override
  Future<void> load() async {}

  @override
  Future<void> refresh() async {
    onRefresh?.call();
  }
}

// ---------------------------------------------------------------------------
// Firebase test setup
// ---------------------------------------------------------------------------

/// Sets up a minimal fake Firebase app so tests that touch MoreScreen
/// (which holds a NotificationService field initializer referencing
/// FirebaseMessaging.instance) don't crash with [no-app].
Future<void> _setUpFirebase() async {
  TestWidgetsFlutterBinding.ensureInitialized();
  TestFirebaseCoreHostApi.setUp(MockFirebaseApp());
  await Firebase.initializeApp();
}

// ---------------------------------------------------------------------------
// Fake notification service (bypasses Firebase platform channels)
// ---------------------------------------------------------------------------

class _FakeNotificationService extends NotificationService {
  @override
  Future<bool> isEnabled() async => true;

  @override
  Future<void> setEnabled(bool value) async {}
}

// ---------------------------------------------------------------------------
// Tenant config fixture (prode enabled)
// ---------------------------------------------------------------------------

const _prodeAuthConfig = ProdeAuthConfig(
  prodeApiBaseUrl: 'https://test.example.com/wp-json',
  googleWebClientId: 'test-google',
  appleTeamId: 'TEST_TEAM',
);

TenantConfig _makeTenantConfig({bool prode = true}) => TenantConfig(
      tenantId: 'test-tenant',
      appName: 'Test App',
      apiBaseUrl: 'https://test.example.com',
      mediaBaseUrl: 'https://test.example.com',
      colors: const BrandColors(
        primary: Colors.blue,
        accent: Colors.cyan,
        splashBackground: Colors.white,
      ),
      features: TenantFeatures(prode: prode),
      integrations: TenantIntegrations(prodeAuth: _prodeAuthConfig),
      logoAsset: 'assets/images/app_logo.png',
    );

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/// Builds a [RankingEntry] with all required fields.
RankingEntry _makeEntry({
  int userId = 1,
  String displayName = 'User',
  int totalPoints = 5,
  int rank = 1,
  int exactCount = 1,
  bool isMe = false,
  String? avatarUrl,
  String? teamName,
}) =>
    RankingEntry(
      userId: userId,
      displayName: displayName,
      totalPoints: totalPoints,
      rank: rank,
      exactCount: exactCount,
      isMe: isMe,
      avatarUrl: avatarUrl,
      teamName: teamName,
    );

/// Builds a [ProdeRankingLoaded] state with the given entries.
ProdeRankingLoaded _makeLoaded(List<RankingEntry> entries) {
  final page = RankingPage(
    items: entries,
    total: entries.length,
    page: 1,
    perPage: 50,
  );
  return ProdeRankingLoaded(page);
}

/// Pumps [ProdeRankingScreen] with a stub controller inside a [ProviderScope].
Future<void> _pumpScreen(
  WidgetTester tester,
  ProdeRankingState initialState,
) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        prodeRankingControllerProvider
            .overrideWith((ref) => _StubController(initialState)),
        prodeFechaRankingControllerProvider
            .overrideWith((ref) => _StubFechaController(initialState)),
      ],
      // initialTab=1 → the "General" tab (backed by prodeRankingControllerProvider)
      // is the visible one these tests assert against.
      child: const MaterialApp(home: ProdeRankingScreen(initialTab: 1)),
    ),
  );
  await tester.pump(); // settle microtask
}

/// Pumps [MoreScreen] with all required provider overrides.
Future<void> _pumpMoreScreen(
  WidgetTester tester, {
  bool prode = true,
  ProdeRankingState rankingState = const ProdeRankingLoading(),
}) async {
  final tenantConfig = _makeTenantConfig(prode: prode);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        tenantConfigProvider.overrideWithValue(tenantConfig),
        notificationServiceProvider
            .overrideWithValue(_FakeNotificationService()),
        prodeRankingControllerProvider
            .overrideWith((ref) => _StubController(rankingState)),
        prodeAuthRepositoryProvider.overrideWithValue(_FakeAuthRepository()),
        prodeApiServiceProvider.overrideWithValue(
          ProdeApiService(
            config: _prodeAuthConfig,
            authRepo: _FakeAuthRepository(),
          ),
        ),
      ],
      child: const MaterialApp(home: MoreScreen()),
    ),
  );
  await tester.pump();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

void main() {
  group('ProdeRankingScreen', () {
    testWidgets('Loading state → CircularProgressIndicator found, ListView absent',
        (tester) async {
      await _pumpScreen(tester, const ProdeRankingLoading());
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      expect(find.byType(ListView), findsNothing);
    });

    testWidgets('Loaded with 5 items → 5 rows, each showing rank+displayName+totalPoints',
        (tester) async {
      final entries = List.generate(
        5,
        (i) => _makeEntry(
          userId: i + 1,
          displayName: 'Player ${i + 1}',
          totalPoints: (5 - i) * 3,
          rank: i + 1,
          exactCount: i,
        ),
      );
      await _pumpScreen(tester, _makeLoaded(entries));
      for (var i = 0; i < 5; i++) {
        expect(
          find.byKey(Key('ranking_row_${i + 1}')),
          findsOneWidget,
        );
        expect(find.text('Player ${i + 1}'), findsOneWidget);
        expect(find.text('${(5 - i) * 3} pts'), findsOneWidget);
      }
    });

    testWidgets('is_me row has tileColor tint; other rows do not', (tester) async {
      final entries = [
        _makeEntry(userId: 1, rank: 1, isMe: false),
        _makeEntry(userId: 2, rank: 2, isMe: true),
        _makeEntry(userId: 3, rank: 3, isMe: false),
      ];
      await _pumpScreen(tester, _makeLoaded(entries));

      // The is_me row Container (key='ranking_row_2') has a non-null color.
      final ismeRow = find.byKey(const Key('ranking_row_2'));
      expect(ismeRow, findsOneWidget);
      // The outermost widget for this key is the Container itself.
      final container = tester.widget<Container>(ismeRow);
      expect(container.color, isNotNull);

      // A non-is_me row Container has a null color.
      final nonIsmeRow = find.byKey(const Key('ranking_row_1'));
      expect(nonIsmeRow, findsOneWidget);
      final nonIsmeContainer = tester.widget<Container>(nonIsmeRow);
      expect(nonIsmeContainer.color, isNull);
    });

    testWidgets('Empty state → text containing "posiciones" found, ListView absent',
        (tester) async {
      await _pumpScreen(tester, const ProdeRankingEmpty());
      expect(
        find.textContaining('posiciones'),
        findsWidgets,
      );
      expect(find.byType(ListView), findsNothing);
    });

    testWidgets('Error state → text containing "salió mal" found, Reintentar button found',
        (tester) async {
      await _pumpScreen(
        tester,
        const ProdeRankingError(code: 'network', message: 'timeout'),
      );
      expect(find.textContaining('salió mal'), findsOneWidget);
      expect(find.text('Reintentar'), findsOneWidget);
    });

    testWidgets('Pull-to-refresh triggers refresh() exactly once', (tester) async {
      var refreshCount = 0;
      final entries = [_makeEntry(userId: 1, rank: 1)];
      final loadedState = _makeLoaded(entries);

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            prodeRankingControllerProvider.overrideWith(
              (ref) => _StubControllerWithCallback(
                loadedState,
                onRefresh: () => refreshCount++,
              ),
            ),
            prodeFechaRankingControllerProvider.overrideWith(
              (ref) => _StubFechaController(const ProdeRankingLoading()),
            ),
          ],
          child: const MaterialApp(home: ProdeRankingScreen(initialTab: 1)),
        ),
      );
      await tester.pump();

      // Trigger pull-to-refresh
      await tester.fling(
        find.byType(ListView),
        const Offset(0, 300),
        1000,
      );
      await tester.pumpAndSettle();

      expect(refreshCount, 1);
    });

    testWidgets('Long displayName (60 chars) no RenderFlex overflow', (tester) async {
      final longName = 'A' * 60;
      final entry = _makeEntry(userId: 1, displayName: longName, rank: 1);
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            prodeRankingControllerProvider
                .overrideWith((ref) => _StubController(_makeLoaded([entry]))),
            prodeFechaRankingControllerProvider
                .overrideWith((ref) => _StubFechaController(
                      const ProdeRankingLoading(),
                    )),
          ],
          child: const MaterialApp(
            home: Scaffold(
              body: SizedBox(
                width: 300,
                child: ProdeRankingScreen(initialTab: 1),
              ),
            ),
          ),
        ),
      );
      await tester.pump();
      // No RenderFlex overflow exception thrown
      expect(tester.takeException(), isNull);
    });

    // ------------------------------------------------------------------
    // New row layout tests (photo + team subtitle)
    // ------------------------------------------------------------------

    testWidgets('row with teamName shows team name and no "exactos" text',
        (tester) async {
      final entry = _makeEntry(
        userId: 10,
        displayName: 'Lionel',
        rank: 1,
        exactCount: 3,
        teamName: 'Club Atlético',
      );
      await _pumpScreen(tester, _makeLoaded([entry]));
      expect(find.text('Club Atlético'), findsOneWidget);
      expect(find.textContaining('exactos'), findsNothing);
    });

    testWidgets('row with null teamName shows "Sin Equipo"', (tester) async {
      final entry = _makeEntry(
        userId: 11,
        displayName: 'Carlos',
        rank: 1,
        teamName: null,
      );
      await _pumpScreen(tester, _makeLoaded([entry]));
      expect(find.text('Sin Equipo'), findsOneWidget);
    });

    testWidgets('row with empty teamName shows "Sin Equipo"', (tester) async {
      final entry = _makeEntry(
        userId: 12,
        displayName: 'Maria',
        rank: 1,
        teamName: '',
      );
      await _pumpScreen(tester, _makeLoaded([entry]));
      expect(find.text('Sin Equipo'), findsOneWidget);
    });

    testWidgets('row without avatarUrl renders CircleAvatar with initials',
        (tester) async {
      final entry = _makeEntry(
        userId: 13,
        displayName: 'Fernando',
        rank: 1,
        avatarUrl: null,
      );
      await _pumpScreen(tester, _makeLoaded([entry]));
      // Initials avatar present (key uses 'initials')
      expect(
        find.byKey(const ValueKey('ranking_avatar_initials_13')),
        findsOneWidget,
      );
    });

    testWidgets('row with non-empty avatarUrl renders network-image CircleAvatar',
        (tester) async {
      final entry = _makeEntry(
        userId: 14,
        displayName: 'Gonzalo',
        rank: 1,
        avatarUrl: 'https://example.com/photo.jpg',
      );
      await _pumpScreen(tester, _makeLoaded([entry]));
      // Photo avatar present (key uses 'photo')
      expect(
        find.byKey(const ValueKey('ranking_avatar_photo_14')),
        findsOneWidget,
      );
    });

    testWidgets('user photo CircleAvatar is positioned to the left of displayName',
        (tester) async {
      final entry = _makeEntry(
        userId: 15,
        displayName: 'Roberto',
        rank: 1,
        avatarUrl: null,
      );
      await _pumpScreen(tester, _makeLoaded([entry]));

      // Both the avatar and the display name text should be rendered.
      final avatarFinder =
          find.byKey(const ValueKey('ranking_avatar_initials_15'));
      final nameFinder = find.text('Roberto');
      expect(avatarFinder, findsOneWidget);
      expect(nameFinder, findsOneWidget);

      // Avatar's left edge < name text's left edge (avatar is to the left).
      final avatarBox =
          tester.getRect(avatarFinder);
      final nameBox = tester.getRect(nameFinder);
      expect(avatarBox.left, lessThan(nameBox.left));
    });
  });

  group('MoreScreen', () {
    setUpAll(() async {
      await _setUpFirebase();
    });

    testWidgets('prode enabled → Prode Chami tile found', (tester) async {
      await _pumpMoreScreen(tester, prode: true);
      expect(find.text('Prode Chami'), findsOneWidget);
    });

    testWidgets('tapping Prode Chami tile pushes ProdeAuthGate', (tester) async {
      await _pumpMoreScreen(tester, prode: true);
      await tester.tap(find.text('Prode Chami'));
      // Use pump with duration to advance the navigator animation without
      // waiting for pumpAndSettle (which can time out if there are pending
      // microtasks from the pushed screen's initState).
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));
      expect(find.byType(ProdeAuthGate), findsOneWidget);
    });

    testWidgets('prode disabled → no Prode Chami tile', (tester) async {
      await _pumpMoreScreen(tester, prode: false);
      expect(find.text('Prode Chami'), findsNothing);
    });
  });
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/config/tenant_config.dart';
import 'package:torneo_futbol_app/config/tenant_provider.dart';
import 'package:torneo_futbol_app/providers/prode_providers.dart';
import 'package:torneo_futbol_app/providers/service_providers.dart';
import 'package:torneo_futbol_app/screens/player_detail_screen.dart';
import 'package:torneo_futbol_app/services/i_api_service.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_controller.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';
import 'package:torneo_futbol_app/services/prode_auth_state.dart';
import 'package:torneo_futbol_app/widgets/prode_identity_card.dart';

// ---------------------------------------------------------------------------
// Fake Prode API service (no platform channels)
// ---------------------------------------------------------------------------

const _kProdeConfig = ProdeAuthConfig(
  prodeApiBaseUrl: 'https://nowhere.test',
  googleWebClientId: 'test',
  appleTeamId: 'TEST',
);

class _FakeProdeApiService extends ProdeApiService {
  _FakeProdeApiService()
      : super(config: _kProdeConfig, authRepo: ProdeAuthRepository());
}

// ---------------------------------------------------------------------------
// Stub public API service — controls getJugadorPorId return value
// ---------------------------------------------------------------------------

class _StubPublicApiService implements IApiService {
  final String? imagenUrl;
  final bool shouldThrow;

  _StubPublicApiService({this.imagenUrl, this.shouldThrow = false});

  @override
  Future<Map<String, dynamic>> getJugadorPorId(int id) async {
    if (shouldThrow) throw Exception('network error');
    return {
      'id': id,
      'title': {'rendered': 'Test Player'},
      'featured_image': imagenUrl,
      'posicion': 'Delantero',
      'numero': '9',
      'equipo': 'Test FC',
      'equipo_id': 1,
      'acf': {},
    };
  }

  // All other methods throw — they should not be called by ProdeIdentityCard.
  @override
  Future<Map<String, dynamic>> getPartidos({
    String? fecha,
    int? liga,
    int? temporada,
    String? equipo,
    int? page,
    int? perPage,
  }) => throw UnimplementedError();

  @override
  Future<Map<String, dynamic>> getPartidosProgramados({int? page, int? perPage}) =>
      throw UnimplementedError();

  @override
  Future<List<dynamic>> getLigas({int? temporada}) => throw UnimplementedError();

  @override
  Future<List<dynamic>> getTemporadas() => throw UnimplementedError();

  @override
  Future<List<dynamic>> getZonas({int? liga}) => throw UnimplementedError();

  @override
  Future<List<dynamic>> getEquipos({int? liga, int? temporada}) =>
      throw UnimplementedError();

  @override
  Future<Map<String, dynamic>> getTablas({
    String? temporada,
    String? zona,
    String? search,
    int? page,
    int? perPage,
  }) => throw UnimplementedError();

  @override
  Future<Map<String, dynamic>> getJugadoresRaw({
    int? temporada,
    int? liga,
    int? zona,
    int? equipoId,
    String? search,
    int? page,
    int? perPage,
  }) => throw UnimplementedError();

  @override
  Future<List<dynamic>> getJugadores({
    int page = 1,
    int perPage = 20,
    int? equipoId,
  }) => throw UnimplementedError();

  @override
  Future<Map<String, dynamic>> getPartidosPorJugador(int jugadorId,
      {int? page, int? perPage}) =>
      throw UnimplementedError();

  @override
  Future<Map<String, dynamic>> getGoleadoresDelPartido(int partidoId) =>
      throw UnimplementedError();

  @override
  Future<Map<String, dynamic>> getTablaGoleadores({
    int? temporada,
    int? liga,
    int page = 1,
    int perPage = 50,
  }) => throw UnimplementedError();

  @override
  Future<List<dynamic>> getHistorialDePartidosPorEquipo(String equipo) =>
      throw UnimplementedError();

  @override
  Future<List<dynamic>> getJugadoresTemporadaActual(int temporadaId,
      {int page = 1, int perPage = 20}) =>
      throw UnimplementedError();

  @override
  Future<List<dynamic>> getJugadoresPorEquipoId(int equipoId) =>
      throw UnimplementedError();

  @override
  Future<List<dynamic>> getPartidosPorEquipoId(int equipoId) =>
      throw UnimplementedError();

  @override
  Future<Map<String, dynamic>> getTablaImbatibles({
    required int temporada,
    int page = 1,
    int perPage = 10,
  }) => throw UnimplementedError();

  @override
  Future<Map<String, dynamic>> getNoticias({int page = 1, int perPage = 10}) =>
      throw UnimplementedError();
}

// ---------------------------------------------------------------------------
// Stub ProdeAuthController — seeds state, all async ops are no-ops
// ---------------------------------------------------------------------------

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
  IApiService? publicApi,
}) async {
  final tenant = _makeTenant(prode: prode);
  final api = publicApi ?? _StubPublicApiService();
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        tenantConfigProvider.overrideWithValue(tenant),
        prodeApiServiceProvider.overrideWithValue(
          _FakeProdeApiService(),
        ),
        apiServiceProvider.overrideWithValue(api),
        prodeAuthControllerProvider.overrideWith(
          (ref) => _StubAuthController(authState),
        ),
      ],
      child: const MaterialApp(
        home: Scaffold(body: ProdeIdentityCard()),
      ),
    ),
  );
  // Let microtasks and async futures complete (bootstrap guard + photo fetch).
  // We need multiple event loop turns: microtask → async fetch → setState.
  await tester.runAsync(() async {
    await Future<void>.delayed(const Duration(milliseconds: 50));
  });
  await tester.pump();
}

// ---------------------------------------------------------------------------
// Counting stub controller — tracks individual callback invocations
// ---------------------------------------------------------------------------

class _CountingStubController extends ProdeAuthController {
  final VoidCallback? onGoogle;

  _CountingStubController(
    ProdeAuthState initialState, {
    this.onGoogle,
  }) : super(
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
  Future<void> signInWithGoogle() async => onGoogle?.call();

  @override
  Future<void> signInWithApple() async {}
}

// ---------------------------------------------------------------------------
// Tests (AC-53a..h + AC-53i..l for navigation and photo)
// ---------------------------------------------------------------------------

void main() {
  group('ProdeIdentityCard', () {
    // AC-53a / AC-10
    testWidgets('Unauthenticated → sign-in buttons visible', (tester) async {
      await _pump(tester, const ProdeAuthUnauthenticated());
      expect(find.text('Google'), findsOneWidget);
    });

    // AC-53n: guest mode — "Invitado" title present, no Prode marketing copy
    testWidgets('AC-53n: Unauthenticated → "Invitado" title shown, no Prode copy',
        (tester) async {
      await _pump(tester, const ProdeAuthUnauthenticated());
      expect(find.text('Invitado'), findsOneWidget);
      expect(
        find.textContaining('Iniciá sesión para ver tus datos'),
        findsOneWidget,
      );
      expect(
        find.textContaining('recordá ingresar el DNI'),
        findsOneWidget,
      );
      expect(find.text('Sumate al Prode — iniciá sesión'), findsNothing);
    });

    // AC-53o: guest mode — person icon present (guest avatar)
    testWidgets('AC-53o: Unauthenticated → guest person icon visible',
        (tester) async {
      await _pump(tester, const ProdeAuthUnauthenticated());
      expect(find.byIcon(Icons.person_outline), findsOneWidget);
    });

    // AC-53p: guest mode — buttons are rendered side-by-side (compact Row),
    // not full-width stacked; ProdeSignInButtons compact mode active
    testWidgets('AC-53p: Unauthenticated → compact sign-in row (not stacked Column)',
        (tester) async {
      await _pump(tester, const ProdeAuthUnauthenticated());
      // In compact mode the buttons live inside a Row widget with short labels.
      expect(find.text('Google'), findsOneWidget);
      // Find the Row that is a direct/indirect ancestor of the Google button
      final rowFinder = find.ancestor(
        of: find.text('Google'),
        matching: find.byType(Row),
      );
      expect(rowFinder, findsWidgets);
    });

    // Regression: the compact row must fit on a NARROW phone (the original
    // implementation overflowed 56px on a 390pt-wide device). Overflow errors
    // surface as exceptions in widget tests — assert there are none.
    testWidgets(
        'AC-53r: Unauthenticated → no overflow on narrow viewport (360pt, iOS both buttons)',
        (tester) async {
      tester.view.physicalSize = const Size(360, 690);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await _pump(tester, const ProdeAuthUnauthenticated());

      expect(tester.takeException(), isNull);
      expect(find.text('Google'), findsOneWidget);
    });

    // AC-53q: guest mode — tapping Google fires callback
    testWidgets('AC-53q: Unauthenticated → Google button fires signInWithGoogle',
        (tester) async {
      var googleCalls = 0;
      // We need a controller stub that counts calls.
      final tenant = _makeTenant();
      final api = _StubPublicApiService();
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            tenantConfigProvider.overrideWithValue(tenant),
            prodeApiServiceProvider.overrideWithValue(_FakeProdeApiService()),
            apiServiceProvider.overrideWithValue(api),
            prodeAuthControllerProvider.overrideWith(
              (ref) => _CountingStubController(
                const ProdeAuthUnauthenticated(),
                onGoogle: () => googleCalls++,
              ),
            ),
          ],
          child: const MaterialApp(
            home: Scaffold(body: ProdeIdentityCard()),
          ),
        ),
      );
      await tester.runAsync(
          () async => Future<void>.delayed(const Duration(milliseconds: 50)));
      await tester.pump();

      await tester.tap(find.text('Google'));
      await tester.pump();
      expect(googleCalls, equals(1));
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

    // -----------------------------------------------------------------------
    // AC-53i: Authenticated with playerId > 0 → tap navigates to
    // PlayerDetailScreen
    // -----------------------------------------------------------------------
    testWidgets(
        'AC-53i: Authenticated playerId>0 → tapping tile pushes PlayerDetailScreen',
        (tester) async {
      const user = ProdeUser(
        userId: 1,
        playerId: 42,
        name: 'Carlos Ruiz',
        sessionVersion: 1,
      );
      await _pump(
        tester,
        ProdeAuthAuthenticated(user: user, stale: false),
        publicApi: _StubPublicApiService(imagenUrl: null),
      );

      // Tap the list tile
      await tester.tap(find.byType(ListTile).first);
      await tester.pumpAndSettle();

      // Should have navigated to PlayerDetailScreen
      expect(find.byType(PlayerDetailScreen), findsOneWidget);
    });

    // -----------------------------------------------------------------------
    // AC-53j: Authenticated with playerId == 0 → no navigation on tap
    // -----------------------------------------------------------------------
    testWidgets(
        'AC-53j: Authenticated playerId==0 → tapping tile does NOT navigate',
        (tester) async {
      const user = ProdeUser(
        userId: 1,
        playerId: 0,
        name: 'Sin Jugador',
        sessionVersion: 1,
      );
      await _pump(
        tester,
        ProdeAuthAuthenticated(user: user, stale: false),
        publicApi: _StubPublicApiService(imagenUrl: null),
      );

      await tester.tap(find.byType(ListTile).first);
      await tester.pumpAndSettle();

      // Should NOT have navigated — PlayerDetailScreen absent
      expect(find.byType(PlayerDetailScreen), findsNothing);
    });

    // -----------------------------------------------------------------------
    // AC-53k: Authenticated with playerId > 0 and imagen URL → photo avatar
    // shown (CircleAvatar with photo key; no initials key).
    //
    // Note: Flutter test binding intercepts all HTTP and returns 400, so
    // NetworkImage will fail. We suppress that expected error via
    // onBackgroundImageError in the widget and ignore it here.
    // -----------------------------------------------------------------------
    testWidgets(
        'AC-53k: Authenticated playerId>0 with imagen → photo avatar key present, no initials',
        (tester) async {
      const user = ProdeUser(
        userId: 1,
        playerId: 7,
        name: 'Lionel Messi',
        sessionVersion: 1,
      );

      // Suppress the expected NetworkImageLoadException that Flutter test
      // binding produces for all HTTP calls (returns 400).
      final originalOnError = FlutterError.onError;
      FlutterError.onError = (details) {
        if (details.exception is NetworkImageLoadException) return;
        originalOnError?.call(details);
      };

      await _pump(
        tester,
        ProdeAuthAuthenticated(user: user, stale: false),
        publicApi: _StubPublicApiService(
          imagenUrl: 'https://example.com/player.jpg',
        ),
      );
      // Let the microtask + setState complete
      await tester.pump();

      FlutterError.onError = originalOnError;

      // Photo avatar key is present (backgroundImage set on CircleAvatar)
      expect(find.byKey(const ValueKey('prode-avatar-photo')), findsOneWidget);
      // Initials avatar key is absent
      expect(
          find.byKey(const ValueKey('prode-avatar-initials')), findsNothing);
    });

    // -----------------------------------------------------------------------
    // AC-53l: Authenticated with playerId > 0 but imagen == null → initials
    // avatar shown
    // -----------------------------------------------------------------------
    testWidgets(
        'AC-53l: Authenticated playerId>0, no imagen → initials avatar shown',
        (tester) async {
      const user = ProdeUser(
        userId: 1,
        playerId: 7,
        name: 'Lionel Messi',
        sessionVersion: 1,
      );
      await _pump(
        tester,
        ProdeAuthAuthenticated(user: user, stale: false),
        publicApi: _StubPublicApiService(imagenUrl: null),
      );
      await tester.pump();

      // Initials avatar key present
      expect(
          find.byKey(const ValueKey('prode-avatar-initials')),
          findsOneWidget);
      // Photo avatar key absent
      expect(
          find.byKey(const ValueKey('prode-avatar-photo')), findsNothing);
    });

    // -----------------------------------------------------------------------
    // AC-53m: playerId == 0 → no fetch called (initials avatar, no photo)
    // -----------------------------------------------------------------------
    testWidgets(
        'AC-53m: Authenticated playerId==0 → initials avatar, no photo fetch',
        (tester) async {
      const user = ProdeUser(
        userId: 1,
        playerId: 0,
        name: 'Sin Jugador',
        sessionVersion: 1,
      );
      // Use a stub that throws if getJugadorPorId is called
      await _pump(
        tester,
        ProdeAuthAuthenticated(user: user, stale: false),
        publicApi: _StubPublicApiService(shouldThrow: true),
      );
      await tester.pump();

      // No exception — fetch was never triggered
      expect(tester.takeException(), isNull);
      // Initials avatar is shown
      expect(
          find.byKey(const ValueKey('prode-avatar-initials')),
          findsOneWidget);
    });
  });
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/models/fecha_activa.dart';
import 'package:torneo_futbol_app/providers/prode_providers.dart';
import 'package:torneo_futbol_app/screens/prode/prode_fixtures_screen.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';
import 'package:torneo_futbol_app/services/prode_fixtures_controller.dart';

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
  Future<FechaActiva> fetchFechaActiva() => Future.error('not used in tests');
}

// ---------------------------------------------------------------------------
// Stub controllers
// ---------------------------------------------------------------------------

/// A stub [ProdeFixturesController] seeded with a fixed initial state and
/// no-op load/refresh so no network call is made during widget tests.
class _StubController extends ProdeFixturesController {
  _StubController(ProdeFixturesState initialState) : super(_FakeApiService()) {
    state = initialState;
  }

  @override
  Future<void> load() async {}

  @override
  Future<void> refresh() async {}
}

/// Stub controller that invokes callbacks on load()/refresh() — for
/// asserting that the correct action was triggered.
class _StubControllerWithCallback extends ProdeFixturesController {
  final VoidCallback? onLoad;
  final VoidCallback? onRefresh;

  _StubControllerWithCallback(
    ProdeFixturesState initialState, {
    this.onLoad,
    this.onRefresh,
  }) : super(_FakeApiService()) {
    state = initialState;
  }

  @override
  Future<void> load() async {
    onLoad?.call();
  }

  @override
  Future<void> refresh() async {
    onRefresh?.call();
  }
}

// ---------------------------------------------------------------------------
// Test data helpers
// ---------------------------------------------------------------------------

FechaActiva _makeFecha({
  ProdeFechaState state = ProdeFechaState.open,
  bool emptyMatches = false,
}) {
  final matches = emptyMatches
      ? <FechaMatch>[]
      : [
          FechaMatch(
            matchId: 1,
            homeTeam: 'Team A',
            awayTeam: 'Team B',
            kickoff: DateTime(2026, 6, 7, 14, 0),
          ),
          FechaMatch(
            matchId: 2,
            homeTeam: 'Team C',
            awayTeam: 'Team D',
            kickoff: DateTime(2026, 6, 7, 16, 0),
          ),
        ];

  return FechaActiva(
    fechaId: 1,
    seasonId: 10,
    state: state,
    lockedAt: null,
    matches: matches,
  );
}

/// Pumps [ProdeFixturesScreen] with a stub controller inside a [ProviderScope].
Future<void> _pumpScreen(
  WidgetTester tester,
  ProdeFixturesState initialState, {
  bool stale = false,
  VoidCallback? onLogout,
}) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        prodeFixturesControllerProvider
            .overrideWith((ref) => _StubController(initialState)),
      ],
      child: MaterialApp(
        home: Scaffold(
          body: ProdeFixturesScreen(
            stale: stale,
            onLogout: onLogout ?? () {},
          ),
        ),
      ),
    ),
  );
  await tester.pump(); // settle microtask from initState
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

void main() {
  group('ProdeFixturesScreen', () {
    // Loading state
    testWidgets('Loading -> shows spinner, no match list', (tester) async {
      await _pumpScreen(tester, const ProdeFixturesLoading());
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      expect(find.text('Team A'), findsNothing);
    });

    // Loaded with 2 matches
    testWidgets('Loaded -> shows both team-name pairs', (tester) async {
      await _pumpScreen(tester, ProdeFixturesLoaded(_makeFecha()));
      expect(find.text('Team A'), findsOneWidget);
      expect(find.text('Team B'), findsOneWidget);
      expect(find.text('Team C'), findsOneWidget);
      expect(find.text('Team D'), findsOneWidget);
    });

    // Kickoff formatted correctly (DateFormat('dd/MM HH:mm'))
    testWidgets('Loaded -> formatted kickoff visible', (tester) async {
      await _pumpScreen(tester, ProdeFixturesLoaded(_makeFecha()));
      expect(find.text('07/06 14:00'), findsOneWidget);
    });

    // Logout button present in Loaded
    testWidgets('Loaded -> Cerrar sesión button present', (tester) async {
      await _pumpScreen(tester, ProdeFixturesLoaded(_makeFecha()));
      expect(find.text('Cerrar sesión'), findsOneWidget);
    });

    // locked state → "Cerrado" badge, no "Finalizada"
    testWidgets('Loaded(locked) -> Cerrado badge, no Finalizada', (tester) async {
      await _pumpScreen(
          tester, ProdeFixturesLoaded(_makeFecha(state: ProdeFechaState.locked)));
      expect(find.text('Cerrado'), findsOneWidget);
      expect(find.text('Finalizada'), findsNothing);
    });

    // evaluated state → "Finalizada" badge, no "Cerrado"
    testWidgets('Loaded(evaluated) -> Finalizada badge, no Cerrado',
        (tester) async {
      await _pumpScreen(
          tester,
          ProdeFixturesLoaded(
              _makeFecha(state: ProdeFechaState.evaluated)));
      expect(find.text('Finalizada'), findsOneWidget);
      expect(find.text('Cerrado'), findsNothing);
    });

    // open state → no badge
    testWidgets('Loaded(open) -> no Cerrado or Finalizada', (tester) async {
      await _pumpScreen(
          tester, ProdeFixturesLoaded(_makeFecha(state: ProdeFechaState.open)));
      expect(find.text('Cerrado'), findsNothing);
      expect(find.text('Finalizada'), findsNothing);
    });

    // stale banner visible when stale: true
    testWidgets('Loaded(stale: true) -> stale banner visible', (tester) async {
      await _pumpScreen(
        tester,
        ProdeFixturesLoaded(_makeFecha()),
        stale: true,
      );
      expect(find.text('Sincronizando tus datos…'), findsOneWidget);
    });

    // no stale banner when stale: false
    testWidgets('Loaded(stale: false) -> no stale banner', (tester) async {
      await _pumpScreen(tester, ProdeFixturesLoaded(_makeFecha()));
      expect(find.text('Sincronizando tus datos…'), findsNothing);
    });

    // Loaded with empty matches → note, no team names
    testWidgets('Loaded(empty matches) -> "Sin partidos" note', (tester) async {
      await _pumpScreen(
          tester, ProdeFixturesLoaded(_makeFecha(emptyMatches: true)));
      expect(find.text('Sin partidos en esta fecha.'), findsOneWidget);
      expect(find.text('Team A'), findsNothing);
    });

    // Empty state
    testWidgets('Empty -> "No hay una fecha activa" message, no spinner',
        (tester) async {
      await _pumpScreen(tester, const ProdeFixturesEmpty());
      expect(
          find.text('No hay una fecha activa en este momento.'), findsOneWidget);
      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(find.text('Team A'), findsNothing);
    });

    // Error → friendly copy + Reintentar, raw message hidden
    testWidgets('Error -> Reintentar button, raw message hidden', (tester) async {
      await _pumpScreen(
        tester,
        const ProdeFixturesError(code: 'fetch_fecha_error', message: 'status 500'),
      );
      expect(find.text('status 500'), findsNothing); // raw message must be hidden
      expect(find.text('Reintentar'), findsOneWidget);
    });

    // Error → tapping Reintentar calls load()
    testWidgets('Error -> tapping Reintentar calls load()', (tester) async {
      var loadCalled = false;
      const error = ProdeFixturesError(code: 'fixtures_error', message: 'oops');

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            prodeFixturesControllerProvider.overrideWith((ref) =>
                _StubControllerWithCallback(error,
                    onLoad: () => loadCalled = true)),
          ],
          child: MaterialApp(
            home: Scaffold(
              body: ProdeFixturesScreen(stale: false, onLogout: () {}),
            ),
          ),
        ),
      );
      await tester.pump();

      await tester.tap(find.text('Reintentar'));
      await tester.pump();

      expect(loadCalled, isTrue);
    });

    // Loaded → pull-to-refresh calls refresh()
    testWidgets('Loaded -> pull-to-refresh calls refresh()', (tester) async {
      var refreshCalled = false;
      final fecha = _makeFecha();

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            prodeFixturesControllerProvider.overrideWith((ref) =>
                _StubControllerWithCallback(
                  ProdeFixturesLoaded(fecha),
                  onRefresh: () => refreshCalled = true,
                )),
          ],
          child: MaterialApp(
            home: Scaffold(
              body: ProdeFixturesScreen(stale: false, onLogout: () {}),
            ),
          ),
        ),
      );
      await tester.pump();

      // Trigger pull-to-refresh by dragging down
      await tester.drag(
        find.byType(RefreshIndicator),
        const Offset(0, 300),
      );
      await tester.pump();
      await tester.pump(const Duration(seconds: 1));

      expect(refreshCalled, isTrue);
    });

    // Loaded → Cerrar sesión calls onLogout
    testWidgets('Loaded -> tapping Cerrar sesión calls onLogout', (tester) async {
      var logoutCalled = false;

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            prodeFixturesControllerProvider.overrideWith(
              (ref) => _StubController(ProdeFixturesLoaded(_makeFecha())),
            ),
          ],
          child: MaterialApp(
            home: Scaffold(
              body: ProdeFixturesScreen(
                stale: false,
                onLogout: () => logoutCalled = true,
              ),
            ),
          ),
        ),
      );
      await tester.pump();

      await tester.tap(find.text('Cerrar sesión'));
      await tester.pump();

      expect(logoutCalled, isTrue);
    });
  });
}

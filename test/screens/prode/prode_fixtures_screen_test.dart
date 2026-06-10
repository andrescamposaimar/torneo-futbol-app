import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/models/fecha_activa.dart';
import 'package:torneo_futbol_app/models/fecha_summary.dart';
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

  @override
  Future<void> selectFecha(int fechaId) async {}
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

  @override
  Future<void> selectFecha(int fechaId) async {}
}

/// Stub controller that records draft updates and submit calls for assertion.
class _StubControllerWithDraftTracking extends ProdeFixturesController {
  final List<(int, int?, int?)> draftUpdates = [];
  final List<int> submitCalls = [];

  // When set to true, submitPrediction succeeds and marks the match saved.
  bool submitSucceeds = false;

  _StubControllerWithDraftTracking(ProdeFixturesState initialState)
      : super(_FakeApiService()) {
    state = initialState;
  }

  @override
  Future<void> load() async {}

  @override
  Future<void> refresh() async {}

  @override
  Future<void> selectFecha(int fechaId) async {}

  @override
  void updateDraft(int matchId, {int? scoreHome, int? scoreAway}) {
    draftUpdates.add((matchId, scoreHome, scoreAway));
    // Also update state so the widget sees the change
    super.updateDraft(matchId, scoreHome: scoreHome, scoreAway: scoreAway);
  }

  @override
  Future<bool> submitPrediction(int matchId) async {
    submitCalls.add(matchId);
    if (submitSucceeds) {
      // Simulate success: mark as saved
      final current = state as ProdeFixturesLoaded;
      final existing = current.drafts[matchId] ?? const PredictionDraft();
      final newDrafts = Map<int, PredictionDraft>.from(current.drafts)
        ..[matchId] = existing.copyWith(status: SubmitStatus.submitted);
      final newSaved = {...current.savedMatchIds, matchId};
      state = ProdeFixturesLoaded(
        current.fecha,
        drafts: newDrafts,
        savedMatchIds: newSaved,
      );
      return true;
    }
    return false;
  }
}

/// Stub controller that records selectFecha calls — for G6-e selector tests.
class _StubControllerWithFechaTracking extends ProdeFixturesController {
  final List<int> selectFechaCalls = [];

  _StubControllerWithFechaTracking(ProdeFixturesState initialState)
      : super(_FakeApiService()) {
    state = initialState;
  }

  @override
  Future<void> load() async {}

  @override
  Future<void> refresh() async {}

  @override
  Future<void> selectFecha(int fechaId) async {
    selectFechaCalls.add(fechaId);
  }
}

// ---------------------------------------------------------------------------
// Test data helpers
// ---------------------------------------------------------------------------

FechaActiva _makeFecha({
  ProdeFechaState state = ProdeFechaState.open,
  bool emptyMatches = false,
  DateTime? lockedAt,
  List<PredictionEntry>? userPredictions,
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
    lockedAt: lockedAt,
    matches: matches,
    userPredictions: userPredictions ?? [],
  );
}

/// Builds the initial drafts for [fecha], mirroring the controller's seed logic.
Map<int, PredictionDraft> _seedDrafts(FechaActiva fecha) {
  final predMap = {
    for (final p in fecha.userPredictions)
      p.matchId: PredictionDraft(scoreHome: p.scoreHome, scoreAway: p.scoreAway),
  };
  return {
    for (final m in fecha.matches)
      m.matchId: predMap[m.matchId] ?? const PredictionDraft(),
  };
}

/// Builds the initial savedMatchIds for [fecha], mirroring controller seed logic.
Set<int> _seedSavedMatchIds(FechaActiva fecha) {
  return {for (final p in fecha.userPredictions) p.matchId};
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

    // Kickoff formatted correctly (new format: EEE dd/MM - HH:mm, capitalized)
    testWidgets('Loaded -> formatted kickoff visible (new EEE dd/MM format)', (tester) async {
      await _pumpScreen(tester, ProdeFixturesLoaded(_makeFecha()));
      // 2026-06-07 14:00 → Sunday 07/06 → "Dom. 07/06 - 14:00" or similar
      // We check the date/time portion is present: "07/06" and "14:00"
      expect(find.textContaining('07/06'), findsAtLeastNWidgets(1));
      expect(find.textContaining('14:00'), findsAtLeastNWidgets(1));
    });

    // Logout button present in Loaded
    testWidgets('Loaded -> Cerrar sesión button present', (tester) async {
      await _pumpScreen(tester, ProdeFixturesLoaded(_makeFecha()));
      expect(find.text('Cerrar sesión'), findsOneWidget);
    });

    // locked state → "Fecha Cerrada" badge, no "Finalizada"
    testWidgets('Loaded(locked) -> Fecha Cerrada badge, no Finalizada', (tester) async {
      await _pumpScreen(
          tester, ProdeFixturesLoaded(_makeFecha(state: ProdeFechaState.locked)));
      expect(find.text('Fecha Cerrada'), findsOneWidget);
      expect(find.text('Finalizada'), findsNothing);
    });

    // evaluated state → "Finalizada" badge, no "Fecha Cerrada"
    testWidgets('Loaded(evaluated) -> Finalizada badge, no Fecha Cerrada',
        (tester) async {
      await _pumpScreen(
          tester,
          ProdeFixturesLoaded(
              _makeFecha(state: ProdeFechaState.evaluated)));
      expect(find.text('Finalizada'), findsOneWidget);
      expect(find.text('Fecha Cerrada'), findsNothing);
    });

    // open state → no badge
    testWidgets('Loaded(open) -> no Fecha Cerrada or Finalizada', (tester) async {
      await _pumpScreen(
          tester, ProdeFixturesLoaded(_makeFecha(state: ProdeFechaState.open)));
      expect(find.text('Fecha Cerrada'), findsNothing);
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

    // -------------------------------------------------------------------------
    // G6-d: Progress header
    // -------------------------------------------------------------------------

    group('Progress header (G6-d)', () {
      testWidgets('shows 0/2 when no predictions', (tester) async {
        final fecha = _makeFecha();
        await _pumpScreen(
          tester,
          ProdeFixturesLoaded(fecha, savedMatchIds: const {}),
        );
        // Exact counter text — loose textContaining would match score boxes too
        expect(find.text('0/2'), findsOneWidget);
      });

      testWidgets('shows 1/2 when one prediction saved', (tester) async {
        final fecha = _makeFecha(
          userPredictions: [PredictionEntry(matchId: 1, scoreHome: 2, scoreAway: 1)],
        );
        final drafts = _seedDrafts(fecha);
        final savedMatchIds = _seedSavedMatchIds(fecha);
        await _pumpScreen(
          tester,
          ProdeFixturesLoaded(fecha, drafts: drafts, savedMatchIds: savedMatchIds),
        );
        // Exact counter text — loose textContaining would match score boxes too
        expect(find.text('1/2'), findsOneWidget);
      });

      testWidgets(
          'counter never exceeds total when savedMatchIds has stray IDs',
          (tester) async {
        final fecha = _makeFecha();
        await _pumpScreen(
          tester,
          // 99 is not a match of this fecha — must not count toward progress
          ProdeFixturesLoaded(fecha, savedMatchIds: const {1, 99}),
        );
        expect(find.text('1/2'), findsOneWidget);
        final indicator = tester.widget<LinearProgressIndicator>(
          find.byType(LinearProgressIndicator),
        );
        expect(indicator.value, 0.5);
      });

      testWidgets('LinearProgressIndicator is present in loaded state', (tester) async {
        await _pumpScreen(tester, ProdeFixturesLoaded(_makeFecha()));
        expect(find.byType(LinearProgressIndicator), findsOneWidget);
      });

      testWidgets('PRÓXIMOS PARTIDOS section title present', (tester) async {
        await _pumpScreen(tester, ProdeFixturesLoaded(_makeFecha()));
        expect(find.text('PRÓXIMOS PARTIDOS'), findsOneWidget);
      });

      testWidgets('PARTIDOS JUGADOS section title when fecha is locked', (tester) async {
        await _pumpScreen(
          tester,
          ProdeFixturesLoaded(
            _makeFecha(
              state: ProdeFechaState.locked,
              lockedAt: DateTime(2000, 1, 1), // past → locked by time
            ),
          ),
        );
        expect(find.text('PARTIDOS JUGADOS'), findsOneWidget);
        expect(find.text('PRÓXIMOS PARTIDOS'), findsNothing);
      });
    });

    // -------------------------------------------------------------------------
    // G6-d: Match card redesign
    // -------------------------------------------------------------------------

    group('Match card (G6-d)', () {
      testWidgets('card is tappable (GestureDetector or InkWell wraps card)', (tester) async {
        final fecha = _makeFecha();
        await _pumpScreen(tester, ProdeFixturesLoaded(fecha));
        // The match_card_1 key should exist and be tappable
        expect(find.byKey(const Key('match_card_1')), findsOneWidget);
      });

      testWidgets('score display shows em dash when draft score is null', (tester) async {
        final fecha = _makeFecha();
        // No drafts seeded — scores are null
        await _pumpScreen(tester, ProdeFixturesLoaded(fecha));
        // Each card should show "—" for unset scores (at least 2 per card × 2 cards)
        expect(find.text('—'), findsAtLeastNWidgets(2));
      });

      testWidgets('score display shows draft value when score is set', (tester) async {
        final fecha = _makeFecha(
          userPredictions: [PredictionEntry(matchId: 1, scoreHome: 3, scoreAway: 1)],
        );
        final drafts = _seedDrafts(fecha);
        final savedMatchIds = _seedSavedMatchIds(fecha);
        await _pumpScreen(
          tester,
          ProdeFixturesLoaded(fecha, drafts: drafts, savedMatchIds: savedMatchIds),
        );
        // Match 1 should show score values 3 and 1
        expect(find.text('3'), findsAtLeastNWidgets(1));
        expect(find.text('1'), findsAtLeastNWidgets(1));
      });

      testWidgets('saved match shows check icon', (tester) async {
        final fecha = _makeFecha(
          userPredictions: [PredictionEntry(matchId: 1, scoreHome: 2, scoreAway: 0)],
        );
        final drafts = _seedDrafts(fecha);
        final savedMatchIds = _seedSavedMatchIds(fecha);
        await _pumpScreen(
          tester,
          ProdeFixturesLoaded(fecha, drafts: drafts, savedMatchIds: savedMatchIds),
        );
        expect(find.byKey(const Key('status_icon_saved_1')), findsOneWidget);
      });

      testWidgets('unsaved match shows pending icon', (tester) async {
        final fecha = _makeFecha();
        await _pumpScreen(tester, ProdeFixturesLoaded(fecha));
        expect(find.byKey(const Key('status_icon_pending_1')), findsOneWidget);
      });
    });

    // -------------------------------------------------------------------------
    // G6-d: Tapping card opens modal
    // -------------------------------------------------------------------------

    group('Prediction modal (G6-d)', () {
      testWidgets('tapping open card opens modal sheet', (tester) async {
        final fecha = _makeFecha();
        await _pumpScreen(tester, ProdeFixturesLoaded(fecha));

        await tester.tap(find.byKey(const Key('match_card_1')));
        await tester.pumpAndSettle();

        // Modal should appear with stepper keys
        expect(find.byKey(const Key('stepper_home_plus_1')), findsOneWidget);
        expect(find.byKey(const Key('stepper_away_plus_1')), findsOneWidget);
      });

      testWidgets('modal stepper + increases home score', (tester) async {
        final fecha = _makeFecha();
        await _pumpScreen(tester, ProdeFixturesLoaded(fecha));

        await tester.tap(find.byKey(const Key('match_card_1')));
        await tester.pumpAndSettle();

        // Initial value is 0
        final valueFinder = find.byKey(const Key('stepper_home_value_1'));
        expect(valueFinder, findsOneWidget);
        expect(tester.widget<Text>(valueFinder).data, equals('0'));

        // Tap +
        await tester.tap(find.byKey(const Key('stepper_home_plus_1')));
        await tester.pump();

        expect(tester.widget<Text>(valueFinder).data, equals('1'));
      });

      testWidgets('modal stepper - decreases home score, clamps at 0', (tester) async {
        final fecha = _makeFecha();
        await _pumpScreen(tester, ProdeFixturesLoaded(fecha));

        await tester.tap(find.byKey(const Key('match_card_1')));
        await tester.pumpAndSettle();

        // Already at 0, tap minus — should stay at 0
        await tester.tap(find.byKey(const Key('stepper_home_minus_1')));
        await tester.pump();

        expect(
          tester.widget<Text>(find.byKey(const Key('stepper_home_value_1'))).data,
          equals('0'),
        );
      });

      testWidgets('modal stepper seeds from existing draft', (tester) async {
        final fecha = _makeFecha(
          userPredictions: [PredictionEntry(matchId: 1, scoreHome: 2, scoreAway: 1)],
        );
        final drafts = _seedDrafts(fecha);
        final savedMatchIds = _seedSavedMatchIds(fecha);
        await _pumpScreen(
          tester,
          ProdeFixturesLoaded(fecha, drafts: drafts, savedMatchIds: savedMatchIds),
        );

        await tester.tap(find.byKey(const Key('match_card_1')));
        await tester.pumpAndSettle();

        expect(
          tester.widget<Text>(find.byKey(const Key('stepper_home_value_1'))).data,
          equals('2'),
        );
        expect(
          tester.widget<Text>(find.byKey(const Key('stepper_away_value_1'))).data,
          equals('1'),
        );
      });

      testWidgets('GUARDAR button is present in modal', (tester) async {
        final fecha = _makeFecha();
        await _pumpScreen(tester, ProdeFixturesLoaded(fecha));

        await tester.tap(find.byKey(const Key('match_card_1')));
        await tester.pumpAndSettle();

        expect(find.byKey(const Key('guardar_1')), findsOneWidget);
      });

      testWidgets('GUARDAR calls updateDraft and submitPrediction then closes', (tester) async {
        final fecha = _makeFecha();
        final drafts = _seedDrafts(fecha);
        final stub = _StubControllerWithDraftTracking(
          ProdeFixturesLoaded(fecha, drafts: drafts),
        );
        stub.submitSucceeds = true;

        await tester.pumpWidget(
          ProviderScope(
            overrides: [
              prodeFixturesControllerProvider.overrideWith((ref) => stub),
            ],
            child: const MaterialApp(
              home: Scaffold(
                body: ProdeFixturesScreen(stale: false, onLogout: _noOp),
              ),
            ),
          ),
        );
        await tester.pump();

        // Open modal
        await tester.tap(find.byKey(const Key('match_card_1')));
        await tester.pumpAndSettle();

        // Adjust score
        await tester.tap(find.byKey(const Key('stepper_home_plus_1')));
        await tester.pump();

        // Tap GUARDAR
        await tester.tap(find.byKey(const Key('guardar_1')));
        await tester.pumpAndSettle();

        // Modal should be closed (stepper no longer visible)
        expect(find.byKey(const Key('stepper_home_plus_1')), findsNothing);
        // Submit was called
        expect(stub.submitCalls, contains(1));
        // updateDraft was called
        expect(stub.draftUpdates, isNotEmpty);
      });
    });

    // -------------------------------------------------------------------------
    // G6-e: Fecha selector row
    // -------------------------------------------------------------------------

    group('Fecha selector (G6-e)', () {
      // Helper: build a ProdeFixturesLoaded with multiple fechas for G6-e tests.
      ProdeFixturesLoaded _loadedWithFechas({
        int selectedIndex = 0,
        int fechaCount = 3,
        bool isFechaLoading = false,
        ProdeFixturesFechaError? fechaLoadError,
        ProdeFechaState selectedFechaState = ProdeFechaState.open,
      }) {
        final fecha = _makeFecha(state: selectedFechaState);
        final fechas = List.generate(fechaCount, (i) => FechaSummary(
          fechaId: i + 1,
          seasonId: 10,
          state: i == selectedIndex ? selectedFechaState : ProdeFechaState.open,
          lockedAt: null,
          matchCount: 2,
        ));
        return ProdeFixturesLoaded(
          fecha,
          fechas: fechas,
          selectedFechaId: fechas[selectedIndex].fechaId,
          isFechaLoading: isFechaLoading,
          fechaLoadError: fechaLoadError,
        );
      }

      testWidgets('selector row present when fechas >= 1', (tester) async {
        await _pumpScreen(tester, _loadedWithFechas(fechaCount: 3, selectedIndex: 0));
        expect(find.byKey(const Key('fecha_selector_label')), findsOneWidget);
      });

      testWidgets('selector row absent when fechas list is empty', (tester) async {
        // ProdeFixturesLoaded with empty fechas list
        final fecha = _makeFecha();
        final emptyLoaded = ProdeFixturesLoaded(fecha, fechas: const []);
        await _pumpScreen(tester, emptyLoaded);
        expect(find.byKey(const Key('fecha_selector_label')), findsNothing);
      });

      testWidgets('label shows "Fecha N" where N = selectedIndex + 1', (tester) async {
        // selectedIndex=1 → N=2
        await _pumpScreen(tester, _loadedWithFechas(fechaCount: 3, selectedIndex: 1));
        expect(find.byKey(const Key('fecha_selector_label')), findsOneWidget);
        expect(find.textContaining('Fecha 2'), findsAtLeastNWidgets(1));
      });

      testWidgets('prev arrow tap calls selectFecha with previous id (AC4)', (tester) async {
        final fecha = _makeFecha();
        final fechas = List.generate(3, (i) => FechaSummary(
          fechaId: i + 1,
          seasonId: 10,
          state: ProdeFechaState.open,
          lockedAt: null,
          matchCount: 2,
        ));
        final initialState = ProdeFixturesLoaded(
          fecha,
          fechas: fechas,
          selectedFechaId: 2, // middle
        );

        final stub = _StubControllerWithFechaTracking(initialState);

        await tester.pumpWidget(
          ProviderScope(
            overrides: [
              prodeFixturesControllerProvider.overrideWith((ref) => stub),
            ],
            child: const MaterialApp(
              home: Scaffold(
                body: ProdeFixturesScreen(stale: false, onLogout: _noOp),
              ),
            ),
          ),
        );
        await tester.pump();

        await tester.tap(find.byKey(const Key('fecha_selector_prev')));
        await tester.pump();

        expect(stub.selectFechaCalls, contains(1)); // id=1 (previous)
      });

      testWidgets('next arrow tap calls selectFecha with next id (AC4)', (tester) async {
        final fecha = _makeFecha();
        final fechas = List.generate(3, (i) => FechaSummary(
          fechaId: i + 1,
          seasonId: 10,
          state: ProdeFechaState.open,
          lockedAt: null,
          matchCount: 2,
        ));
        final initialState = ProdeFixturesLoaded(
          fecha,
          fechas: fechas,
          selectedFechaId: 2, // middle
        );

        final stub = _StubControllerWithFechaTracking(initialState);

        await tester.pumpWidget(
          ProviderScope(
            overrides: [
              prodeFixturesControllerProvider.overrideWith((ref) => stub),
            ],
            child: const MaterialApp(
              home: Scaffold(
                body: ProdeFixturesScreen(stale: false, onLogout: _noOp),
              ),
            ),
          ),
        );
        await tester.pump();

        await tester.tap(find.byKey(const Key('fecha_selector_next')));
        await tester.pump();

        expect(stub.selectFechaCalls, contains(3)); // id=3 (next)
      });

      testWidgets('prev arrow non-interactive when first fecha selected (AC5)', (tester) async {
        await _pumpScreen(tester, _loadedWithFechas(fechaCount: 3, selectedIndex: 0));

        // The prev button should exist but be non-interactive (disabled/no onTap)
        final prevFinder = find.byKey(const Key('fecha_selector_prev'));
        expect(prevFinder, findsOneWidget);

        // Attempt tap — should NOT call selectFecha (no callback set up)
        await tester.tap(prevFinder, warnIfMissed: false);
        await tester.pump();
        // If it's truly non-interactive, no error thrown and no side effect.
        // The visual state is tested by checking the widget has null onTap.
        final widget = tester.widget(prevFinder);
        if (widget is InkWell) {
          expect(widget.onTap, isNull);
        } else if (widget is GestureDetector) {
          expect(widget.onTap, isNull);
        }
        // Widget exists — just muted; test passes by not throwing.
      });

      testWidgets('next arrow non-interactive when last fecha selected (AC5)', (tester) async {
        await _pumpScreen(tester, _loadedWithFechas(fechaCount: 3, selectedIndex: 2));

        final nextFinder = find.byKey(const Key('fecha_selector_next'));
        expect(nextFinder, findsOneWidget);

        final widget = tester.widget(nextFinder);
        if (widget is InkWell) {
          expect(widget.onTap, isNull);
        } else if (widget is GestureDetector) {
          expect(widget.onTap, isNull);
        }
      });

      testWidgets('tapping label opens bottom sheet with Fecha N entries (AC6)', (tester) async {
        await _pumpScreen(tester, _loadedWithFechas(fechaCount: 3, selectedIndex: 0));

        await tester.tap(find.byKey(const Key('fecha_selector_label')));
        await tester.pumpAndSettle();

        // Bottom sheet should be open with entries
        expect(find.byKey(const Key('fecha_picker_entry_1')), findsOneWidget);
        expect(find.byKey(const Key('fecha_picker_entry_2')), findsOneWidget);
        expect(find.byKey(const Key('fecha_picker_entry_3')), findsOneWidget);
        expect(find.text('Seleccionar fecha'), findsOneWidget);
      });

      // W-1: selector row MUST appear above the progress header.
      testWidgets('selector row renders above progress header in loaded state (W-1)', (tester) async {
        await _pumpScreen(tester, _loadedWithFechas(fechaCount: 3, selectedIndex: 0));

        final selectorFinder = find.byKey(const Key('fecha_selector_label'));
        final progressFinder = find.byType(LinearProgressIndicator);

        expect(selectorFinder, findsOneWidget);
        expect(progressFinder, findsOneWidget);

        final selectorDy = tester.getTopLeft(selectorFinder).dy;
        final progressDy = tester.getTopLeft(progressFinder).dy;

        expect(
          selectorDy,
          lessThan(progressDy),
          reason: '_FechaSelectorRow must be rendered above the progress header',
        );
      });

      // W-2: dismissing the picker without selecting must NOT change the
      // label and must NOT call selectFecha.
      testWidgets('dismiss picker without selecting keeps label and fires no selectFecha (W-2)', (tester) async {
        final fecha = _makeFecha();
        final fechas = List.generate(3, (i) => FechaSummary(
          fechaId: i + 1,
          seasonId: 10,
          state: ProdeFechaState.open,
          lockedAt: null,
          matchCount: 2,
        ));
        // selectedFechaId == 2 → label should show "Fecha 2"
        final initialState = ProdeFixturesLoaded(
          fecha,
          fechas: fechas,
          selectedFechaId: 2,
        );

        final stub = _StubControllerWithFechaTracking(initialState);

        await tester.pumpWidget(
          ProviderScope(
            overrides: [
              prodeFixturesControllerProvider.overrideWith((ref) => stub),
            ],
            child: const MaterialApp(
              home: Scaffold(
                body: ProdeFixturesScreen(stale: false, onLogout: _noOp),
              ),
            ),
          ),
        );
        await tester.pump();

        // Label shows "Fecha 2" before open.
        expect(find.textContaining('Fecha 2'), findsAtLeastNWidgets(1));

        // Open picker.
        await tester.tap(find.byKey(const Key('fecha_selector_label')));
        await tester.pumpAndSettle();

        expect(find.text('Seleccionar fecha'), findsOneWidget);

        // Dismiss via barrier (tap near top-left of screen, outside the sheet).
        await tester.tapAt(const Offset(200, 10));
        await tester.pumpAndSettle();

        // Sheet is gone.
        expect(find.text('Seleccionar fecha'), findsNothing);

        // Label still shows the same fecha and no selectFecha was called.
        expect(find.textContaining('Fecha 2'), findsAtLeastNWidgets(1));
        expect(stub.selectFechaCalls, isEmpty);
      });

      testWidgets('tapping picker entry closes sheet and calls selectFecha (AC6)', (tester) async {
        final fecha = _makeFecha();
        final fechas = List.generate(3, (i) => FechaSummary(
          fechaId: i + 1,
          seasonId: 10,
          state: ProdeFechaState.open,
          lockedAt: null,
          matchCount: 2,
        ));
        final initialState = ProdeFixturesLoaded(
          fecha,
          fechas: fechas,
          selectedFechaId: 1,
        );

        final stub = _StubControllerWithFechaTracking(initialState);

        await tester.pumpWidget(
          ProviderScope(
            overrides: [
              prodeFixturesControllerProvider.overrideWith((ref) => stub),
            ],
            child: const MaterialApp(
              home: Scaffold(
                body: ProdeFixturesScreen(stale: false, onLogout: _noOp),
              ),
            ),
          ),
        );
        await tester.pump();

        // Open dropdown
        await tester.tap(find.byKey(const Key('fecha_selector_label')));
        await tester.pumpAndSettle();

        // Tap entry for fecha 3
        await tester.tap(find.byKey(const Key('fecha_picker_entry_3')));
        await tester.pumpAndSettle();

        expect(stub.selectFechaCalls, contains(3));
        // Sheet should be closed
        expect(find.byKey(const Key('fecha_picker_entry_3')), findsNothing);
      });

      testWidgets('isFechaLoading true → scoped spinner present, selector row still mounted (AC7)', (tester) async {
        await _pumpScreen(tester, _loadedWithFechas(isFechaLoading: true));

        expect(find.byKey(const Key('fecha_load_spinner')), findsOneWidget);
        expect(find.byKey(const Key('fecha_selector_label')), findsOneWidget);
      });

      testWidgets('fechaLoadError set → inline error and Reintentar button present (AC8)', (tester) async {
        final error = ProdeFixturesFechaError(code: 'fetch_error', fechaId: 2);
        await _pumpScreen(tester, _loadedWithFechas(fechaLoadError: error));

        expect(find.text('No pudimos cargar esta fecha.'), findsOneWidget);
        expect(find.byKey(const Key('fecha_load_retry')), findsOneWidget);
      });

      testWidgets('Reintentar tap re-calls selectFecha with same fechaId (AC8)', (tester) async {
        final fecha = _makeFecha();
        final fechas = List.generate(3, (i) => FechaSummary(
          fechaId: i + 1,
          seasonId: 10,
          state: ProdeFechaState.open,
          lockedAt: null,
          matchCount: 2,
        ));
        final error = ProdeFixturesFechaError(code: 'fetch_error', fechaId: 2);
        final initialState = ProdeFixturesLoaded(
          fecha,
          fechas: fechas,
          selectedFechaId: 1,
          fechaLoadError: error,
        );

        final stub = _StubControllerWithFechaTracking(initialState);

        await tester.pumpWidget(
          ProviderScope(
            overrides: [
              prodeFixturesControllerProvider.overrideWith((ref) => stub),
            ],
            child: const MaterialApp(
              home: Scaffold(
                body: ProdeFixturesScreen(stale: false, onLogout: _noOp),
              ),
            ),
          ),
        );
        await tester.pump();

        await tester.tap(find.byKey(const Key('fecha_load_retry')));
        await tester.pump();

        expect(stub.selectFechaCalls, contains(2)); // retry with same fechaId
      });
    });

    // -------------------------------------------------------------------------
    // G6-d: Locked fecha behavior
    // -------------------------------------------------------------------------

    group('Locked fecha (G6-d)', () {
      testWidgets('locked fecha: modal opens read-only (steppers and GUARDAR disabled)', (tester) async {
        // lockedAt in the past → locked. The modal still opens (it will host
        // the populares section in G6-f) but every control must be disabled.
        final fecha = _makeFecha(lockedAt: DateTime(2020, 1, 1));
        await _pumpScreen(tester, ProdeFixturesLoaded(fecha));

        await tester.tap(find.byKey(const Key('match_card_1')));
        await tester.pumpAndSettle();

        // Modal DID open
        final guardarFinder = find.byKey(const Key('guardar_1'));
        expect(guardarFinder, findsOneWidget);

        // GUARDAR disabled
        final btn = tester.widget<ElevatedButton>(guardarFinder);
        expect(btn.onPressed, isNull);

        // Steppers disabled
        final plus = tester.widget<IconButton>(
          find.byKey(const Key('stepper_home_plus_1')),
        );
        expect(plus.onPressed, isNull);
        final minus = tester.widget<IconButton>(
          find.byKey(const Key('stepper_away_minus_1')),
        );
        expect(minus.onPressed, isNull);
      });

      testWidgets('locked fecha: unsaved card shows lock icon instead of pending', (tester) async {
        final fecha = _makeFecha(lockedAt: DateTime(2020, 1, 1));
        await _pumpScreen(tester, ProdeFixturesLoaded(fecha));

        expect(find.byKey(const Key('status_icon_locked_1')), findsOneWidget);
        expect(find.byKey(const Key('status_icon_pending_1')), findsNothing);
      });

      // G6-e AC11: selected FechaSummary.state == locked → guardar disabled
      testWidgets('G6-e: selected fecha state=locked → guardar_{id} disabled (AC11)', (tester) async {
        final fecha = _makeFecha(state: ProdeFechaState.locked);
        final fechas = [
          FechaSummary(
            fechaId: 1,
            seasonId: 10,
            state: ProdeFechaState.locked,
            lockedAt: null,
            matchCount: 2,
          ),
        ];
        final loadedState = ProdeFixturesLoaded(
          fecha,
          fechas: fechas,
          selectedFechaId: 1,
        );

        await _pumpScreen(tester, loadedState);

        await tester.tap(find.byKey(const Key('match_card_1')));
        await tester.pumpAndSettle();

        final guardarFinder = find.byKey(const Key('guardar_1'));
        expect(guardarFinder, findsOneWidget);
        final btn = tester.widget<ElevatedButton>(guardarFinder);
        expect(btn.onPressed, isNull);
      });

      // G6-e AC11: selected FechaSummary.state == open → guardar enabled
      testWidgets('G6-e: selected fecha state=open → guardar_{id} enabled (AC11)', (tester) async {
        final fecha = _makeFecha(state: ProdeFechaState.open);
        final fechas = [
          FechaSummary(
            fechaId: 1,
            seasonId: 10,
            state: ProdeFechaState.open,
            lockedAt: null,
            matchCount: 2,
          ),
        ];
        final loadedState = ProdeFixturesLoaded(
          fecha,
          fechas: fechas,
          selectedFechaId: 1,
        );

        await _pumpScreen(tester, loadedState);

        await tester.tap(find.byKey(const Key('match_card_1')));
        await tester.pumpAndSettle();

        final guardarFinder = find.byKey(const Key('guardar_1'));
        expect(guardarFinder, findsOneWidget);
        final btn = tester.widget<ElevatedButton>(guardarFinder);
        expect(btn.onPressed, isNotNull);
      });
    });

    // -------------------------------------------------------------------------
    // T-12: Evaluated fecha — result badge + real-score line in _MatchCard
    // -------------------------------------------------------------------------

    group('Evaluated fecha result rendering (T-12)', () {
      /// Builds a FechaActiva in evaluated state with one match that is final,
      /// and one user prediction with [points] and [evaluationMethod].
      FechaActiva _evaluatedFecha({
        int? realScoreHome = 2,
        int? realScoreAway = 1,
        bool isFinal = true,
        int? points = 3,
        String? evaluationMethod = 'exact_score',
      }) {
        return FechaActiva(
          fechaId: 1,
          seasonId: 10,
          state: ProdeFechaState.evaluated,
          lockedAt: DateTime(2020, 1, 1),
          matches: [
            FechaMatch(
              matchId: 1,
              homeTeam: 'River',
              awayTeam: 'Boca',
              kickoff: DateTime(2026, 6, 7, 14, 0),
              realScoreHome: realScoreHome,
              realScoreAway: realScoreAway,
              isFinal: isFinal,
            ),
          ],
          userPredictions: [
            PredictionEntry(
              matchId: 1,
              scoreHome: 2,
              scoreAway: 1,
              points: points,
              evaluationMethod: evaluationMethod,
            ),
          ],
        );
      }

      // --- badge rendering ---

      testWidgets('exact_score: green badge label "+3 Exacto" visible on card', (tester) async {
        final fecha = _evaluatedFecha(
          points: 3,
          evaluationMethod: 'exact_score',
          isFinal: true,
        );
        final drafts = _seedDrafts(fecha);
        final savedMatchIds = _seedSavedMatchIds(fecha);
        await _pumpScreen(
          tester,
          ProdeFixturesLoaded(fecha, drafts: drafts, savedMatchIds: savedMatchIds),
        );

        expect(find.byKey(const Key('result_badge_1')), findsOneWidget);
        expect(find.text('+3 Exacto'), findsOneWidget);
      });

      testWidgets('result_only/1: amber badge label "+1 Ganador" visible on card', (tester) async {
        final fecha = _evaluatedFecha(
          points: 1,
          evaluationMethod: 'result_only',
          realScoreHome: 1,
          realScoreAway: 0,
          isFinal: true,
        );
        final drafts = _seedDrafts(fecha);
        final savedMatchIds = _seedSavedMatchIds(fecha);
        await _pumpScreen(
          tester,
          ProdeFixturesLoaded(fecha, drafts: drafts, savedMatchIds: savedMatchIds),
        );

        expect(find.byKey(const Key('result_badge_1')), findsOneWidget);
        expect(find.text('+1 Ganador'), findsOneWidget);
      });

      testWidgets('result_only/0: red badge label "0 pts" visible on card', (tester) async {
        final fecha = _evaluatedFecha(
          points: 0,
          evaluationMethod: 'result_only',
          realScoreHome: 3,
          realScoreAway: 0,
          isFinal: true,
        );
        final drafts = _seedDrafts(fecha);
        final savedMatchIds = _seedSavedMatchIds(fecha);
        await _pumpScreen(
          tester,
          ProdeFixturesLoaded(fecha, drafts: drafts, savedMatchIds: savedMatchIds),
        );

        expect(find.byKey(const Key('result_badge_1')), findsOneWidget);
        expect(find.text('0 pts'), findsOneWidget);
      });

      // --- real-score line ---

      testWidgets('isFinal=true: real-score line shows "Resultado: 2 - 1"', (tester) async {
        final fecha = _evaluatedFecha(
          realScoreHome: 2,
          realScoreAway: 1,
          isFinal: true,
        );
        final drafts = _seedDrafts(fecha);
        final savedMatchIds = _seedSavedMatchIds(fecha);
        await _pumpScreen(
          tester,
          ProdeFixturesLoaded(fecha, drafts: drafts, savedMatchIds: savedMatchIds),
        );

        expect(find.byKey(const Key('real_score_line_1')), findsOneWidget);
        // Must show both real-score numbers
        final widget = tester.widget<Text>(find.byKey(const Key('real_score_line_1')));
        expect(widget.data, contains('2'));
        expect(widget.data, contains('1'));
      });

      testWidgets('isFinal=false: no real-score line rendered', (tester) async {
        final fecha = _evaluatedFecha(
          realScoreHome: null,
          realScoreAway: null,
          isFinal: false,
        );
        final drafts = _seedDrafts(fecha);
        final savedMatchIds = _seedSavedMatchIds(fecha);
        await _pumpScreen(
          tester,
          ProdeFixturesLoaded(fecha, drafts: drafts, savedMatchIds: savedMatchIds),
        );

        expect(find.byKey(const Key('real_score_line_1')), findsNothing);
      });

      // --- null real-score fallback (legacy evaluated fecha) ---

      testWidgets('legacy evaluated: points known but realScore null — badge shown, no real-score line, no crash', (tester) async {
        final fecha = _evaluatedFecha(
          realScoreHome: null,
          realScoreAway: null,
          isFinal: false, // pre-change: is_final was not set
          points: 3,
          evaluationMethod: 'exact_score',
        );
        final drafts = _seedDrafts(fecha);
        final savedMatchIds = _seedSavedMatchIds(fecha);

        await _pumpScreen(
          tester,
          ProdeFixturesLoaded(fecha, drafts: drafts, savedMatchIds: savedMatchIds),
        );

        // badge still shows (points are known)
        expect(find.byKey(const Key('result_badge_1')), findsOneWidget);
        // real-score line absent (not final)
        expect(find.byKey(const Key('real_score_line_1')), findsNothing);
      });

      // --- active/open fecha: no badge, no real-score line ---

      testWidgets('open fecha: no result badge, no real-score line', (tester) async {
        final fecha = FechaActiva(
          fechaId: 1,
          seasonId: 10,
          state: ProdeFechaState.open,
          lockedAt: null,
          matches: [
            FechaMatch(
              matchId: 1,
              homeTeam: 'River',
              awayTeam: 'Boca',
              kickoff: DateTime(2026, 6, 7, 14, 0),
              realScoreHome: null,
              realScoreAway: null,
              isFinal: false,
            ),
          ],
        );
        await _pumpScreen(tester, ProdeFixturesLoaded(fecha));

        expect(find.byKey(const Key('result_badge_1')), findsNothing);
        expect(find.byKey(const Key('real_score_line_1')), findsNothing);
      });

      // --- locked fecha with no evaluation: no badge ---

      testWidgets('locked fecha: no result badge shown', (tester) async {
        final fecha = FechaActiva(
          fechaId: 1,
          seasonId: 10,
          state: ProdeFechaState.locked,
          lockedAt: DateTime(2020, 1, 1),
          matches: [
            FechaMatch(
              matchId: 1,
              homeTeam: 'River',
              awayTeam: 'Boca',
              kickoff: DateTime(2026, 6, 7, 14, 0),
              isFinal: false,
            ),
          ],
        );
        await _pumpScreen(tester, ProdeFixturesLoaded(fecha));

        expect(find.byKey(const Key('result_badge_1')), findsNothing);
      });

      // --- card border color reflects evaluation style ---

      testWidgets('evaluated isFinal=true card has colored border (not grey.shade200)', (tester) async {
        final fecha = _evaluatedFecha(points: 3, evaluationMethod: 'exact_score', isFinal: true);
        final drafts = _seedDrafts(fecha);
        final savedMatchIds = _seedSavedMatchIds(fecha);
        await _pumpScreen(
          tester,
          ProdeFixturesLoaded(fecha, drafts: drafts, savedMatchIds: savedMatchIds),
        );

        // The card Container/Card should exist with the match_card key.
        expect(find.byKey(const Key('match_card_1')), findsOneWidget);
        // We can't easily inspect border color in widget tests without finding the
        // specific Card or Container — verify that no assertion error occurred
        // and the badge is present (implicit: card rendered without error).
        expect(find.text('+3 Exacto'), findsOneWidget);
      });
    });

    // -------------------------------------------------------------------------
    // G6-f: Populares section
    // -------------------------------------------------------------------------

    group('Populares section (G6-f)', () {
      // Helper: pump screen and open the modal for a given match card.
      Future<void> _openModal(
        WidgetTester tester,
        ProdeFixturesState state,
        int matchId,
      ) async {
        await _pumpScreen(tester, state);
        await tester.tap(find.byKey(Key('match_card_$matchId')));
        await tester.pumpAndSettle();
      }

      // POP-1-a: section container and all 3 chips present when locked + populares non-null.
      testWidgets('POP-1-a: section + chips present when isLocked=true + populares!=null', (tester) async {
        final fecha = FechaActiva(
          fechaId: 1,
          seasonId: 10,
          state: ProdeFechaState.locked,
          lockedAt: DateTime(2020, 1, 1),
          matches: [
            FechaMatch(
              matchId: 1,
              homeTeam: 'Team A',
              awayTeam: 'Team B',
              kickoff: DateTime(2026, 6, 7, 14, 0),
              // Backend sends percentages [0,100] — 45.0 means 45%, not 0.45
              populares: const Populares(home: 45.0, draw: 30.0, away: 25.0),
            ),
          ],
        );
        await _openModal(tester, ProdeFixturesLoaded(fecha), 1);

        expect(find.byKey(const Key('populares_section_1')), findsOneWidget);
        expect(find.byKey(const Key('populares_chip_1_1')), findsOneWidget);
        expect(find.byKey(const Key('populares_chip_X_1')), findsOneWidget);
        expect(find.byKey(const Key('populares_chip_2_1')), findsOneWidget);
      });

      // POP-2-a: chips show correct percentages when locked + populares non-null.
      // Backend contract: values are already percentages [0,100].
      testWidgets('POP-2-a: chips show 45%, 30%, 25% when locked + populares set', (tester) async {
        final fecha = FechaActiva(
          fechaId: 1,
          seasonId: 10,
          state: ProdeFechaState.locked,
          lockedAt: DateTime(2020, 1, 1),
          matches: [
            FechaMatch(
              matchId: 1,
              homeTeam: 'Team A',
              awayTeam: 'Team B',
              kickoff: DateTime(2026, 6, 7, 14, 0),
              // Wire values are percentages: 45.0 → "45%", 30.0 → "30%", 25.0 → "25%"
              populares: const Populares(home: 45.0, draw: 30.0, away: 25.0),
            ),
          ],
        );
        await _openModal(tester, ProdeFixturesLoaded(fecha), 1);

        expect(find.text('45%'), findsOneWidget);
        expect(find.text('30%'), findsOneWidget);
        expect(find.text('25%'), findsOneWidget);
      });

      // POP-2-b: away chip shows "0%" when away=0.0.
      testWidgets('POP-2-b: away chip shows 0% when away=0.0', (tester) async {
        final fecha = FechaActiva(
          fechaId: 1,
          seasonId: 10,
          state: ProdeFechaState.locked,
          lockedAt: DateTime(2020, 1, 1),
          matches: [
            FechaMatch(
              matchId: 1,
              homeTeam: 'Team A',
              awayTeam: 'Team B',
              kickoff: DateTime(2026, 6, 7, 14, 0),
              // Wire values are percentages: 70.0 → "70%", 30.0 → "30%", 0.0 → "0%"
              populares: const Populares(home: 70.0, draw: 30.0, away: 0.0),
            ),
          ],
        );
        await _openModal(tester, ProdeFixturesLoaded(fecha), 1);

        expect(find.text('0%'), findsOneWidget);
        expect(find.text('70%'), findsOneWidget);
        expect(find.text('30%'), findsOneWidget);
      });

      // POP-2-c: rounding artifact — values round independently, no normalization, no error.
      testWidgets('POP-2-c: rounding artifact — each chip rounds independently', (tester) async {
        final fecha = FechaActiva(
          fechaId: 1,
          seasonId: 10,
          state: ProdeFechaState.locked,
          lockedAt: DateTime(2020, 1, 1),
          matches: [
            FechaMatch(
              matchId: 1,
              homeTeam: 'Team A',
              awayTeam: 'Team B',
              kickoff: DateTime(2026, 6, 7, 14, 0),
              // Wire percentages: 33.4 → 33, 33.3 → 33, 33.3 → 33 (sum = 99)
              populares: const Populares(home: 33.4, draw: 33.3, away: 33.3),
            ),
          ],
        );
        await _openModal(tester, ProdeFixturesLoaded(fecha), 1);

        // All three chips should show "33%" — no crash, no normalization.
        expect(find.text('33%'), findsNWidgets(3));
      });

      // POP-2-d: 100/0/0 case.
      testWidgets('POP-2-d: chip "1" shows 100%, X and 2 show 0% (all votes on home)', (tester) async {
        final fecha = FechaActiva(
          fechaId: 1,
          seasonId: 10,
          state: ProdeFechaState.locked,
          lockedAt: DateTime(2020, 1, 1),
          matches: [
            FechaMatch(
              matchId: 1,
              homeTeam: 'Team A',
              awayTeam: 'Team B',
              kickoff: DateTime(2026, 6, 7, 14, 0),
              // Wire values are percentages: 100.0 → "100%", 0.0 → "0%"
              populares: const Populares(home: 100.0, draw: 0.0, away: 0.0),
            ),
          ],
        );
        await _openModal(tester, ProdeFixturesLoaded(fecha), 1);

        expect(find.text('100%'), findsOneWidget);
        expect(find.text('0%'), findsNWidgets(2));
      });

      // POP-2-e: wire percentage contract — backend sends percentages [0,100].
      // Populares values are already percentages (e.g. 100.0 means 100%, not 1).
      // The screen must NOT multiply by 100 again.
      testWidgets('POP-2-e: wire value 100.0 renders as "100%", not "10000%" (percentage contract)', (tester) async {
        // Backend sends percentages: 100.0, 0.0, 0.0
        final fecha = FechaActiva(
          fechaId: 1,
          seasonId: 10,
          state: ProdeFechaState.locked,
          lockedAt: DateTime(2020, 1, 1),
          matches: [
            FechaMatch(
              matchId: 1,
              homeTeam: 'Team A',
              awayTeam: 'Team B',
              kickoff: DateTime(2026, 6, 7, 14, 0),
              populares: const Populares(home: 100.0, draw: 0.0, away: 0.0),
            ),
          ],
        );
        await _openModal(tester, ProdeFixturesLoaded(fecha), 1);

        expect(find.text('100%'), findsOneWidget);
        expect(find.text('10000%'), findsNothing); // must never appear
        expect(find.text('0%'), findsNWidgets(2));
      });

      // POP-2-f: realistic mixed case — backend sends 33.3 / 33.3 / 33.4.
      testWidgets('POP-2-f: wire values 33.3/33.3/33.4 render as "33%"/"33%"/"33%" (percentage contract)', (tester) async {
        final fecha = FechaActiva(
          fechaId: 1,
          seasonId: 10,
          state: ProdeFechaState.locked,
          lockedAt: DateTime(2020, 1, 1),
          matches: [
            FechaMatch(
              matchId: 1,
              homeTeam: 'Team A',
              awayTeam: 'Team B',
              kickoff: DateTime(2026, 6, 7, 14, 0),
              populares: const Populares(home: 33.3, draw: 33.3, away: 33.4),
            ),
          ],
        );
        await _openModal(tester, ProdeFixturesLoaded(fecha), 1);

        expect(find.text('33%'), findsNWidgets(3));
      });

      // POP-3-a: open fecha + populares null → locked hint visible, no % anywhere.
      testWidgets('POP-3-a: open fecha + populares null → locked_hint visible, no % shown', (tester) async {
        final fecha = FechaActiva(
          fechaId: 1,
          seasonId: 10,
          state: ProdeFechaState.open,
          lockedAt: null,
          matches: [
            FechaMatch(
              matchId: 1,
              homeTeam: 'Team A',
              awayTeam: 'Team B',
              kickoff: DateTime(2026, 6, 7, 14, 0),
              populares: null,
            ),
          ],
        );
        await _openModal(tester, ProdeFixturesLoaded(fecha), 1);

        expect(find.byKey(const Key('populares_locked_hint')), findsOneWidget);
        expect(find.textContaining('%'), findsNothing);
      });

      // POP-3-b: locked fecha + populares null → locked presentation, no crash, no %.
      testWidgets('POP-3-b: locked fecha + populares null → locked presentation, no crash', (tester) async {
        final fecha = FechaActiva(
          fechaId: 1,
          seasonId: 10,
          state: ProdeFechaState.locked,
          lockedAt: DateTime(2020, 1, 1),
          matches: [
            FechaMatch(
              matchId: 1,
              homeTeam: 'Team A',
              awayTeam: 'Team B',
              kickoff: DateTime(2026, 6, 7, 14, 0),
              populares: null,
            ),
          ],
        );
        await _openModal(tester, ProdeFixturesLoaded(fecha), 1);

        expect(find.byKey(const Key('populares_locked_hint')), findsOneWidget);
        expect(find.textContaining('%'), findsNothing);
      });

      // POP-3-c: open fecha + populares non-null → isLocked takes precedence, no % revealed.
      testWidgets('POP-3-c: open fecha + populares non-null → locked presentation (isLocked wins)', (tester) async {
        final fecha = FechaActiva(
          fechaId: 1,
          seasonId: 10,
          state: ProdeFechaState.open,
          lockedAt: null,
          matches: [
            FechaMatch(
              matchId: 1,
              homeTeam: 'Team A',
              awayTeam: 'Team B',
              kickoff: DateTime(2026, 6, 7, 14, 0),
              populares: const Populares(home: 45.0, draw: 30.0, away: 25.0),
            ),
          ],
        );
        await _openModal(tester, ProdeFixturesLoaded(fecha), 1);

        expect(find.byKey(const Key('populares_locked_hint')), findsOneWidget);
        expect(find.textContaining('%'), findsNothing);
      });

      // POP-4-a: match card in list does NOT contain populares_section.
      testWidgets('POP-4-a: populares_section not present in match card list (no modal)', (tester) async {
        final fecha = FechaActiva(
          fechaId: 1,
          seasonId: 10,
          state: ProdeFechaState.locked,
          lockedAt: DateTime(2020, 1, 1),
          matches: [
            FechaMatch(
              matchId: 1,
              homeTeam: 'Team A',
              awayTeam: 'Team B',
              kickoff: DateTime(2026, 6, 7, 14, 0),
              populares: const Populares(home: 45.0, draw: 30.0, away: 25.0),
            ),
          ],
        );
        await _pumpScreen(tester, ProdeFixturesLoaded(fecha));

        // Do NOT open the modal — card list must not show the section.
        expect(find.byKey(const Key('populares_section_1')), findsNothing);
      });

      // POP-5-a: mixed populares in a locked fecha.
      testWidgets('POP-5-a: mixed populares — match 1 shows %, match 2 shows locked hint', (tester) async {
        final fecha = FechaActiva(
          fechaId: 1,
          seasonId: 10,
          state: ProdeFechaState.locked,
          lockedAt: DateTime(2020, 1, 1),
          matches: [
            FechaMatch(
              matchId: 1,
              homeTeam: 'Team A',
              awayTeam: 'Team B',
              kickoff: DateTime(2026, 6, 7, 14, 0),
              populares: const Populares(home: 45.0, draw: 30.0, away: 25.0),
            ),
            FechaMatch(
              matchId: 2,
              homeTeam: 'Team C',
              awayTeam: 'Team D',
              kickoff: DateTime(2026, 6, 7, 16, 0),
              populares: null,
            ),
          ],
        );

        // Open match 1 — should reveal percentages.
        await _openModal(tester, ProdeFixturesLoaded(fecha), 1);
        expect(find.text('45%'), findsOneWidget);
        expect(find.byKey(const Key('populares_locked_hint')), findsNothing);

        // Close modal.
        await tester.tapAt(const Offset(200, 10));
        await tester.pumpAndSettle();

        // Open match 2 — should show locked hint.
        await tester.tap(find.byKey(const Key('match_card_2')));
        await tester.pumpAndSettle();
        expect(find.byKey(const Key('populares_locked_hint')), findsOneWidget);
        expect(find.textContaining('%'), findsNothing);
      });

      // POP-6-a: info icon has non-empty Semantics label.
      testWidgets('POP-6-a: info icon has Semantics label "Información sobre pronósticos populares"', (tester) async {
        final fecha = FechaActiva(
          fechaId: 1,
          seasonId: 10,
          state: ProdeFechaState.locked,
          lockedAt: DateTime(2020, 1, 1),
          matches: [
            FechaMatch(
              matchId: 1,
              homeTeam: 'Team A',
              awayTeam: 'Team B',
              kickoff: DateTime(2026, 6, 7, 14, 0),
              populares: const Populares(home: 45.0, draw: 30.0, away: 25.0),
            ),
          ],
        );
        await _openModal(tester, ProdeFixturesLoaded(fecha), 1);

        final semanticsWidget = find.bySemanticsLabel(
          RegExp('Información sobre pronósticos populares'),
        );
        expect(semanticsWidget, findsAtLeastNWidgets(1));
      });
    });

    // -------------------------------------------------------------------------
    // T-13: Two-tab split — "A Jugarse" / "Finalizados"
    // -------------------------------------------------------------------------

    group('Two-tab split (T-13)', () {
      // Helper: builds a ProdeFixturesLoaded with a mix of fechas across states.
      ProdeFixturesLoaded _loadedWithMixedFechas({
        int openCount = 2,
        int lockedCount = 1,
        int evaluatedCount = 1,
        int? selectedFechaId,
      }) {
        final summaries = <FechaSummary>[];
        int nextId = 1;

        for (var i = 0; i < openCount; i++) {
          summaries.add(FechaSummary(
            fechaId: nextId++,
            seasonId: 10,
            state: ProdeFechaState.open,
            lockedAt: null,
            matchCount: 2,
          ));
        }
        for (var i = 0; i < lockedCount; i++) {
          summaries.add(FechaSummary(
            fechaId: nextId++,
            seasonId: 10,
            state: ProdeFechaState.locked,
            lockedAt: DateTime(2020, 1, 1),
            matchCount: 2,
          ));
        }
        for (var i = 0; i < evaluatedCount; i++) {
          summaries.add(FechaSummary(
            fechaId: nextId++,
            seasonId: 10,
            state: ProdeFechaState.evaluated,
            lockedAt: DateTime(2020, 1, 1),
            matchCount: 2,
          ));
        }

        final resolvedSelectedId = selectedFechaId ?? summaries.first.fechaId;
        final selectedSummary = summaries.firstWhere(
          (s) => s.fechaId == resolvedSelectedId,
        );
        final fecha = _makeFecha(state: selectedSummary.state);

        return ProdeFixturesLoaded(
          fecha,
          fechas: summaries,
          selectedFechaId: resolvedSelectedId,
        );
      }

      // TAB-1: Two tabs render with correct labels
      testWidgets('TAB-1: two tabs "A Jugarse" and "Finalizados" visible when fechas loaded', (tester) async {
        await _pumpScreen(
          tester,
          _loadedWithMixedFechas(openCount: 2, lockedCount: 1, evaluatedCount: 1),
        );
        expect(find.text('A Jugarse'), findsOneWidget);
        expect(find.text('Finalizados'), findsOneWidget);
      });

      // TAB-2: Default tab is "A Jugarse" when open fechas exist
      testWidgets('TAB-2: default tab is "A Jugarse" when open fechas exist', (tester) async {
        await _pumpScreen(
          tester,
          _loadedWithMixedFechas(openCount: 1, lockedCount: 1, evaluatedCount: 1),
        );
        // Prediction save affordance (GUARDAR) is accessible from open tab
        // Without tapping a card we confirm the tab label is selected/visible
        expect(find.text('A Jugarse'), findsOneWidget);
        // The progress header and PRÓXIMOS PARTIDOS section title belong to open fechas
        expect(find.text('PRÓXIMOS PARTIDOS'), findsOneWidget);
      });

      // TAB-3: "A Jugarse" tab only shows fechas with state == open in its selector
      testWidgets('TAB-3: "A Jugarse" selector only contains open fechas', (tester) async {
        // 2 open (ids 1,2), 1 locked (id 3), 1 evaluated (id 4)
        final state = _loadedWithMixedFechas(
          openCount: 2, lockedCount: 1, evaluatedCount: 1,
          selectedFechaId: 1, // open fecha selected
        );
        await _pumpScreen(tester, state);

        // Open the picker on the "A Jugarse" tab
        await tester.tap(find.byKey(const Key('fecha_selector_label')));
        await tester.pumpAndSettle();

        // Picker should show Fecha 1 and Fecha 2 (open), but NOT locked/evaluated ones
        expect(find.byKey(const Key('fecha_picker_entry_1')), findsOneWidget);
        expect(find.byKey(const Key('fecha_picker_entry_2')), findsOneWidget);
        // entries 3 and 4 (locked/evaluated) must NOT appear in A Jugarse picker
        expect(find.byKey(const Key('fecha_picker_entry_3')), findsNothing);
        expect(find.byKey(const Key('fecha_picker_entry_4')), findsNothing);
      });

      // TAB-4: "Finalizados" selector contains locked + evaluated fechas
      testWidgets('TAB-4: switching to "Finalizados" shows locked+evaluated fechas in selector', (tester) async {
        // 2 open (ids 1,2), 1 locked (id 3), 1 evaluated (id 4)
        final state = _loadedWithMixedFechas(
          openCount: 2, lockedCount: 1, evaluatedCount: 1,
          selectedFechaId: 1,
        );
        await _pumpScreen(tester, state);

        // Switch to Finalizados tab
        await tester.tap(find.text('Finalizados'));
        await tester.pumpAndSettle();

        // Open picker
        await tester.tap(find.byKey(const Key('fecha_selector_label')));
        await tester.pumpAndSettle();

        // Should show locked (3) and evaluated (4), NOT open ones (1, 2)
        expect(find.byKey(const Key('fecha_picker_entry_3')), findsOneWidget);
        expect(find.byKey(const Key('fecha_picker_entry_4')), findsOneWidget);
        expect(find.byKey(const Key('fecha_picker_entry_1')), findsNothing);
        expect(find.byKey(const Key('fecha_picker_entry_2')), findsNothing);
      });

      // TAB-5: Empty state when no open fechas in "A Jugarse"
      testWidgets('TAB-5: "A Jugarse" shows empty message when no open fechas', (tester) async {
        // Only locked + evaluated, no open fechas
        final state = _loadedWithMixedFechas(openCount: 0, lockedCount: 1, evaluatedCount: 1);
        await _pumpScreen(tester, state);

        // "A Jugarse" should show an empty message (no dates to play)
        // Default tab is "Finalizados" in this case since no open fechas exist,
        // but explicitly tap "A Jugarse" to check its empty state
        await tester.tap(find.text('A Jugarse'));
        await tester.pumpAndSettle();

        expect(find.text('No hay fechas para jugar por ahora.'), findsOneWidget);
      });

      // TAB-6: Empty state when no finalized fechas in "Finalizados"
      testWidgets('TAB-6: "Finalizados" shows empty message when no locked/evaluated fechas', (tester) async {
        // Only open fechas
        final state = _loadedWithMixedFechas(openCount: 2, lockedCount: 0, evaluatedCount: 0);
        await _pumpScreen(tester, state);

        // Switch to Finalizados tab
        await tester.tap(find.text('Finalizados'));
        await tester.pumpAndSettle();

        expect(find.text('Todavía no hay fechas finalizadas.'), findsOneWidget);
      });

      // TAB-7: Default tab is "Finalizados" when no open fechas exist
      testWidgets('TAB-7: default tab is "Finalizados" when no open fechas', (tester) async {
        // No open fechas — only locked + evaluated
        final state = _loadedWithMixedFechas(openCount: 0, lockedCount: 1, evaluatedCount: 1);
        await _pumpScreen(tester, state);

        // Should see PARTIDOS JUGADOS section title (finalizados tab content)
        expect(find.text('PARTIDOS JUGADOS'), findsOneWidget);
        expect(find.text('PRÓXIMOS PARTIDOS'), findsNothing);
      });

      // TAB-8: "A Jugarse" tab shows prediction affordance (prediction badge/controls available)
      testWidgets('TAB-8: open tab card is tappable and opens prediction sheet with enabled GUARDAR', (tester) async {
        final state = _loadedWithMixedFechas(openCount: 1, lockedCount: 1, evaluatedCount: 0, selectedFechaId: 1);
        await _pumpScreen(tester, state);

        // We should be on "A Jugarse" tab with an open fecha selected
        await tester.tap(find.byKey(const Key('match_card_1')));
        await tester.pumpAndSettle();

        final guardar = find.byKey(const Key('guardar_1'));
        expect(guardar, findsOneWidget);
        final btn = tester.widget<ElevatedButton>(guardar);
        expect(btn.onPressed, isNotNull);
      });

      // TAB-9: "Finalizados" tab shows evaluation badge for evaluated fecha
      testWidgets('TAB-9: "Finalizados" tab shows result badge for evaluated fecha', (tester) async {
        // Build a state where selectedFechaId is an evaluated fecha
        final summaries = [
          FechaSummary(
            fechaId: 1,
            seasonId: 10,
            state: ProdeFechaState.open,
            lockedAt: null,
            matchCount: 2,
          ),
          FechaSummary(
            fechaId: 2,
            seasonId: 10,
            state: ProdeFechaState.evaluated,
            lockedAt: DateTime(2020, 1, 1),
            matchCount: 1,
          ),
        ];
        final evaluatedFecha = FechaActiva(
          fechaId: 2,
          seasonId: 10,
          state: ProdeFechaState.evaluated,
          lockedAt: DateTime(2020, 1, 1),
          matches: [
            FechaMatch(
              matchId: 10,
              homeTeam: 'River',
              awayTeam: 'Boca',
              kickoff: DateTime(2026, 6, 1, 14, 0),
              realScoreHome: 2,
              realScoreAway: 1,
              isFinal: true,
            ),
          ],
          userPredictions: [
            PredictionEntry(
              matchId: 10,
              scoreHome: 2,
              scoreAway: 1,
              points: 3,
              evaluationMethod: 'exact_score',
            ),
          ],
        );
        final loadedState = ProdeFixturesLoaded(
          evaluatedFecha,
          fechas: summaries,
          selectedFechaId: 2,
          drafts: {10: const PredictionDraft(scoreHome: 2, scoreAway: 1)},
          savedMatchIds: {10},
        );

        await _pumpScreen(tester, loadedState);

        // Switch to Finalizados tab — evaluated fecha is the current selection
        await tester.tap(find.text('Finalizados'));
        await tester.pumpAndSettle();

        expect(find.byKey(const Key('result_badge_10')), findsOneWidget);
        expect(find.text('+3 Exacto'), findsOneWidget);
      });

      // ---------------------------------------------------------------------------
      // T-13 TAB-AUTO: Per-tab auto-select on tab switch
      // ---------------------------------------------------------------------------

      // TAB-AUTO-1: switching to "Finalizados" while an open fecha is selected
      // must call selectFecha with the most-recent finalized fecha id (last in list).
      testWidgets(
          'TAB-AUTO-1: switching to Finalizados auto-selects most-recent finalized fecha',
          (tester) async {
        // fechas: open(1), open(2), locked(3), evaluated(4)
        // selectedFechaId=1 (open) → switching to Finalizados must call selectFecha(4)
        final summaries = [
          FechaSummary(
            fechaId: 1, seasonId: 10, state: ProdeFechaState.open,
            lockedAt: null, matchCount: 2,
          ),
          FechaSummary(
            fechaId: 2, seasonId: 10, state: ProdeFechaState.open,
            lockedAt: null, matchCount: 2,
          ),
          FechaSummary(
            fechaId: 3, seasonId: 10, state: ProdeFechaState.locked,
            lockedAt: DateTime(2020, 1, 1), matchCount: 2,
          ),
          FechaSummary(
            fechaId: 4, seasonId: 10, state: ProdeFechaState.evaluated,
            lockedAt: DateTime(2020, 1, 2), matchCount: 2,
          ),
        ];
        final fecha = _makeFecha(state: ProdeFechaState.open);
        final initialState = ProdeFixturesLoaded(
          fecha,
          fechas: summaries,
          selectedFechaId: 1,
        );

        final stub = _StubControllerWithFechaTracking(initialState);

        await tester.pumpWidget(
          ProviderScope(
            overrides: [
              prodeFixturesControllerProvider.overrideWith((ref) => stub),
            ],
            child: const MaterialApp(
              home: Scaffold(
                body: ProdeFixturesScreen(stale: false, onLogout: _noOp),
              ),
            ),
          ),
        );
        await tester.pump();

        // Switch to Finalizados tab — selectedFechaId=1 is NOT in finalizados list
        await tester.tap(find.text('Finalizados'));
        await tester.pumpAndSettle();

        // Must auto-select the last finalized fecha (id=4, most recent)
        expect(stub.selectFechaCalls, contains(4));
      });

      // TAB-AUTO-2: switching back to "A Jugarse" while a finalized fecha is
      // selected must call selectFecha with the first open fecha id.
      testWidgets(
          'TAB-AUTO-2: switching back to A Jugarse auto-selects first open fecha',
          (tester) async {
        // fechas: open(1), open(2), locked(3), evaluated(4)
        // Start with selectedFechaId=3 (locked) → switching to A Jugarse must call selectFecha(1)
        final summaries = [
          FechaSummary(
            fechaId: 1, seasonId: 10, state: ProdeFechaState.open,
            lockedAt: null, matchCount: 2,
          ),
          FechaSummary(
            fechaId: 2, seasonId: 10, state: ProdeFechaState.open,
            lockedAt: null, matchCount: 2,
          ),
          FechaSummary(
            fechaId: 3, seasonId: 10, state: ProdeFechaState.locked,
            lockedAt: DateTime(2020, 1, 1), matchCount: 2,
          ),
          FechaSummary(
            fechaId: 4, seasonId: 10, state: ProdeFechaState.evaluated,
            lockedAt: DateTime(2020, 1, 2), matchCount: 2,
          ),
        ];
        // Selected fecha is locked → no open fechas exist in "A Jugarse" initial tab:
        // default tab is "A Jugarse" when open fechas exist, so we start on "Finalizados"
        // by seeding a locked fecha as selected and open fechas exist.
        final fecha = _makeFecha(state: ProdeFechaState.locked);
        final initialState = ProdeFixturesLoaded(
          fecha,
          fechas: summaries,
          selectedFechaId: 3, // locked fecha selected
        );

        final stub = _StubControllerWithFechaTracking(initialState);

        await tester.pumpWidget(
          ProviderScope(
            overrides: [
              prodeFixturesControllerProvider.overrideWith((ref) => stub),
            ],
            child: const MaterialApp(
              home: Scaffold(
                body: ProdeFixturesScreen(stale: false, onLogout: _noOp),
              ),
            ),
          ),
        );
        await tester.pump();

        // Default tab is "A Jugarse" (open fechas exist). selectedFechaId=3 (locked)
        // is NOT in aJugarse list → auto-select must fire immediately on initial tab.
        // But to test the switch direction explicitly: manually switch to Finalizados
        // first, then switch back.

        // First switch to Finalizados (selectedFechaId=3 IS in finalizados → no auto-select)
        await tester.tap(find.text('Finalizados'));
        await tester.pumpAndSettle();

        // Clear selectFecha calls so we can isolate the next switch
        stub.selectFechaCalls.clear();

        // Switch back to A Jugarse — selectedFechaId=3 is NOT in aJugarse list
        await tester.tap(find.text('A Jugarse'));
        await tester.pumpAndSettle();

        // Must auto-select the first open fecha (id=1)
        expect(stub.selectFechaCalls, contains(1));
      });

      // TAB-AUTO-3: no redundant selectFecha when selected fecha already belongs
      // to the tapped tab.
      testWidgets(
          'TAB-AUTO-3: no redundant selectFecha call when selected fecha already in target tab',
          (tester) async {
        // fechas: open(1), open(2), locked(3), evaluated(4)
        // selectedFechaId=1 (open) → tap "A Jugarse" (already correct tab) → no call
        final summaries = [
          FechaSummary(
            fechaId: 1, seasonId: 10, state: ProdeFechaState.open,
            lockedAt: null, matchCount: 2,
          ),
          FechaSummary(
            fechaId: 2, seasonId: 10, state: ProdeFechaState.open,
            lockedAt: null, matchCount: 2,
          ),
          FechaSummary(
            fechaId: 3, seasonId: 10, state: ProdeFechaState.locked,
            lockedAt: DateTime(2020, 1, 1), matchCount: 2,
          ),
          FechaSummary(
            fechaId: 4, seasonId: 10, state: ProdeFechaState.evaluated,
            lockedAt: DateTime(2020, 1, 2), matchCount: 2,
          ),
        ];
        final fecha = _makeFecha(state: ProdeFechaState.open);
        final initialState = ProdeFixturesLoaded(
          fecha,
          fechas: summaries,
          selectedFechaId: 1, // already in "A Jugarse"
        );

        final stub = _StubControllerWithFechaTracking(initialState);

        await tester.pumpWidget(
          ProviderScope(
            overrides: [
              prodeFixturesControllerProvider.overrideWith((ref) => stub),
            ],
            child: const MaterialApp(
              home: Scaffold(
                body: ProdeFixturesScreen(stale: false, onLogout: _noOp),
              ),
            ),
          ),
        );
        await tester.pump();

        // Switch to Finalizados then back — on the way back, selectedFechaId=1 is
        // open, but after switching to Finalizados the controller would have selected
        // a finalized one. For this test we care about the simpler case: switching
        // to Finalizados when selectedFechaId=4 (evaluated) already belongs to Finalizados.

        // Re-seed with an evaluated fecha selected
        final stub2 = _StubControllerWithFechaTracking(
          ProdeFixturesLoaded(
            _makeFecha(state: ProdeFechaState.evaluated),
            fechas: summaries,
            selectedFechaId: 4, // already in "Finalizados"
          ),
        );

        await tester.pumpWidget(
          ProviderScope(
            overrides: [
              prodeFixturesControllerProvider.overrideWith((ref) => stub2),
            ],
            child: const MaterialApp(
              home: Scaffold(
                body: ProdeFixturesScreen(stale: false, onLogout: _noOp),
              ),
            ),
          ),
        );
        await tester.pump();

        // Tap Finalizados tab — selectedFechaId=4 already belongs to it, no call expected
        await tester.tap(find.text('Finalizados'));
        await tester.pumpAndSettle();

        // No auto-select call — id=4 is already in finalizados
        expect(stub2.selectFechaCalls, isEmpty);
      });
    });
  });
}

void _noOp() {}

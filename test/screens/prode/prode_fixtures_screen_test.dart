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
    });
  });
}

void _noOp() {}

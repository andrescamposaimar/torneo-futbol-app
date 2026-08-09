import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/models/fecha_activa.dart';
import 'package:torneo_futbol_app/models/prediction_history.dart';
import 'package:torneo_futbol_app/providers/prode_providers.dart';
import 'package:torneo_futbol_app/screens/prode/prode_fixtures_screen.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';
import 'package:torneo_futbol_app/services/prode_history_controller.dart';

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

/// Fake service whose `fetchFechaById` is driven by the test: it either returns
/// [fecha] or throws, and records every id it was asked for.
class _FakeApiService extends ProdeApiService {
  _FakeApiService({this.fecha, this.shouldFail = false})
      : super(
          config: const ProdeAuthConfig(
            prodeApiBaseUrl: 'https://nowhere.test',
            googleWebClientId: 'test',
            appleTeamId: 'TEST',
          ),
          authRepo: ProdeAuthRepository(),
        );

  FechaActiva? fecha;
  bool shouldFail;

  final List<int> fetchedFechaIds = [];

  @override
  Future<FechaActiva> fetchFechaById(int id) async {
    fetchedFechaIds.add(id);
    if (shouldFail) {
      throw Exception('boom');
    }
    return fecha!;
  }
}

/// History controller seeded with a fixed state; load/refresh are no-ops so no
/// network call happens on mount.
class _StubHistoryController extends ProdeHistoryController {
  _StubHistoryController(ProdeHistoryState initialState)
      : super(_FakeApiService()) {
    state = initialState;
  }

  @override
  Future<void> load() async {}

  @override
  Future<void> refresh() async {}

  @override
  Future<void> loadMore() async {}
}

// ---------------------------------------------------------------------------
// Builders
// ---------------------------------------------------------------------------

const int _matchId = 501;
const int _fechaId = 42;

PredictionHistoryEntry _makeEntry({
  int? points = 3,
  String? evaluationMethod = 'exact_score',
}) {
  return PredictionHistoryEntry(
    fechaId: _fechaId,
    seasonId: 7,
    matchId: _matchId,
    kickoff: DateTime(2026, 6, 7, 14, 0),
    zona: 'Zona A',
    homeTeam: 'Team A',
    awayTeam: 'Team B',
    scoreHome: 2,
    scoreAway: 1,
    realScoreHome: 2,
    realScoreAway: 1,
    points: points,
    evaluationMethod: evaluationMethod,
  );
}

/// A fecha payload containing [_matchId] plus an unrelated match, so the sheet
/// has to select by match_id rather than take the first entry.
FechaActiva _makeFecha({Populares? populares}) {
  return FechaActiva(
    fechaId: _fechaId,
    seasonId: 7,
    state: ProdeFechaState.evaluated,
    lockedAt: null,
    matches: [
      FechaMatch(
        matchId: 999,
        homeTeam: 'Other C',
        awayTeam: 'Other D',
        kickoff: DateTime(2026, 6, 7, 16, 0),
        populares: const Populares(home: 10, draw: 20, away: 70),
      ),
      FechaMatch(
        matchId: _matchId,
        homeTeam: 'Team A',
        awayTeam: 'Team B',
        kickoff: DateTime(2026, 6, 7, 14, 0),
        populares: populares,
      ),
    ],
  );
}

Future<_FakeApiService> _pumpHistory(
  WidgetTester tester, {
  required _FakeApiService api,
  PredictionHistoryEntry? entry,
}) async {
  final state = ProdeHistoryState(
    phase: ProdeHistoryPhase.ready,
    items: [entry ?? _makeEntry()],
    loadedPages: 1,
  );

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        prodeApiServiceProvider.overrideWithValue(api),
        prodeHistoryControllerProvider
            .overrideWith((ref) => _StubHistoryController(state)),
      ],
      child: MaterialApp(
        home: Scaffold(
          body: ProdeHistoryList(onLogout: () {}),
        ),
      ),
    ),
  );
  await tester.pump(); // settle the initState microtask
  return api;
}

Future<void> _openSheet(WidgetTester tester) async {
  await tester.tap(find.byKey(const Key('match_card_$_matchId')));
  await tester.pumpAndSettle();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

void main() {
  group('ProdeHistoryList — card tap', () {
    testWidgets('tapping a history card opens the read-only sheet',
        (tester) async {
      final api = _FakeApiService(
        fecha: _makeFecha(populares: const Populares(home: 50, draw: 30, away: 20)),
      );
      await _pumpHistory(tester, api: api);

      expect(find.text('Tu pronóstico'), findsNothing);

      await _openSheet(tester);

      expect(find.text('Tu pronóstico'), findsOneWidget);
      expect(find.byKey(const Key('history_cerrar_$_matchId')), findsOneWidget);
    });

    testWidgets('sheet is read-only: no steppers, no GUARDAR', (tester) async {
      final api = _FakeApiService(
        fecha: _makeFecha(populares: const Populares(home: 50, draw: 30, away: 20)),
      );
      await _pumpHistory(tester, api: api);
      await _openSheet(tester);

      expect(find.byKey(const Key('guardar_$_matchId')), findsNothing);
      expect(
        find.byKey(const Key('stepper_home_plus_$_matchId')),
        findsNothing,
      );
      expect(
        find.byKey(const Key('stepper_away_minus_$_matchId')),
        findsNothing,
      );
    });
  });

  group('ProdeHistoryList — populares in the sheet', () {
    testWidgets('fetches the entry fecha and reveals the percentages',
        (tester) async {
      final api = _FakeApiService(
        fecha: _makeFecha(
          populares: const Populares(home: 63.4, draw: 26.6, away: 10.0),
        ),
      );
      await _pumpHistory(tester, api: api);
      await _openSheet(tester);

      expect(api.fetchedFechaIds, [_fechaId]);
      expect(
        find.byKey(const Key('populares_section_$_matchId')),
        findsOneWidget,
      );
      // Rounded, single ×100 — the wire value is already a percentage.
      expect(find.text('63%'), findsOneWidget);
      expect(find.text('27%'), findsOneWidget);
      expect(find.text('10%'), findsOneWidget);
      expect(find.byKey(const Key('populares_locked_hint')), findsNothing);
    });

    testWidgets('picks populares by match_id, not the first match',
        (tester) async {
      final api = _FakeApiService(
        fecha: _makeFecha(
          populares: const Populares(home: 63.4, draw: 26.6, away: 10.0),
        ),
      );
      await _pumpHistory(tester, api: api);
      await _openSheet(tester);

      // The other match in the payload is 10/20/70 — none of those may leak.
      expect(find.text('70%'), findsNothing);
      expect(find.text('20%'), findsNothing);
    });

    testWidgets('no populares for the match -> played-match hint, not the '
        '"cierra la fecha" copy', (tester) async {
      final api = _FakeApiService(fecha: _makeFecha(populares: null));
      await _pumpHistory(tester, api: api);
      await _openSheet(tester);

      expect(
        find.byKey(const Key('populares_locked_hint')),
        findsOneWidget,
      );
      expect(
        find.text('No hay datos de pronósticos para este partido'),
        findsOneWidget,
      );
      expect(find.text('Se revelan cuando cierra la fecha'), findsNothing);
    });

    testWidgets('fetch failure -> inline retry; retrying loads the percentages',
        (tester) async {
      final api = _FakeApiService(shouldFail: true);
      await _pumpHistory(tester, api: api);
      await _openSheet(tester);

      expect(
        find.byKey(const Key('history_populares_retry')),
        findsOneWidget,
      );
      // The prediction/result data comes from the entry, so it still renders.
      expect(
        find.byKey(const Key('history_real_score_$_matchId')),
        findsOneWidget,
      );

      api
        ..shouldFail = false
        ..fecha = _makeFecha(
          populares: const Populares(home: 63.4, draw: 26.6, away: 10.0),
        );

      await tester.tap(find.byKey(const Key('history_populares_retry')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('history_populares_retry')), findsNothing);
      expect(find.text('63%'), findsOneWidget);
      expect(api.fetchedFechaIds, [_fechaId, _fechaId]);
    });
  });

  group('ProdeHistoryList — result and points in the sheet', () {
    testWidgets('shows the official result and the evaluation badge',
        (tester) async {
      final api = _FakeApiService(
        fecha: _makeFecha(populares: const Populares(home: 50, draw: 30, away: 20)),
      );
      await _pumpHistory(tester, api: api);
      await _openSheet(tester);

      expect(find.text('Resultado: 2 - 1'), findsWidgets);
      expect(
        find.byKey(const Key('history_result_badge_$_matchId')),
        findsOneWidget,
      );
    });

    testWidgets('not yet evaluated -> no points badge', (tester) async {
      final api = _FakeApiService(
        fecha: _makeFecha(populares: const Populares(home: 50, draw: 30, away: 20)),
      );
      await _pumpHistory(
        tester,
        api: api,
        entry: _makeEntry(points: null, evaluationMethod: null),
      );
      await _openSheet(tester);

      expect(
        find.byKey(const Key('history_result_badge_$_matchId')),
        findsNothing,
      );
    });
  });
}

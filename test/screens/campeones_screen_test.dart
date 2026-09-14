import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/providers/service_providers.dart';
import 'package:torneo_futbol_app/screens/campeones_screen.dart';
import 'package:torneo_futbol_app/services/i_api_service.dart';
import 'package:torneo_futbol_app/services/i_cache_service.dart';

// ---------------------------------------------------------------------------
// Stubs
// ---------------------------------------------------------------------------

/// `noSuchMethod` covers the rest of the interface: CampeonesScreen only
/// ever reaches for the championship history.
class _StubApiService implements IApiService {
  final List<Map<String, dynamic>>? historia;
  final bool shouldThrow;

  _StubApiService({this.historia, this.shouldThrow = false});

  @override
  Future<List<dynamic>> getCampeonesHistoria() async {
    if (shouldThrow) throw Exception('campeones historia endpoint down');
    return historia ?? [];
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

/// Every getCached* call is a deterministic cache miss; every write is a
/// no-op — keeps tests exercising the stub API directly.
class _NoopCacheService implements ICacheService {
  @override
  dynamic noSuchMethod(Invocation invocation) => Future.value(null);
}

/// A fresh-cache miss whose stale (TTL-ignoring) copy is [stale] — used to
/// prove the down-endpoint-with-stale-cache degrade-gracefully path.
class _StaleCacheService implements ICacheService {
  final List<dynamic> stale;

  _StaleCacheService(this.stale);

  @override
  Future<List<dynamic>?> getCachedCampeonesHistoria() async => null;

  @override
  Future<List<dynamic>?> getCachedCampeonesHistoriaIgnoringTtl() async => stale;

  @override
  Future<void> cacheCampeonesHistoria(List<dynamic> titulos) async {}

  @override
  dynamic noSuchMethod(Invocation invocation) => Future.value(null);
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

Map<String, dynamic> _entry({
  required String nombre,
  bool esCapitan = false,
  int? jugadorId,
  String? fotoUrl,
}) =>
    {
      'nombre': nombre,
      'es_capitan': esCapitan,
      'jugador_id': jugadorId,
      'foto_url': fotoUrl,
    };

Map<String, dynamic> _titulo({
  required int anio,
  String zona = 'A',
  String posicion = '1',
  required String equipo,
  List<Map<String, dynamic>> plantel = const [],
}) =>
    {
      'anio': anio,
      'zona': zona,
      'posicion': posicion,
      'equipo_nombre': equipo,
      'plantel': plantel,
    };

/// A text finder that does not skip offstage elements — used to assert real
/// removal from the element tree (a collapsed `ExpansionTile` with
/// `maintainState: false`), which the default `find.text` (`skipOffstage:
/// true`) cannot distinguish from "built but merely painted offstage".
Finder _notOffstage(String text) => find.text(text, skipOffstage: false);

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

Future<void> _pump(
  WidgetTester tester, {
  List<Map<String, dynamic>>? historia,
  bool shouldThrow = false,
  ICacheService? cache,
  int? initialAnio,
  String? initialZona,
  bool settle = true,
}) async {
  tester.view.physicalSize = const Size(360, 800);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        apiServiceProvider.overrideWithValue(
          _StubApiService(historia: historia, shouldThrow: shouldThrow),
        ),
        cacheServiceProvider.overrideWithValue(cache ?? _NoopCacheService()),
      ],
      child: MaterialApp(
        home: CampeonesScreen(initialAnio: initialAnio, initialZona: initialZona),
      ),
    ),
  );
  if (settle) await tester.pumpAndSettle();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

void main() {
  group('CampeonesScreen · loading state', () {
    testWidgets('shows a spinner while the fetch is in flight', (tester) async {
      await _pump(tester, historia: [], settle: false);

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });
  });

  group('CampeonesScreen · empty state (APP-6)', () {
    testWidgets('an empty history renders the explanatory state, never a blank list',
        (tester) async {
      await _pump(tester, historia: const []);

      expect(find.text('Todavía no hay campeones cargados'), findsOneWidget);
      expect(
        find.textContaining('vas a poder ver acá todos los campeones'),
        findsOneWidget,
      );
      expect(find.byIcon(Icons.emoji_events_outlined), findsOneWidget);
      expect(find.byType(ListView), findsNothing);
    });
  });

  group('CampeonesScreen · error state', () {
    testWidgets(
        'a down endpoint with no cache at all shows an error state with a '
        'working retry, and reports the failure',
        (tester) async {
      final messages = <String>[];
      final originalDebugPrint = debugPrint;
      debugPrint = (String? message, {int? wrapWidth}) {
        if (message != null) messages.add(message);
      };

      try {
        await _pump(tester, shouldThrow: true);

        expect(find.byIcon(Icons.error_outline), findsOneWidget);
        expect(find.text('Reintentar'), findsOneWidget);
        // Never silently swallowed (no bare catch (_) {}) — reported through
        // the shared non-fatal error path exactly like the títulos panel.
        expect(
          messages.any(
            (m) => m.contains('CampeonesScreen: getCampeonesHistoria failed'),
          ),
          isTrue,
          reason: 'expected reportNonFatal to fire; got: $messages',
        );

        // Retry works: tapping it re-runs the load without crashing (the
        // same throwing stub is still wired, so it ends back on the error
        // state — the point here is that _load() runs again cleanly).
        await tester.tap(find.text('Reintentar'));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
        expect(find.byIcon(Icons.error_outline), findsOneWidget);
      } finally {
        debugPrint = originalDebugPrint;
      }
    });

    testWidgets(
        'a down endpoint WITH a stale cache degrades gracefully to the stale '
        'data instead of the error state',
        (tester) async {
      final stale = [
        _titulo(anio: 2016, zona: 'A', equipo: 'CHELSEA'),
      ];

      await _pump(tester, shouldThrow: true, cache: _StaleCacheService(stale));

      expect(find.byIcon(Icons.error_outline), findsNothing);
      expect(find.text('CHELSEA'), findsOneWidget);
    });
  });

  group('CampeonesScreen · loaded state and accordion', () {
    List<Map<String, dynamic>> threeYears() => [
          _titulo(
            anio: 2023,
            equipo: 'LIVERPOOL',
            plantel: [_entry(nombre: 'JUGADOR_2023_A')],
          ),
          _titulo(
            anio: 2019,
            equipo: 'ARSENAL',
            plantel: [_entry(nombre: 'JUGADOR_2019_A')],
          ),
          _titulo(
            anio: 2016,
            equipo: 'CHELSEA',
            plantel: [_entry(nombre: 'JUGADOR_2016_A')],
          ),
        ];

    testWidgets(
        'the most recent year starts expanded; the rest start collapsed and '
        'are not in the widget tree', (tester) async {
      await _pump(tester, historia: threeYears());

      expect(find.text('JUGADOR_2023_A'), findsOneWidget);
      // `skipOffstage: false` is deliberate: `ExpansionTile.maintainState`
      // defaults to false, so a collapsed year's squad rows are REMOVED
      // from the element tree, not merely painted offstage. The default
      // `find.text` finder (`skipOffstage: true`) would pass this
      // assertion even if the rows were kept in the tree but hidden via
      // `Offstage` — asserting with `skipOffstage: false` (which does not
      // skip offstage elements either) closes that gap: if this ever
      // regressed to `maintainState: true`, the widgets would still be
      // found and this assertion would correctly fail.
      expect(_notOffstage('JUGADOR_2019_A'), findsNothing);
      expect(_notOffstage('JUGADOR_2016_A'), findsNothing);
    });

    testWidgets(
        'accordion invariant: opening year B collapses year A',
        (tester) async {
      await _pump(tester, historia: threeYears());

      expect(find.text('JUGADOR_2023_A'), findsOneWidget);

      await tester.tap(find.text('ARSENAL'));
      await tester.pumpAndSettle();

      expect(find.text('JUGADOR_2019_A'), findsOneWidget);
      // The previously-open year collapses and leaves the tree entirely
      // (see the `skipOffstage: false` note above).
      expect(_notOffstage('JUGADOR_2023_A'), findsNothing);
      expect(_notOffstage('JUGADOR_2016_A'), findsNothing);
    });

    testWidgets('tapping the open year closes it (no year open at all)',
        (tester) async {
      await _pump(tester, historia: threeYears());

      await tester.tap(find.text('LIVERPOOL'));
      await tester.pumpAndSettle();

      expect(_notOffstage('JUGADOR_2023_A'), findsNothing);
      expect(_notOffstage('JUGADOR_2019_A'), findsNothing);
      expect(_notOffstage('JUGADOR_2016_A'), findsNothing);
    });

    testWidgets(
        'deep link: initialAnio/initialZona opens that year instead of the '
        'most recent one', (tester) async {
      await _pump(
        tester,
        historia: threeYears(),
        initialAnio: 2016,
        initialZona: 'A',
      );

      expect(find.text('JUGADOR_2016_A'), findsOneWidget);
      expect(_notOffstage('JUGADOR_2023_A'), findsNothing);
    });
  });

  group('CampeonesScreen · squad row treatment (APP-7 / APP-5)', () {
    List<Map<String, dynamic>> oneYearMixedSquad() => [
          _titulo(
            anio: 2016,
            equipo: 'CHELSEA',
            plantel: [
              _entry(nombre: 'BASSO, A.', jugadorId: 42),
              _entry(nombre: 'MAZZARA, M.'),
            ],
          ),
        ];

    testWidgets(
        'a linked entry is an InkWell with a trailing chevron; an unlinked '
        'entry has neither', (tester) async {
      await _pump(tester, historia: oneYearMixedSquad());

      expect(
        find.ancestor(of: find.text('BASSO, A.'), matching: find.byType(InkWell)),
        findsOneWidget,
      );
      expect(
        find.ancestor(
          of: find.text('MAZZARA, M.'),
          matching: find.byType(InkWell),
        ),
        findsNothing,
      );
      expect(find.byIcon(Icons.chevron_right), findsOneWidget);
    });

    testWidgets(
        'APP-5: the unlinked row is not visually lesser — same name style '
        'and same row height as the linked row', (tester) async {
      await _pump(tester, historia: oneYearMixedSquad());

      final linkedText = tester.widget<Text>(find.text('BASSO, A.'));
      final unlinkedText = tester.widget<Text>(find.text('MAZZARA, M.'));
      expect(linkedText.style?.fontSize, unlinkedText.style?.fontSize);

      final linkedRowHeight = tester
          .getSize(
            find.ancestor(of: find.text('BASSO, A.'), matching: find.byType(Row)).first,
          )
          .height;
      final unlinkedRowHeight = tester
          .getSize(
            find
                .ancestor(of: find.text('MAZZARA, M.'), matching: find.byType(Row))
                .first,
          )
          .height;
      expect(linkedRowHeight, unlinkedRowHeight);
    });

    testWidgets('the captain marker (C) is still shown on this screen',
        (tester) async {
      await _pump(
        tester,
        historia: [
          _titulo(
            anio: 2016,
            equipo: 'CHELSEA',
            plantel: [_entry(nombre: 'MAZZARA, M.', esCapitan: true)],
          ),
        ],
      );

      expect(find.text('MAZZARA, M. (C)'), findsOneWidget);
    });

    testWidgets('tapping a linked row navigates to PlayerDetailScreen',
        (tester) async {
      await _pump(tester, historia: oneYearMixedSquad());

      await tester.tap(find.text('BASSO, A.'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.text('Detalles'), findsOneWidget); // PlayerDetailScreen tab
    });
  });
}

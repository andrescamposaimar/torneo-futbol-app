import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/providers/service_providers.dart';
import 'package:torneo_futbol_app/screens/player_detail_screen.dart';
import 'package:torneo_futbol_app/services/i_api_service.dart';
import 'package:torneo_futbol_app/services/i_cache_service.dart';

// ---------------------------------------------------------------------------
// Stubs
// ---------------------------------------------------------------------------

/// `noSuchMethod` covers the rest of the interface: this screen only ever
/// reaches for the player, their matches and their titles.
class _StubApiService implements IApiService {
  final List<Map<String, dynamic>>? titulos;
  final bool titulosShouldThrow;

  _StubApiService({this.titulos, this.titulosShouldThrow = false});

  @override
  Future<Map<String, dynamic>> getJugadorPorId(int id) async => {
        'id': id,
        'title': {'rendered': 'Juan Pérez'},
        'featured_image': null,
        'posicion': 'Mediocampista',
        // No equipo, so the hero's team chip (and its own chevron_right)
        // never renders — keeps the chevron/InkWell assertions below
        // scoped to the titles panel alone.
        'equipo': 'Sin equipo',
        'escudo': '',
        'fecha_nacimiento': '2010-03-14',
        'temporadas': ['2024', '2025', '2026'],
        'metrics': {'puntaje': '7,5'},
      };

  @override
  Future<Map<String, dynamic>> getPartidosPorJugador(
    int jugadorId, {
    int? page,
    int? perPage,
  }) async =>
      {'items': [], 'current_page': 1, 'total_pages': 0};

  @override
  Future<Map<String, dynamic>> getTitulosDeJugador(int jugadorId) async {
    if (titulosShouldThrow) throw Exception('campeones endpoint down');
    return {
      'jugador_id': jugadorId,
      'total': titulos?.length ?? 0,
      'titulos': titulos ?? [],
    };
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

/// An "un-updated stub" — deliberately does NOT override getTitulosDeJugador,
/// so noSuchMethod's synchronous throw fires the instant the method is
/// invoked (not at await time). Exercises the exact trap the loading fix
/// guards against: if titulosFuture were created outside its own try/catch
/// (like jugadorFuture is), this throw would escape to the outer catch and
/// render the whole screen's error state instead of just omitting the panel.
class _UnimplementedTitulosApiService implements IApiService {
  @override
  Future<Map<String, dynamic>> getJugadorPorId(int id) async => {
        'id': id,
        'title': {'rendered': 'Juan Pérez'},
        'featured_image': null,
        'posicion': 'Mediocampista',
        'equipo': 'Sin equipo',
        'escudo': '',
        'temporadas': [],
        'metrics': {'puntaje': '7,5'},
      };

  @override
  Future<Map<String, dynamic>> getPartidosPorJugador(
    int jugadorId, {
    int? page,
    int? perPage,
  }) async =>
      {'items': [], 'current_page': 1, 'total_pages': 0};

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

/// A titles fetch that never resolves — the campeones endpoint hanging, or
/// a very slow network. Proves the fetch runs concurrently with the rest of
/// the profile instead of gating it: awaiting this future before
/// `partidosFuture` (the deserialization bug the loading fix guards
/// against) would leave `isLoading` stuck forever, so the profile would
/// never render.
class _NeverCompletingTitulosApiService implements IApiService {
  @override
  Future<Map<String, dynamic>> getJugadorPorId(int id) async => {
        'id': id,
        'title': {'rendered': 'Juan Pérez'},
        'featured_image': null,
        'posicion': 'Mediocampista',
        'equipo': 'Sin equipo',
        'escudo': '',
        'temporadas': [],
        'metrics': {'puntaje': '7,5'},
      };

  @override
  Future<Map<String, dynamic>> getPartidosPorJugador(
    int jugadorId, {
    int? page,
    int? perPage,
  }) async =>
      {'items': [], 'current_page': 1, 'total_pages': 0};

  @override
  Future<Map<String, dynamic>> getTitulosDeJugador(int jugadorId) {
    return Completer<Map<String, dynamic>>().future;
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

/// Every getCached* call is a deterministic cache miss; every write is a
/// no-op. Keeps the titles fetch flow exercising the stub API directly in
/// tests, without depending on real shared_preferences plugin mocking.
class _NoopCacheService implements ICacheService {
  // Every ICacheService method returns a Future — a bare `null` fails the
  // implicit cast to Future<...> at the call site (Dart still checks the
  // declared return type even when the implementation comes from
  // noSuchMethod), so every fallback must be wrapped.
  @override
  dynamic noSuchMethod(Invocation invocation) => Future.value(null);
}

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

Future<void> _pump(
  WidgetTester tester, {
  required Size size,
  List<Map<String, dynamic>>? titulos,
  bool titulosShouldThrow = false,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        apiServiceProvider.overrideWithValue(
          _StubApiService(titulos: titulos, titulosShouldThrow: titulosShouldThrow),
        ),
        cacheServiceProvider.overrideWithValue(_NoopCacheService()),
      ],
      child: MaterialApp(
        home: PlayerDetailScreen(player: const {
          'id': 4321,
          'title': {'rendered': 'Juan Pérez'},
          'equipo': 'Sin equipo',
          'escudo': '',
          'temporadas': [],
          'metrics': {},
        }),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Map<String, dynamic> _titulo({int anio = 2016, String zona = 'A', String equipo = 'CHELSEA'}) =>
    {'anio': anio, 'zona': zona, 'equipo_nombre': equipo, 'es_capitan': false};

/// On a narrow screen the hero and OTROS DATOS fill the viewport, so the
/// titles panel is below the fold and the ListView has not built it yet.
Future<void> _scrollToTitulos(WidgetTester tester) async {
  for (var i = 0; i < 5; i++) {
    await tester.drag(find.byType(ListView).first, const Offset(0, -400));
    await tester.pumpAndSettle();
  }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

void main() {
  group('PlayerDetailScreen · hero puntaje icon', () {
    testWidgets('uses Icons.speed in the brand primary colour, not the amber star',
        (tester) async {
      await _pump(tester, size: const Size(320, 568));

      expect(find.byIcon(Icons.speed), findsOneWidget);
      expect(find.byIcon(Icons.star_rounded), findsNothing);
    });
  });

  group('PlayerDetailScreen · hero title stars', () {
    testWidgets('a player with zero titles shows no stars', (tester) async {
      await _pump(tester, size: const Size(320, 568), titulos: const []);

      expect(find.text('A'), findsNothing);
    });

    testWidgets('one gold star per title, with the zone letter inside',
        (tester) async {
      await _pump(
        tester,
        size: const Size(320, 568),
        titulos: [_titulo(anio: 2023), _titulo(anio: 2019), _titulo(anio: 2016)],
      );

      expect(find.byIcon(Icons.star_rounded), findsNWidgets(3));
      expect(find.text('A'), findsNWidgets(3));
    });

    testWidgets('on a 320px-wide screen the stars wrap below the puntaje pill',
        (tester) async {
      await _pump(
        tester,
        size: const Size(320, 568),
        titulos: [_titulo(anio: 2023), _titulo(anio: 2019), _titulo(anio: 2016)],
      );

      expect(tester.takeException(), isNull);

      // Three stars plus the pill do not fit on 320px — the pill and the
      // first star or two may still share the pill's line, but the LAST
      // star is guaranteed to overflow onto its own wrapped line.
      final pillTop = tester.getTopLeft(find.text('7.5')).dy;
      final lastStarTop = tester.getTopLeft(find.byIcon(Icons.star_rounded).last).dy;
      expect(lastStarTop, greaterThan(pillTop + 10));
    });

    testWidgets('on a wide screen the stars sit beside the puntaje pill',
        (tester) async {
      await _pump(
        tester,
        size: const Size(700, 800),
        titulos: [_titulo(anio: 2023), _titulo(anio: 2019), _titulo(anio: 2016)],
      );

      expect(tester.takeException(), isNull);

      // On a wide screen every star fits on the pill's own line — allow a
      // small tolerance for cross-axis centering between differently-sized
      // children on the same Wrap run.
      final pillTop = tester.getTopLeft(find.text('7.5')).dy;
      final lastStarTop = tester.getTopLeft(find.byIcon(Icons.star_rounded).last).dy;
      expect((lastStarTop - pillTop).abs(), lessThan(10));
    });
  });

  group('PlayerDetailScreen · títulos panel', () {
    testWidgets('a player with zero titles renders no panel at all',
        (tester) async {
      await _pump(tester, size: const Size(320, 568), titulos: const []);
      await _scrollToTitulos(tester);

      expect(find.text('TÍTULOS'), findsNothing);
      expect(find.textContaining('Campeón Zona'), findsNothing);
    });

    testWidgets('a player with one title shows the singular count',
        (tester) async {
      await _pump(tester, size: const Size(320, 568), titulos: [_titulo()]);
      await _scrollToTitulos(tester);

      expect(find.text('TÍTULOS'), findsOneWidget);
      expect(find.text('1 título'), findsOneWidget);
    });

    testWidgets('a player with titles shows year, team and zone per row',
        (tester) async {
      await _pump(
        tester,
        size: const Size(320, 568),
        titulos: [
          _titulo(anio: 2023, zona: 'A', equipo: 'LIVERPOOL'),
          _titulo(anio: 2016, zona: 'B', equipo: 'CHELSEA'),
        ],
      );
      await _scrollToTitulos(tester);

      expect(find.text('2 títulos'), findsOneWidget);
      expect(find.text('2023'), findsOneWidget);
      expect(find.text('LIVERPOOL'), findsOneWidget);
      expect(find.text('Campeón Zona A'), findsOneWidget);
      expect(find.text('2016'), findsOneWidget);
      expect(find.text('CHELSEA'), findsOneWidget);
      expect(find.text('Campeón Zona B'), findsOneWidget);
    });

    testWidgets('no star inside the panel, even for a captain title',
        (tester) async {
      await _pump(
        tester,
        size: const Size(320, 568),
        titulos: [
          {'anio': 2016, 'zona': 'A', 'equipo_nombre': 'CHELSEA', 'es_capitan': true},
        ],
      );
      await _scrollToTitulos(tester);

      // Scoped to the panel's own subtree (PlayerDetailScreen.titulosPanelKey)
      // rather than the whole screen: with 1 title the hero renders exactly
      // 1 star of its own right after the first pump, and the ListView's
      // cache extent unmounts that hero star's render object once
      // _scrollToTitulos() drags far enough — an unscoped
      // `find.byIcon(Icons.star_rounded)` would read as "no star" for that
      // unrelated reason, regardless of what the panel itself renders.
      //
      // esCapitan is parsed onto JugadorTitulo (see campeon_titulo.dart) but
      // deliberately never read by this screen — a future slice (the
      // champion-squad history screen) reads it instead. There is therefore
      // no '(C)' or similar captain marker produced anywhere in this
      // screen's code for this test to assert the absence of; a
      // `findsNothing` on a string that is never emitted regardless of
      // whether the captain flag is honoured would pass for the wrong
      // reason, so no such assertion is made here.
      expect(
        find.descendant(
          of: find.byKey(PlayerDetailScreen.titulosPanelKey),
          matching: find.byIcon(Icons.star_rounded),
        ),
        findsNothing,
      );
    });

    testWidgets('the team name is plain, non-tappable text with no chevron',
        (tester) async {
      await _pump(
        tester,
        size: const Size(320, 568),
        titulos: [_titulo(equipo: 'CHELSEA')],
      );
      await _scrollToTitulos(tester);

      expect(
        find.ancestor(of: find.text('CHELSEA'), matching: find.byType(InkWell)),
        findsNothing,
      );
      expect(find.byIcon(Icons.chevron_right), findsNothing);
    });

    testWidgets('a down campeones endpoint omits the panel without breaking the profile',
        (tester) async {
      await _pump(tester, size: const Size(320, 568), titulosShouldThrow: true);
      await _scrollToTitulos(tester);

      expect(find.text('TÍTULOS'), findsNothing);
      // The rest of the profile still works.
      expect(find.text('Juan Pérez'), findsOneWidget);
    });

    testWidgets(
        'an un-updated fake whose getTitulosDeJugador throws synchronously '
        'still renders the full profile',
        (tester) async {
      tester.view.physicalSize = const Size(320, 568);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            apiServiceProvider.overrideWithValue(_UnimplementedTitulosApiService()),
            cacheServiceProvider.overrideWithValue(_NoopCacheService()),
          ],
          child: MaterialApp(
            home: PlayerDetailScreen(player: const {
              'id': 4321,
              'title': {'rendered': 'Juan Pérez'},
              'equipo': 'Sin equipo',
              'escudo': '',
              'temporadas': [],
              'metrics': {},
            }),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.text('Juan Pérez'), findsWidgets);
      expect(find.text('TÍTULOS'), findsNothing);
      // Stronger signal than the name (already present before any fetch,
      // from widget.player itself): 'posicion' only reaches the screen via
      // a completed getJugadorPorId round-trip, which the outer catch would
      // skip entirely if the titles fetch escaped past its own try/catch.
      expect(find.text('Mediocampista'), findsOneWidget);
    });
  });

  group('PlayerDetailScreen · incomplete títulos', () {
    testWidgets(
        'a title with an empty zona is omitted from the hero star and the panel',
        (tester) async {
      await _pump(
        tester,
        size: const Size(320, 568),
        titulos: [
          {'anio': 2016, 'zona': '', 'equipo_nombre': 'CHELSEA', 'es_capitan': false},
        ],
      );
      await _scrollToTitulos(tester);

      // No dangling "Campeón Zona " label (trailing space, empty zone), and
      // no star with an empty letter inside — the only title is incomplete,
      // so nothing is rendered for it anywhere.
      expect(find.byIcon(Icons.star_rounded), findsNothing);
      expect(find.textContaining('Campeón Zona'), findsNothing);
      expect(find.text('TÍTULOS'), findsNothing);
      // The rest of the profile still works.
      expect(find.text('Juan Pérez'), findsOneWidget);
    });

    testWidgets(
        'a title with an empty equipo_nombre is omitted from the hero star and the panel',
        (tester) async {
      await _pump(
        tester,
        size: const Size(320, 568),
        titulos: [
          {'anio': 2016, 'zona': 'A', 'equipo_nombre': '', 'es_capitan': false},
        ],
      );
      await _scrollToTitulos(tester);

      // No empty bold headline, and no star for a title this incomplete.
      expect(find.byIcon(Icons.star_rounded), findsNothing);
      expect(find.text('TÍTULOS'), findsNothing);
      expect(find.text('Juan Pérez'), findsOneWidget);
    });

    testWidgets(
        'a mix of one valid and one incomplete title renders only the valid '
        'one, with a matching star count and header count',
        (tester) async {
      await _pump(
        tester,
        size: const Size(320, 568),
        titulos: [
          _titulo(anio: 2023, zona: 'A', equipo: 'LIVERPOOL'),
          {'anio': 2016, 'zona': '', 'equipo_nombre': 'CHELSEA', 'es_capitan': false},
        ],
      );

      // The hero star is asserted BEFORE scrolling: _scrollToTitulos()'s
      // drag unmounts the hero star's render object past the ListView's
      // cache extent (see the panel-scoped star test in the títulos panel
      // group above), so this assertion would read as "no star" for that
      // unrelated reason if it ran after the scroll.
      expect(find.byIcon(Icons.star_rounded), findsOneWidget);

      await _scrollToTitulos(tester);

      expect(find.text('1 título'), findsOneWidget);
      expect(find.text('LIVERPOOL'), findsOneWidget);
      expect(find.text('CHELSEA'), findsNothing);
    });
  });

  group('PlayerDetailScreen · títulos fetch concurrency', () {
    testWidgets(
        'a títulos fetch that never completes does not block the rest of '
        'the profile from loading',
        (tester) async {
      tester.view.physicalSize = const Size(320, 568);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            apiServiceProvider
                .overrideWithValue(_NeverCompletingTitulosApiService()),
            cacheServiceProvider.overrideWithValue(_NoopCacheService()),
          ],
          child: MaterialApp(
            home: PlayerDetailScreen(player: const {
              'id': 4321,
              'title': {'rendered': 'Juan Pérez'},
              'equipo': 'Sin equipo',
              'escudo': '',
              'temporadas': [],
              'metrics': {},
            }),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      // If the títulos fetch were awaited before the rest of the profile
      // (serialized instead of concurrent), isLoading would never clear —
      // the spinner tested below would still be on screen, forever, since
      // this fake's future never resolves.
      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(find.text('Juan Pérez'), findsWidgets);
      // Stronger signal than the name: 'Mediocampista' only reaches the
      // screen via a completed getJugadorPorId round-trip inside the same
      // _fetchInitialData call — proving the whole method actually ran to
      // completion (hit its `finally`) rather than getting stuck awaiting
      // the títulos future.
      expect(find.text('Mediocampista'), findsOneWidget);
    });
  });

  group('PlayerDetailScreen · títulos fetch failure reporting', () {
    testWidgets(
        'a títulos fetch failure is reported through the shared non-fatal '
        'error path instead of being silently swallowed',
        (tester) async {
      final messages = <String>[];
      final originalDebugPrint = debugPrint;
      debugPrint = (String? message, {int? wrapWidth}) {
        if (message != null) messages.add(message);
      };

      // Restored before the test body returns (not via addTearDown): the
      // test binding asserts foundation debug variables are back to their
      // defaults as soon as the test body completes, before tearDowns run.
      try {
        await _pump(tester,
            size: const Size(320, 568), titulosShouldThrow: true);
        await _scrollToTitulos(tester);

        // No Firebase app is initialized in the widget-test environment, so
        // reportNonFatal()'s own internal try/catch (lib/utils/error_reporting.dart)
        // takes its debugPrint fallback branch. Asserting on that fallback
        // message is the cheapest seam available to prove the títulos-fetch
        // failure actually reaches reportNonFatal() with a recognisable
        // reason, rather than being swallowed by a bare `catch (_) {}` with
        // no trace at all.
        expect(
          messages.any(
            (m) => m.contains('getTitulosDeJugador failed for player 4321'),
          ),
          isTrue,
          reason: 'expected reportNonFatal\'s debugPrint fallback to fire '
              'with the títulos-fetch failure reason; got: $messages',
        );
      } finally {
        debugPrint = originalDebugPrint;
      }
    });
  });
}

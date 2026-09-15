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
  /// The raw `metrics.puntaje` value the backend would send — a number, a
  /// string, or absent entirely (`null`).
  final dynamic puntaje;

  _StubApiService({required this.puntaje});

  @override
  Future<Map<String, dynamic>> getJugadorPorId(int id) async => {
        'id': id,
        'title': {'rendered': 'Juan Pérez'},
        'featured_image': null,
        'posicion': 'Mediocampista',
        'equipo': 'Sin equipo',
        'escudo': '',
        'fecha_nacimiento': '2010-03-14',
        'temporadas': <String>[],
        'metrics': {'puntaje': puntaje},
      };

  @override
  Future<Map<String, dynamic>> getPartidosPorJugador(
    int jugadorId, {
    int? page,
    int? perPage,
  }) async =>
      {'items': [], 'current_page': 1, 'total_pages': 0};

  @override
  Future<Map<String, dynamic>> getTitulosDeJugador(int jugadorId) async =>
      {'jugador_id': jugadorId, 'total': 0, 'titulos': []};

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

/// Every getCached* call is a deterministic cache miss; every write is a
/// no-op — same pattern used by the títulos test suite for this screen.
class _NoopCacheService implements ICacheService {
  @override
  dynamic noSuchMethod(Invocation invocation) => Future.value(null);
}

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

Future<void> _pump(WidgetTester tester, {required dynamic puntaje}) async {
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        apiServiceProvider.overrideWithValue(_StubApiService(puntaje: puntaje)),
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

/// Scoped to the hero's own subtree — the rating text and glyph also appear
/// in the OTROS DATOS row below, so an unscoped finder would match both and
/// these assertions would stop discriminating "the hero" from "the screen".
Finder _enHero(Finder matching) => find.descendant(
      of: find.byKey(PlayerDetailScreen.heroKey),
      matching: matching,
    );

/// Scoped to the OTROS DATOS "Puntaje" row's own Column (label + value),
/// found via its ancestor relationship to the 'Puntaje' label — the label
/// itself is unique to that row, so this discriminates the row from the
/// rest of the panel (and from the hero, which never shows the word
/// 'Puntaje').
Finder _filaPuntaje(Finder matching) => find.descendant(
      of: find.ancestor(
        of: find.text('Puntaje'),
        matching: find.byType(Column),
      ).first,
      matching: matching,
    );

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

void main() {
  group('PlayerDetailScreen · hero, an unrated player (puntaje 0)', () {
    testWidgets('renders the sinPuntaje branch: "Sin puntaje", not "0"',
        (tester) async {
      await _pump(tester, puntaje: 0);

      // The unreachable-until-now branch: grey pill copy, not a bare zero.
      expect(_enHero(find.text('Sin puntaje')), findsOneWidget);
      expect(_enHero(find.text('0')), findsNothing);
      // find.text defaults to skipOffstage: true, so a false "findsNothing"
      // here could just mean the widget scrolled off-screen rather than
      // never existing — rule that out for '0' with a broader scan.
      expect(
        _enHero(find.text('0', skipOffstage: false)),
        findsNothing,
      );
    });

    testWidgets('does not render the confident (non-grey) pill copy',
        (tester) async {
      await _pump(tester, puntaje: 0);

      // A real rating never renders as exactly '0' through this function
      // (formatearPuntaje maps whole numbers without a decimal point), so
      // this also rules out the pre-fix behaviour for the numeric-zero input.
      expect(_enHero(find.text('0.0')), findsNothing);
    });
  });

  group('PlayerDetailScreen · hero, a rated player (puntaje > 0)', () {
    testWidgets('renders the real rating, not the sinPuntaje branch',
        (tester) async {
      await _pump(tester, puntaje: '7,5');

      expect(_enHero(find.text('7.5')), findsOneWidget);
      expect(_enHero(find.text('Sin puntaje')), findsNothing);
    });
  });

  group('PlayerDetailScreen · OTROS DATOS Puntaje row, an unrated player', () {
    testWidgets(
        "renders _infoRow's grey no-data copy ('No informado'), not a "
        "confident '0'", (tester) async {
      await _pump(tester, puntaje: 0);

      expect(find.text('Puntaje'), findsOneWidget);
      expect(_filaPuntaje(find.text('No informado')), findsOneWidget);
      expect(_filaPuntaje(find.text('0')), findsNothing);
      expect(_filaPuntaje(find.text('0', skipOffstage: false)), findsNothing);

      // Proves _filaPuntaje is scoped to the Puntaje row alone, not to the
      // wider OTROS DATOS panel that also contains the other three rows —
      // otherwise this whole group could pass for the wrong reason (e.g. by
      // accident matching a sibling row's own text instead of this row's).
      expect(_filaPuntaje(find.text('Posición')), findsNothing);
    });
  });

  group('PlayerDetailScreen · OTROS DATOS Puntaje row, a rated player', () {
    testWidgets('renders the real rating, not the no-data copy',
        (tester) async {
      await _pump(tester, puntaje: '7,5');

      expect(_filaPuntaje(find.text('7.5')), findsOneWidget);
      expect(_filaPuntaje(find.text('No informado')), findsNothing);
    });
  });
}

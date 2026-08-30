import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/providers/service_providers.dart';
import 'package:torneo_futbol_app/screens/player_detail_screen.dart';
import 'package:torneo_futbol_app/services/i_api_service.dart';

// ---------------------------------------------------------------------------
// Stub
// ---------------------------------------------------------------------------

/// `noSuchMethod` covers the rest of the interface: the Detalles tab only ever
/// reaches for the player and their matches.
class _StubApiService implements IApiService {
  final Map<String, dynamic>? estadisticas;

  _StubApiService({this.estadisticas});

  @override
  Future<Map<String, dynamic>> getJugadorPorId(int id) async => {
        'id': id,
        'title': {'rendered': 'Juan Pérez'},
        'featured_image': null,
        'posicion': 'Mediocampista',
        'equipo': 'Test FC',
        'equipo_id': 1,
        'escudo': '',
        'fecha_nacimiento': '2010-03-14',
        'temporadas': ['2024', '2025', '2026'],
        'metrics': {'puntaje': '7,5', 'caracter': 'Tranquilo'},
        if (estadisticas != null) 'estadisticas': estadisticas,
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

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

/// A 320x568 logical-pixel screen — the narrowest phone the app still targets.
/// The default 800x600 test viewport hides horizontal overflow entirely.
Future<void> _pumpNarrow(
  WidgetTester tester, {
  Map<String, dynamic>? estadisticas,
}) async {
  tester.view.physicalSize = const Size(320, 568);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        apiServiceProvider
            .overrideWithValue(_StubApiService(estadisticas: estadisticas)),
      ],
      child: MaterialApp(
        home: PlayerDetailScreen(player: const {
          'id': 4321,
          'title': {'rendered': 'Juan Pérez'},
          'equipo': 'Test FC',
          'escudo': '',
          'temporadas': [],
          'metrics': {},
        }),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// On a narrow screen the hero and OTROS DATOS fill the viewport, so the stats
/// panel is below the fold and the ListView has not built it yet.
Future<void> _scrollToStats(WidgetTester tester) async {
  await tester.drag(find.byType(ListView).first, const Offset(0, -400));
  await tester.pumpAndSettle();
}

/// The icon sitting in the same tile as [label]. Scoped to the tile's own
/// Column because some of these icons also appear elsewhere on the tab.
Finder _tileIcon(String label, IconData icon) => find.descendant(
      of: find.ancestor(of: find.text(label), matching: find.byType(Column)).first,
      matching: find.byIcon(icon),
    );

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

void main() {
  group('PlayerDetailScreen · Detalles', () {
    testWidgets('no longer shows Carácter', (tester) async {
      await _pumpNarrow(tester, estadisticas: const {
        'partidos_jugados': 42,
        'goles': 17,
        'temporadas': 3,
      });

      expect(find.text('Carácter'), findsNothing);
      expect(find.text('Tranquilo'), findsNothing);
    });

    testWidgets('Posición is marked with a player-position glyph, not a ball',
        (tester) async {
      await _pumpNarrow(tester);

      expect(
        find.descendant(
          of: find.ancestor(of: find.text('Posición'), matching: find.byType(Row)).first,
          matching: find.byIcon(Icons.person_pin_circle),
        ),
        findsOneWidget,
      );
    });

    testWidgets('renders the stats panel with the API numbers', (tester) async {
      await _pumpNarrow(tester, estadisticas: const {
        'partidos_jugados': 42,
        'goles': 17,
        'temporadas': 3,
      });

      await _scrollToStats(tester);

      expect(find.text('ESTADÍSTICAS DEL JUGADOR'), findsOneWidget);
      expect(find.text('Partidos jugados'), findsOneWidget);
      expect(find.text('42'), findsOneWidget);
      expect(find.text('Goles'), findsOneWidget);
      expect(find.text('17'), findsOneWidget);
      expect(find.text('Temporadas'), findsOneWidget);
    });

    testWidgets('each tile carries the icon the league asked for',
        (tester) async {
      await _pumpNarrow(tester, estadisticas: const {
        'partidos_jugados': 42,
        'goles': 17,
        'temporadas': 3,
      });
      await _scrollToStats(tester);

      expect(_tileIcon('Partidos jugados', Icons.directions_run), findsOneWidget);
      expect(_tileIcon('Goles', Icons.sports_soccer), findsOneWidget);
      expect(_tileIcon('Temporadas', Icons.event_repeat), findsOneWidget);
    });

    testWidgets('the panel survives a 320px-wide screen without overflowing',
        (tester) async {
      // Three-digit totals are the widest the tiles will ever have to hold.
      await _pumpNarrow(tester, estadisticas: const {
        'partidos_jugados': 128,
        'goles': 256,
        'temporadas': 12,
      });

      await _scrollToStats(tester);

      // A RenderFlex overflow is reported as a framework exception.
      expect(tester.takeException(), isNull);
      expect(find.text('128'), findsOneWidget);
      expect(find.text('256'), findsOneWidget);
    });

    testWidgets('a player with zero matches still gets the panel',
        (tester) async {
      await _pumpNarrow(tester, estadisticas: const {
        'partidos_jugados': 0,
        'goles': 0,
        'temporadas': 1,
      });

      await _scrollToStats(tester);

      expect(find.text('ESTADÍSTICAS DEL JUGADOR'), findsOneWidget);
      expect(find.text('0'), findsNWidgets(2));
    });

    testWidgets('an API without the stats block hides the panel entirely',
        (tester) async {
      await _pumpNarrow(tester);
      await _scrollToStats(tester);

      expect(find.text('ESTADÍSTICAS DEL JUGADOR'), findsNothing);
      // The rest of the tab keeps working.
      expect(find.text('TEMPORADAS'), findsOneWidget);
    });
  });
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/cambios_vigente.dart';
import 'package:torneo_futbol_app/providers/cambios_providers.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_activos_screen.dart';
import 'package:torneo_futbol_app/services/cambios_api_service.dart';
import 'package:torneo_futbol_app/services/cambios_vigentes_controller.dart';

CambiosVigente _v({
  int teamId = 1,
  String team = 'Los Pibes',
  int plazaId = 1,
  bool esArco = false,
}) =>
    CambiosVigente(
      teamId: teamId,
      teamNombre: team,
      plazaId: plazaId,
      esArco: esArco,
      baja: const CambiosVigenteJugador(
          playerId: 1, nombre: 'Campos, Andres', puntaje: 3.5, sinCalificar: false),
      alta: const CambiosVigenteJugador(
          playerId: 2, nombre: 'Grigorjew, Gerardo', puntaje: null, sinCalificar: true),
      desdeFechaId: 3,
      desdeLabel: 'Apertura Fecha 7',
      situacion: 'Faltan 2 fechas para poder volver.',
      situacionTipo: 'faltan_fechas',
      fechasRestantes: 2,
    );

Widget _wrap(CambiosVigentesState state, {VoidCallback? onRetry}) => MaterialApp(
      home: Scaffold(
        body: CambiosActivosView(
          state: state,
          onRetry: onRetry ?? () {},
          onRefresh: () async {},
        ),
      ),
    );

class _StubController extends CambiosVigentesController {
  int loads = 0;
  _StubController(CambiosVigentesState initial) : super(_NoService()) {
    state = initial;
  }

  @override
  Future<void> load() async {
    loads++;
  }
}

class _NoService implements CambiosApiService {
  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

void main() {
  group('CambiosActivosView', () {
    testWidgets('loading shows a spinner', (tester) async {
      await tester.pumpWidget(_wrap(const CambiosVigentesLoading()));
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('data is grouped by team with Baja/Alta/Desde/Situación', (tester) async {
      await tester.pumpWidget(_wrap(CambiosVigentesLoaded(vigentes: [
        _v(teamId: 1, team: 'Los Pibes', plazaId: 1, esArco: true),
        _v(teamId: 1, team: 'Los Pibes', plazaId: 2),
        _v(teamId: 2, team: 'Zorros', plazaId: 3),
      ])));

      expect(find.text('Los Pibes'), findsOneWidget);
      expect(find.text('Zorros'), findsOneWidget);
      expect(find.byKey(const Key('cambios_activos_card_1')), findsOneWidget);
      expect(find.byKey(const Key('cambios_activos_card_3')), findsOneWidget);
      expect(find.byType(Card), findsNWidgets(3));
      expect(find.text('Arco'), findsOneWidget);
      expect(find.textContaining('Andres Campos (3,5)'), findsWidgets);
      expect(find.textContaining('Gerardo Grigorjew (sin calificar)'), findsWidgets);
      expect(find.textContaining('Apertura Fecha 7'), findsWidgets);
      expect(find.textContaining('Faltan 2 fechas para poder volver.'), findsWidgets);
      expect(find.textContaining('Baja: '), findsWidgets);
      expect(find.textContaining('Situación: '), findsWidgets);
    });

    testWidgets('empty state', (tester) async {
      await tester.pumpWidget(_wrap(const CambiosVigentesLoaded(vigentes: [])));
      expect(find.text('No hay cambios activos en este momento.'), findsOneWidget);
    });

    testWidgets('error state offers Reintentar', (tester) async {
      var retried = 0;
      await tester.pumpWidget(_wrap(
        const CambiosVigentesError(code: 'vigentes_500', message: 'x'),
        onRetry: () => retried++,
      ));
      await tester.tap(find.text('Reintentar'));
      expect(retried, 1);
    });
  });

  group('CambiosActivosScreen (container)', () {
    testWidgets('renders controller state and Reintentar calls load()', (tester) async {
      late _StubController stub;
      await tester.pumpWidget(ProviderScope(
        overrides: [
          cambiosVigentesControllerProvider.overrideWith((ref) {
            stub = _StubController(const CambiosVigentesError(code: 'vigentes_error', message: 'x'));
            return stub;
          }),
        ],
        child: const MaterialApp(home: CambiosActivosScreen()),
      ));
      await tester.pump();

      expect(find.text('Cambios activos'), findsOneWidget); // app bar
      await tester.tap(find.text('Reintentar'));
      expect(stub.loads, 1);
    });
  });
}

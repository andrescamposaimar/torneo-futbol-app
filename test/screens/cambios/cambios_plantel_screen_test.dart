import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/cambios_plaza.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_plantel_screen.dart';
import 'package:torneo_futbol_app/services/cambios_plantel_controller.dart';

CambiosPlaza _plaza({
  int plazaId = 1,
  String tipo = 'campo',
  String titularNombre = 'Juan Pérez',
  String? ocupanteNombre = 'Pedro Gómez',
  int? ocupantePlayerId = 200,
  bool esTitularElOcupante = false,
  bool cerrada = false,
  int? fechasFaltantesLiberacion = 1,
  bool fechasFaltantesLiberacionIndeterminado = false,
}) {
  return CambiosPlaza(
    plazaId: plazaId,
    tipo: tipo,
    titularPlayerId: 100,
    titularNombre: titularNombre,
    ocupantePlayerId: ocupantePlayerId,
    ocupanteNombre: ocupanteNombre,
    esTitularElOcupante: esTitularElOcupante,
    cerrada: cerrada,
    fechasFaltantesLiberacion: fechasFaltantesLiberacion,
    fechasFaltantesLiberacionIndeterminado: fechasFaltantesLiberacionIndeterminado,
  );
}

Widget _wrap(Widget child) => MaterialApp(home: Scaffold(body: child));

void main() {
  group('CambiosPlantelView', () {
    testWidgets('loading state shows a spinner', (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: const CambiosPlantelLoading(),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
      )));

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('error state shows a retry button that fires onRetry', (tester) async {
      var retried = false;
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: const CambiosPlantelError(),
        onRetry: () => retried = true,
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
      )));

      expect(find.text('Algo salió mal'), findsOneWidget);
      await tester.tap(find.text('Reintentar'));
      expect(retried, isTrue);
    });

    testWidgets('empty plazas list shows the honest backfill-pending message', (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: const CambiosPlantelLoaded(plazas: []),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
      )));

      expect(find.text('Todavía no hay plazas cargadas'), findsOneWidget);
    });

    testWidgets('loaded state renders each plaza and a closed plaza hides its actions', (tester) async {
      CambiosPlaza? tappedCambio;
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(plazaId: 1),
          _plaza(plazaId: 2, cerrada: true),
        ]),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (p) => tappedCambio = p,
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
      )));

      expect(find.byKey(const Key('plantel_list')), findsOneWidget);
      expect(find.byKey(const Key('pedir_cambio_1')), findsOneWidget);
      // A closed plaza shows the "Cerrada" badge and hides both actions.
      expect(find.byKey(const Key('plaza_cerrada_badge')), findsOneWidget);
      expect(find.byKey(const Key('pedir_cambio_2')), findsNothing);
      expect(find.byKey(const Key('pedir_regreso_2')), findsNothing);

      await tester.tap(find.byKey(const Key('pedir_cambio_1')));
      expect(tappedCambio?.plazaId, 1);
    });

    testWidgets('regreso action only shows when the plaza is regreso-eligible', (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          // Eligible: occupied by someone else, 0 fechas faltantes, not indeterminado.
          _plaza(plazaId: 1, fechasFaltantesLiberacion: 0),
          // Not eligible: 2 fechas still missing.
          _plaza(plazaId: 2, fechasFaltantesLiberacion: 2),
          // Not eligible: indeterminado — never claim regreso is possible when unsure.
          _plaza(
            plazaId: 3,
            fechasFaltantesLiberacion: null,
            fechasFaltantesLiberacionIndeterminado: true,
          ),
        ]),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
      )));

      expect(find.byKey(const Key('pedir_regreso_1')), findsOneWidget);
      expect(find.byKey(const Key('pedir_regreso_2')), findsNothing);
      expect(find.byKey(const Key('pedir_regreso_3')), findsNothing);
      expect(
        find.text('No pudimos calcular cuántas fechas faltan para que el '
            'titular pueda volver.'),
        findsOneWidget,
      );
    });

    testWidgets('"Mis pedidos" button fires onVerSolicitudes', (tester) async {
      var tapped = false;
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: const CambiosPlantelLoaded(plazas: []),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () => tapped = true,
      )));

      await tester.tap(find.byKey(const Key('ver_solicitudes_button')));
      expect(tapped, isTrue);
    });
  });
}

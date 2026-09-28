import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/cambios_dictamen.dart';
import 'package:torneo_futbol_app/models/cambios_solicitud.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_solicitudes_screen.dart';
import 'package:torneo_futbol_app/services/cambios_solicitudes_controller.dart';

CambiosSolicitud _solicitud({
  int id = 1,
  CambiosSolicitudTipo tipo = CambiosSolicitudTipo.sustitucion,
  CambiosSolicitudEstado estado = CambiosSolicitudEstado.pendiente,
  CambiosDictamen dictamen = const CambiosDictamen(procede: true),
  String? nota,
}) {
  return CambiosSolicitud(
    id: id,
    plazaId: 1,
    tipo: tipo,
    entrantePlayerId: 200,
    fechaId: 3,
    estado: estado,
    solicitadaAt: DateTime(2026, 3, 1, 10),
    resueltaAt: null,
    nota: nota,
    dictamen: dictamen,
  );
}

Widget _wrap(Widget child) => MaterialApp(home: Scaffold(body: child));

void main() {
  group('CambiosSolicitudesView', () {
    testWidgets('loading state shows a spinner', (tester) async {
      await tester.pumpWidget(_wrap(CambiosSolicitudesView(
        state: const CambiosSolicitudesLoading(),
        onRetry: () {},
        onRefresh: () async {},
      )));

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('error state shows a retry button that fires onRetry', (tester) async {
      var retried = false;
      await tester.pumpWidget(_wrap(CambiosSolicitudesView(
        state: const CambiosSolicitudesError(),
        onRetry: () => retried = true,
        onRefresh: () async {},
      )));

      await tester.tap(find.text('Reintentar'));
      expect(retried, isTrue);
    });

    testWidgets('empty state explains there are no solicitudes yet', (tester) async {
      await tester.pumpWidget(_wrap(const CambiosSolicitudesView(
        state: CambiosSolicitudesLoaded(solicitudes: []),
        onRetry: _noop,
        onRefresh: _noopAsync,
      )));

      expect(find.text('Todavía no hiciste ningún pedido'), findsOneWidget);
    });

    testWidgets('loaded state renders solicitud cards with estado label', (tester) async {
      await tester.pumpWidget(_wrap(CambiosSolicitudesView(
        state: CambiosSolicitudesLoaded(solicitudes: [
          _solicitud(id: 1, estado: CambiosSolicitudEstado.aprobada),
        ]),
        onRetry: () {},
        onRefresh: () async {},
      )));

      expect(find.byKey(const Key('solicitudes_list')), findsOneWidget);
      expect(find.byKey(const Key('solicitud_card_1')), findsOneWidget);
      expect(find.text('Aprobada'), findsOneWidget);
    });

    testWidgets('a rejecting dictamen maps its motivo codes to plain Spanish, never the raw code',
        (tester) async {
      await tester.pumpWidget(_wrap(CambiosSolicitudesView(
        state: CambiosSolicitudesLoaded(solicitudes: [
          _solicitud(
            id: 2,
            estado: CambiosSolicitudEstado.rechazada,
            dictamen: const CambiosDictamen(
              procede: false,
              motivos: [
                CambiosDictamenMotivo(
                  codigo: 'puntaje_excede_techo',
                  mensaje: 'internal message that must never render',
                ),
              ],
            ),
          ),
        ]),
        onRetry: () {},
        onRefresh: () async {},
      )));

      expect(
        find.text('El puntaje del jugador elegido supera el techo permitido '
            'para esta plaza.'),
        findsOneWidget,
      );
      expect(find.text('puntaje_excede_techo'), findsNothing);
      expect(find.text('internal message that must never render'), findsNothing);
    });

    testWidgets('an unrecognized motivo code still renders a friendly fallback sentence',
        (tester) async {
      await tester.pumpWidget(_wrap(CambiosSolicitudesView(
        state: CambiosSolicitudesLoaded(solicitudes: [
          _solicitud(
            id: 3,
            estado: CambiosSolicitudEstado.rechazada,
            dictamen: const CambiosDictamen(
              procede: false,
              motivos: [
                CambiosDictamenMotivo(codigo: 'algo_nuevo_del_futuro', mensaje: 'x'),
              ],
            ),
          ),
        ]),
        onRetry: () {},
        onRefresh: () async {},
      )));

      expect(
        find.text('El pedido no cumple con una de las reglas del reglamento.'),
        findsOneWidget,
      );
    });

    testWidgets('a resolved solicitud with a committee nota shows it', (tester) async {
      await tester.pumpWidget(_wrap(CambiosSolicitudesView(
        state: CambiosSolicitudesLoaded(solicitudes: [
          _solicitud(id: 4, nota: 'Aprobado en reunión del 5/3'),
        ]),
        onRetry: () {},
        onRefresh: () async {},
      )));

      expect(find.textContaining('Aprobado en reunión del 5/3'), findsOneWidget);
    });
  });
}

void _noop() {}
Future<void> _noopAsync() async {}

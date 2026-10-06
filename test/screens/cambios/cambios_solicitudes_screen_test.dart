import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/models/cambios_dictamen.dart';
import 'package:torneo_futbol_app/models/cambios_solicitud.dart';
import 'package:torneo_futbol_app/providers/cambios_providers.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_solicitudes_screen.dart';
import 'package:torneo_futbol_app/services/cambios_api_service.dart';
import 'package:torneo_futbol_app/services/cambios_solicitudes_controller.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';

CambiosSolicitud _solicitud({
  int id = 1,
  CambiosSolicitudTipo tipo = CambiosSolicitudTipo.sustitucion,
  CambiosSolicitudEstado estado = CambiosSolicitudEstado.pendiente,
  CambiosDictamen dictamen = const CambiosDictamen(procede: true),
  String? nota,
  CambiosSolicitudLado sale = const CambiosSolicitudLado(
    playerId: 777,
    nombre: 'Campos, Andres',
    puntaje: 4.5,
  ),
  CambiosSolicitudLado entra = const CambiosSolicitudLado(
    playerId: 200,
    nombre: 'Grigorjew, Gerardo',
    puntaje: 4.5,
  ),
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
    sale: sale,
    entra: entra,
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

    testWidgets('renders Sale and Entra with name and puntaje in brackets', (tester) async {
      await tester.pumpWidget(_wrap(CambiosSolicitudesView(
        state: CambiosSolicitudesLoaded(solicitudes: [
          _solicitud(
            id: 1,
            sale: const CambiosSolicitudLado(playerId: 777, nombre: 'Campos, Andres', puntaje: 4.5),
            entra: const CambiosSolicitudLado(playerId: 200, nombre: 'Grigorjew, Gerardo', puntaje: 4.5),
          ),
        ]),
        onRetry: () {},
        onRefresh: () async {},
      )));

      expect(find.text('Sale: '), findsOneWidget);
      expect(find.text('Campos, Andres'), findsOneWidget);
      expect(find.text('Entra: '), findsOneWidget);
      expect(find.text('Grigorjew, Gerardo'), findsOneWidget);
      expect(find.text('[4.5]'), findsNWidgets(2));
    });

    testWidgets('a whole-number puntaje renders without a spurious decimal (formatearPuntaje)',
        (tester) async {
      await tester.pumpWidget(_wrap(CambiosSolicitudesView(
        state: CambiosSolicitudesLoaded(solicitudes: [
          _solicitud(
            id: 1,
            sale: const CambiosSolicitudLado(playerId: 777, nombre: 'Campos, Andres', puntaje: 5.0),
          ),
        ]),
        onRetry: () {},
        onRefresh: () async {},
      )));

      expect(find.text('[5]'), findsOneWidget);
      expect(find.text('[5.0]'), findsNothing);
    });

    testWidgets('an unresolvable puntaje omits the brackets entirely — never [] or [-]',
        (tester) async {
      await tester.pumpWidget(_wrap(CambiosSolicitudesView(
        state: CambiosSolicitudesLoaded(solicitudes: [
          _solicitud(
            id: 1,
            sale: const CambiosSolicitudLado(playerId: 777, nombre: 'Campos, Andres', puntaje: null),
          ),
        ]),
        onRetry: () {},
        onRefresh: () async {},
      )));

      expect(find.text('Campos, Andres'), findsOneWidget);
      expect(find.text('[]'), findsNothing);
      expect(find.text('[-]'), findsNothing);
      // Exactly one bracket pair rendered (Entra's default 4.5) — Sale's own
      // unresolved puntaje contributed none.
      expect(find.textContaining('['), findsOneWidget);
    });

    testWidgets('a player that was never recorded (predates the backend column) shows '
        '"Sin registrar", not a blank line', (tester) async {
      await tester.pumpWidget(_wrap(CambiosSolicitudesView(
        state: CambiosSolicitudesLoaded(solicitudes: [
          _solicitud(id: 1, sale: CambiosSolicitudLado.noRegistrado),
        ]),
        onRetry: () {},
        onRefresh: () async {},
      )));

      expect(find.text('Sale: Sin registrar'), findsOneWidget);
    });

    testWidgets('no RenderFlex overflow with a long Sale/Entra name at a narrow phone width',
        (tester) async {
      tester.view.physicalSize = const Size(320, 640);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(_wrap(CambiosSolicitudesView(
        state: CambiosSolicitudesLoaded(solicitudes: [
          _solicitud(
            id: 1,
            sale: const CambiosSolicitudLado(
              playerId: 1,
              nombre: 'Von Hohenzollern-Sigmaringen, Maximiliano Alejandro',
              puntaje: 4.5,
            ),
            entra: const CambiosSolicitudLado(
              playerId: 2,
              nombre: 'Fernandez de Kirchner Alvarez, Bartolome Ignacio',
              puntaje: 3.0,
            ),
          ),
        ]),
        onRetry: () {},
        onRefresh: () async {},
      )));

      expect(tester.takeException(), isNull);
      // The puntajes survive whole — the NAMES are what give way (ellipsis).
      expect(find.text('[4.5]'), findsOneWidget);
      expect(find.text('[3]'), findsOneWidget);
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

  group('CambiosSolicitudesScreen (container)', () {
    // FIX 3's secondary ask: check CambiosSolicitudesScreen's own container
    // wiring, not just the presentational View above. Unlike CambiosPlantelScreen
    // (which routes to two DIFFERENT tipos), this container has no branching to
    // swap — but its scope (seasonId/teamId) reaching the right provider family
    // key and the right notifier calls was still untested.
    CambiosApiService fakeService() => CambiosApiService(
          baseUrl: 'https://nowhere.test/cambios',
          prodeApi: ProdeApiService(
            config: const ProdeAuthConfig(
              prodeApiBaseUrl: 'https://nowhere.test/prode',
              googleWebClientId: 'test',
              appleTeamId: 'TEST',
            ),
            authRepo: ProdeAuthRepository(),
          ),
        );

    testWidgets('renders the state of the controller scoped to its own seasonId/teamId',
        (tester) async {
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            cambiosSolicitudesControllerProvider((seasonId: 7, teamId: 1)).overrideWith(
              (ref) => _StubSolicitudesController(
                fakeService(),
                CambiosSolicitudesLoaded(solicitudes: [_solicitud(id: 1)]),
              ),
            ),
          ],
          child: const MaterialApp(
            home: CambiosSolicitudesScreen(seasonId: 7, teamId: 1),
          ),
        ),
      );
      await tester.pump();

      expect(find.text('Mis Solicitudes'), findsOneWidget); // app bar title
      expect(find.byKey(const Key('solicitud_card_1')), findsOneWidget);
    });

    testWidgets('onRetry calls load() with THIS screen\'s own seasonId/teamId', (tester) async {
      final loadCalls = <({int seasonId, int teamId})>[];

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            cambiosSolicitudesControllerProvider((seasonId: 9, teamId: 2)).overrideWith(
              (ref) => _RecordingSolicitudesController(
                fakeService(),
                initialState: const CambiosSolicitudesError(),
                onLoad: (seasonId, teamId) => loadCalls.add((seasonId: seasonId, teamId: teamId)),
                onRefresh: (_, __) {},
              ),
            ),
          ],
          child: const MaterialApp(
            home: CambiosSolicitudesScreen(seasonId: 9, teamId: 2),
          ),
        ),
      );
      await tester.pump();

      expect(find.text('Reintentar'), findsOneWidget);
      await tester.tap(find.text('Reintentar'));
      expect(loadCalls, equals([(seasonId: 9, teamId: 2)]));
    });

    testWidgets('onRefresh (pull-to-refresh) calls refresh() with THIS screen\'s own '
        'seasonId/teamId', (tester) async {
      final refreshCalls = <({int seasonId, int teamId})>[];

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            cambiosSolicitudesControllerProvider((seasonId: 9, teamId: 2)).overrideWith(
              (ref) => _RecordingSolicitudesController(
                fakeService(),
                initialState: CambiosSolicitudesLoaded(solicitudes: [_solicitud(id: 1)]),
                onLoad: (_, __) {},
                onRefresh: (seasonId, teamId) =>
                    refreshCalls.add((seasonId: seasonId, teamId: teamId)),
              ),
            ),
          ],
          child: const MaterialApp(
            home: CambiosSolicitudesScreen(seasonId: 9, teamId: 2),
          ),
        ),
      );
      await tester.pump();

      expect(find.byType(RefreshIndicator), findsOneWidget);
      await tester.fling(find.byType(RefreshIndicator), const Offset(0, 300), 800);
      await tester.pumpAndSettle();
      expect(refreshCalls, equals([(seasonId: 9, teamId: 2)]));
    });
  });
}

/// Seeded with a fixed initial state and no-op load/refresh, mirroring the
/// stub convention used throughout the other Cambios/Prode screen tests.
class _StubSolicitudesController extends CambiosSolicitudesController {
  _StubSolicitudesController(super.service, CambiosSolicitudesState initialState) {
    state = initialState;
  }

  @override
  Future<void> load({required int seasonId, required int teamId}) async {}

  @override
  Future<void> refresh({required int seasonId, required int teamId}) async {}
}

/// Records every seasonId/teamId a container passes to load()/refresh() —
/// catches a hardcoded or swapped scope that a trivial no-op stub would miss.
class _RecordingSolicitudesController extends CambiosSolicitudesController {
  final void Function(int seasonId, int teamId) onLoad;
  final void Function(int seasonId, int teamId) onRefresh;

  _RecordingSolicitudesController(
    super.service, {
    required CambiosSolicitudesState initialState,
    required this.onLoad,
    required this.onRefresh,
  }) {
    state = initialState;
  }

  @override
  Future<void> load({required int seasonId, required int teamId}) async {
    onLoad(seasonId, teamId);
  }

  @override
  Future<void> refresh({required int seasonId, required int teamId}) async {
    onRefresh(seasonId, teamId);
  }
}

void _noop() {}
Future<void> _noopAsync() async {}

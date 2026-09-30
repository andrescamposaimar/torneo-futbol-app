import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/models/cambios_plaza.dart';
import 'package:torneo_futbol_app/models/cambios_solicitud.dart';
import 'package:torneo_futbol_app/providers/cambios_providers.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_plantel_screen.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_solicitar_screen.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_solicitudes_screen.dart';
import 'package:torneo_futbol_app/services/cambios_api_service.dart';
import 'package:torneo_futbol_app/services/cambios_candidatos_controller.dart';
import 'package:torneo_futbol_app/services/cambios_plantel_controller.dart';
import 'package:torneo_futbol_app/services/cambios_solicitudes_controller.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';

CambiosPlaza _plaza({
  int plazaId = 1,
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

// ---------------------------------------------------------------------------
// Container test fixtures (FIX 3): pump the REAL CambiosPlantelScreen, not
// just CambiosPlantelView with the test's own inline closures — those closures
// can't catch onPedirCambio/onPedirRegreso being wired to the wrong tipo,
// since the presentational view has no opinion on what tipo means.
// ---------------------------------------------------------------------------

CambiosApiService _fakeService() {
  return CambiosApiService(
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
}

class _StubPlantelController extends CambiosPlantelController {
  _StubPlantelController(CambiosPlantelState initialState) : super(_fakeService()) {
    state = initialState;
  }

  @override
  Future<void> load({required int seasonId, required int teamId}) async {}

  @override
  Future<void> refresh({required int seasonId, required int teamId}) async {}
}

class _StubCandidatosController extends CambiosCandidatosController {
  _StubCandidatosController(CambiosCandidatosState initialState)
      : super(_fakeService(), seasonId: 7, teamId: 1, plazaId: 1) {
    state = initialState;
  }

  @override
  Future<void> load({String query = ''}) async {}
}

class _StubSolicitudesController extends CambiosSolicitudesController {
  _StubSolicitudesController(CambiosSolicitudesState initialState) : super(_fakeService()) {
    state = initialState;
  }

  @override
  Future<void> load({required int seasonId, required int teamId}) async {}

  @override
  Future<void> refresh({required int seasonId, required int teamId}) async {}
}

const _scope = (seasonId: 7, teamId: 1);
const _params = (seasonId: 7, teamId: 1, plazaId: 1);

Future<void> _pumpContainer(WidgetTester tester) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        cambiosPlantelControllerProvider(_scope).overrideWith(
          (ref) => _StubPlantelController(
            // regresoElegible: !cerrada && !esTitularElOcupante &&
            // ocupantePlayerId != null && !indeterminado && faltantes == 0.
            CambiosPlantelLoaded(plazas: [_plaza(plazaId: 1, fechasFaltantesLiberacion: 0)]),
          ),
        ),
        cambiosCandidatosControllerProvider(_params).overrideWith(
          (ref) => _StubCandidatosController(const CambiosCandidatosLoaded(candidatos: [], query: '')),
        ),
        cambiosFechaAbiertaProvider(_scope.seasonId).overrideWith((ref) => Future.value(null)),
        cambiosSolicitudesControllerProvider(_scope).overrideWith(
          (ref) => _StubSolicitudesController(const CambiosSolicitudesLoaded(solicitudes: [])),
        ),
      ],
      child: const MaterialApp(
        home: CambiosPlantelScreen(seasonId: 7, teamId: 1),
      ),
    ),
  );
  await tester.pump();
}

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

  group('CambiosPlantelScreen (container)', () {
    // These pump the REAL screen — swapping the two tipos in
    // cambios_plantel_screen.dart's onPedirCambio/onPedirRegreso wiring would
    // pass every CambiosPlantelView test above (which uses its own inline
    // closures) but would fail these.
    testWidgets('"Pedir cambio" pushes CambiosSolicitarScreen with tipo=sustitucion',
        (tester) async {
      await _pumpContainer(tester);

      await tester.tap(find.byKey(const Key('pedir_cambio_1')));
      await tester.pumpAndSettle();

      final screen = tester.widget<CambiosSolicitarScreen>(find.byType(CambiosSolicitarScreen));
      expect(screen.tipo, equals(CambiosSolicitudTipo.sustitucion));
    });

    testWidgets('"Pedir regreso" pushes CambiosSolicitarScreen with tipo=regreso',
        (tester) async {
      await _pumpContainer(tester);

      await tester.tap(find.byKey(const Key('pedir_regreso_1')));
      await tester.pumpAndSettle();

      final screen = tester.widget<CambiosSolicitarScreen>(find.byType(CambiosSolicitarScreen));
      expect(screen.tipo, equals(CambiosSolicitudTipo.regreso));
    });

    testWidgets('"Mis pedidos" pushes CambiosSolicitudesScreen for the same season/team',
        (tester) async {
      await _pumpContainer(tester);

      await tester.tap(find.byKey(const Key('ver_solicitudes_button')));
      await tester.pumpAndSettle();

      final screen =
          tester.widget<CambiosSolicitudesScreen>(find.byType(CambiosSolicitudesScreen));
      expect(screen.seasonId, equals(7));
      expect(screen.teamId, equals(1));
    });
  });
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/models/cambios_candidato.dart';
import 'package:torneo_futbol_app/models/cambios_plaza.dart';
import 'package:torneo_futbol_app/models/cambios_solicitud.dart';
import 'package:torneo_futbol_app/models/jugador.dart';
import 'package:torneo_futbol_app/providers/cambios_providers.dart';
import 'package:torneo_futbol_app/providers/service_providers.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_arco_titular_picker_screen.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_plantel_screen.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_solicitar_screen.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_solicitudes_screen.dart';
import 'package:torneo_futbol_app/services/cambios_api_service.dart';
import 'package:torneo_futbol_app/services/cambios_candidatos_controller.dart';
import 'package:torneo_futbol_app/services/cambios_plantel_controller.dart';
import 'package:torneo_futbol_app/services/cambios_solicitudes_controller.dart';
import 'package:torneo_futbol_app/services/i_api_service.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';
import 'package:torneo_futbol_app/widgets/cambios_jugador_card.dart';

CambiosPlaza _plaza({
  int plazaId = 1,
  int titularPlayerId = 100,
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
    titularPlayerId: titularPlayerId,
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

Jugador _jugador({required int id, required String posicion, double puntaje = 4.5}) => Jugador(
      id: id,
      nombre: 'Jugador $id',
      posicion: posicion,
      puntaje: puntaje,
      equipo: 'Equipo A',
      escudo: '',
      temporadas: const [],
      raw: const {},
    );

// ---------------------------------------------------------------------------
// Container test fixtures (FIX 3, carried over from this file's previous
// round): pump the REAL CambiosPlantelScreen, not just CambiosPlantelView
// with the test's own inline closures — those closures can't catch
// onPedirCambio/onPedirRegreso being wired to the wrong tipo, since the
// presentational view has no opinion on what tipo means. See the container
// group below for how the swap was actually verified to fail.
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
      : super(
          _fakeService(),
          seasonId: 7,
          teamId: 1,
          plazaId: 1,
          seccion: CambiosCandidatosSeccion.listaEspera,
        ) {
    state = initialState;
  }

  @override
  Future<void> load({String query = '', List<double> puntajes = const []}) async {}
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

/// The container tests exercise the screen's provider wiring, not the
/// photo/posicion/puntaje fetch — every method is an honest "I don't have
/// that" (empty roster, thrown-and-caught per-id lookup), which is exactly
/// what `cambiosEquipoRosterProvider`/`cambiosJugadorPorIdProvider` are
/// built to tolerate (see their docblocks in `cambios_providers.dart`).
class _NoopJugadorApiService implements IApiService {
  @override
  Future<Map<String, dynamic>> getJugadoresRaw({
    int? temporada,
    int? liga,
    int? zona,
    int? equipoId,
    String? search,
    int? page,
    int? perPage,
  }) async =>
      {'items': const []};

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

/// Unlike [_NoopJugadorApiService], resolves [arqueroPlayerId] to a real
/// `Jugador` with `posicion: 'Arquero'` — needed for the ONE container test
/// that must see "Cambiar por Titular" actually render (it is gated on the
/// resolved posicion, never visible against an empty roster).
class _ArqueroRosterApiService implements IApiService {
  final int arqueroPlayerId;
  _ArqueroRosterApiService(this.arqueroPlayerId);

  @override
  Future<Map<String, dynamic>> getJugadoresRaw({
    int? temporada,
    int? liga,
    int? zona,
    int? equipoId,
    String? search,
    int? page,
    int? perPage,
  }) async =>
      {
        'items': [
          {
            'id': arqueroPlayerId,
            'title': {'rendered': 'Juan Pérez'},
            'posicion': 'Arquero',
          },
        ],
      };

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

const _scope = (seasonId: 7, teamId: 1);

/// [CambiosSolicitarScreen] keys its candidatos controller by
/// `(seasonId, teamId, plazaId, seccion)` — ONE override per distinct
/// plazaId AND seccion under test, or the real (non-stubbed) provider runs
/// instead and reaches for `tenantConfigProvider`, which this test suite
/// never bootstraps. `CambiosSolicitarScreen.build()` `watch`es BOTH
/// sections unconditionally (see that screen's own docblock for why), so
/// both need an override here even though these container tests never
/// interact with the candidate step itself.
List<Override> _candidatosOverridesFor(int plazaId) {
  return CambiosCandidatosSeccion.values.map((seccion) {
    final params = (
      seasonId: _scope.seasonId,
      teamId: _scope.teamId,
      plazaId: plazaId,
      seccion: seccion,
    );
    return cambiosCandidatosControllerProvider(params).overrideWith(
      (ref) => _StubCandidatosController(const CambiosCandidatosLoaded(candidatos: [], query: '')),
    );
  }).toList();
}

Future<void> _pumpContainer(
  WidgetTester tester, {
  required List<CambiosPlaza> plazas,
  IApiService? apiService,
}) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        apiServiceProvider.overrideWithValue(apiService ?? _NoopJugadorApiService()),
        cambiosPlantelControllerProvider(_scope).overrideWith(
          (ref) => _StubPlantelController(CambiosPlantelLoaded(plazas: plazas)),
        ),
        for (final plazaId in plazas.map((p) => p.plazaId).toSet())
          ..._candidatosOverridesFor(plazaId),
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
  group('CambiosPlantelView — loading/error/empty', () {
    testWidgets('loading state shows a spinner', (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: const CambiosPlantelLoading(),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
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
        onCambiarPorTitular: (_) {},
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
        onCambiarPorTitular: (_) {},
      )));

      expect(find.text('Todavía no hay plazas cargadas'), findsOneWidget);
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
        onCambiarPorTitular: (_) {},
      )));

      await tester.tap(find.byKey(const Key('ver_solicitudes_button')));
      expect(tapped, isTrue);
    });
  });

  group('CambiosPlantelView — Section 1, the 11 titulares', () {
    testWidgets('titular occupying his own plaza: normal card, "Pedir cambio" present',
        (tester) async {
      CambiosPlaza? tapped;
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(plazaId: 1, esTitularElOcupante: true, ocupanteNombre: null, ocupantePlayerId: null),
        ]),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (p) => tapped = p,
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
      )));

      expect(find.byKey(const Key('plantel_list')), findsOneWidget);
      expect(find.byKey(const Key('pedir_cambio_1')), findsOneWidget);
      expect(find.byKey(const Key('baja_por_cambio_badge_1')), findsNothing);
      expect(find.byKey(const Key('plaza_cerrada_badge_1')), findsNothing);

      final card = tester.widget<CambiosJugadorCard>(find.byKey(const Key('plaza_card_1')));
      expect(card.greyedOut, isFalse);
      expect(card.nombre, 'Juan Pérez');

      await tester.tap(find.byKey(const Key('pedir_cambio_1')));
      expect(tapped?.plazaId, 1);
    });

    testWidgets(
        'plaza occupied by someone else: greyed out, "Baja por cambio" marker, '
        'NO actions at all', (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(plazaId: 2, esTitularElOcupante: false, ocupanteNombre: 'Pedro Gómez'),
        ]),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
      )));

      expect(find.byKey(const Key('baja_por_cambio_badge_2')), findsOneWidget);

      final card = tester.widget<CambiosJugadorCard>(find.byKey(const Key('plaza_card_2')));
      expect(card.greyedOut, isTrue);
      // The titular is not the one leaving — no action belongs on HIS card.
      expect(card.actions, isEmpty);
      expect(find.byKey(const Key('pedir_cambio_2')), findsNothing);
      expect(find.byKey(const Key('pedir_regreso_2')), findsNothing);
    });

    testWidgets('cerrada plaza: "Cerrada" badge, visibly inert, no actions', (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(
            plazaId: 3,
            esTitularElOcupante: true,
            ocupanteNombre: null,
            ocupantePlayerId: null,
            cerrada: true,
          ),
        ]),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
      )));

      expect(find.byKey(const Key('plaza_cerrada_badge_3')), findsOneWidget);
      expect(find.byKey(const Key('pedir_cambio_3')), findsNothing);
    });

    testWidgets('the 11 titulares never disappear, regardless of occupancy', (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(plazaId: 1, esTitularElOcupante: true, ocupanteNombre: null, ocupantePlayerId: null),
          _plaza(plazaId: 2, esTitularElOcupante: false, ocupanteNombre: 'Pedro Gómez'),
          _plaza(plazaId: 3, cerrada: true, esTitularElOcupante: true, ocupanteNombre: null, ocupantePlayerId: null),
        ]),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
      )));

      expect(find.byKey(const Key('plaza_card_1')), findsOneWidget);
      expect(find.byKey(const Key('plaza_card_2')), findsOneWidget);
      expect(find.byKey(const Key('plaza_card_3')), findsOneWidget);
    });
  });

  group('CambiosPlantelView — "Cambiar por Titular" (arco plaza only)', () {
    testWidgets('appears on the arco plaza\'s own card, alongside "Pedir cambio"', (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(plazaId: 1, esTitularElOcupante: true, ocupanteNombre: null, ocupantePlayerId: null),
        ]),
        jugadoresById: {100: _jugador(id: 100, posicion: 'Arquero')},
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
      )));

      expect(find.byKey(const Key('pedir_cambio_1')), findsOneWidget);
      expect(find.byKey(const Key('cambiar_por_titular_1')), findsOneWidget);
    });

    testWidgets('does NOT appear on a field plaza\'s card (posicion != Arquero)', (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(plazaId: 1, esTitularElOcupante: true, ocupanteNombre: null, ocupantePlayerId: null),
        ]),
        jugadoresById: {100: _jugador(id: 100, posicion: 'Defensor')},
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
      )));

      expect(find.byKey(const Key('pedir_cambio_1')), findsOneWidget);
      expect(find.byKey(const Key('cambiar_por_titular_1')), findsNothing);
    });

    testWidgets('does NOT appear when the jugador has not resolved yet (posicion unknown)',
        (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(plazaId: 1, esTitularElOcupante: true, ocupanteNombre: null, ocupantePlayerId: null),
        ]),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
      )));

      expect(find.byKey(const Key('cambiar_por_titular_1')), findsNothing);
    });

    testWidgets('does NOT appear on a cerrada arco plaza — visibly inert, same as "Pedir cambio"',
        (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(
            plazaId: 1,
            esTitularElOcupante: true,
            ocupanteNombre: null,
            ocupantePlayerId: null,
            cerrada: true,
          ),
        ]),
        jugadoresById: {100: _jugador(id: 100, posicion: 'Arquero')},
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
      )));

      expect(find.byKey(const Key('pedir_cambio_1')), findsNothing);
      expect(find.byKey(const Key('cambiar_por_titular_1')), findsNothing);
    });

    testWidgets(
        'does NOT appear when the arco plaza is occupied by someone else (baja por cambio)',
        (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(plazaId: 1, esTitularElOcupante: false, ocupanteNombre: 'Pedro Gómez'),
        ]),
        jugadoresById: {100: _jugador(id: 100, posicion: 'Arquero')},
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
      )));

      expect(find.byKey(const Key('cambiar_por_titular_1')), findsNothing);
    });

    testWidgets('tapping it fires onCambiarPorTitular with this plaza', (tester) async {
      CambiosPlaza? tapped;
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(plazaId: 1, esTitularElOcupante: true, ocupanteNombre: null, ocupantePlayerId: null),
        ]),
        jugadoresById: {100: _jugador(id: 100, posicion: 'Arquero')},
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (p) => tapped = p,
      )));

      await tester.tap(find.byKey(const Key('cambiar_por_titular_1')));
      expect(tapped?.plazaId, 1);
    });

    testWidgets('narrow width (320px), long names: both titular cards render with no overflow',
        (tester) async {
      tester.view.physicalSize = const Size(320, 640);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(
            plazaId: 1,
            titularPlayerId: 100,
            titularNombre: 'Von Hohenzollern-Sigmaringen, Maximiliano Alejandro',
            esTitularElOcupante: true,
            ocupanteNombre: null,
            ocupantePlayerId: null,
          ),
          _plaza(
            plazaId: 2,
            titularPlayerId: 101,
            titularNombre: 'Fernández Etcheverrigaray, Juan Bautista',
            esTitularElOcupante: true,
            ocupanteNombre: null,
            ocupantePlayerId: null,
          ),
        ]),
        jugadoresById: {
          100: _jugador(id: 100, posicion: 'Arquero'),
          101: _jugador(id: 101, posicion: 'Defensor'),
        },
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
      )));

      expect(tester.takeException(), isNull);
      expect(find.byKey(const Key('cambiar_por_titular_1')), findsOneWidget);
      expect(find.byKey(const Key('cambiar_por_titular_2')), findsNothing);
    });
  });

  group('CambiosPlantelView — Section 2, "Cambios activos"', () {
    testWidgets('absent entirely (no header) when nothing is active', (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(plazaId: 1, esTitularElOcupante: true, ocupanteNombre: null, ocupantePlayerId: null),
        ]),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
      )));

      expect(find.text('Cambios activos'), findsNothing);
    });

    testWidgets('lists the right occupant with the right "en la plaza de" text',
        (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(plazaId: 1, esTitularElOcupante: true, ocupanteNombre: null, ocupantePlayerId: null),
          _plaza(
            plazaId: 2,
            titularNombre: 'Carlos Ruiz',
            ocupanteNombre: 'Pedro Gómez',
            ocupantePlayerId: 200,
            esTitularElOcupante: false,
          ),
        ]),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
      )));

      expect(find.text('Cambios activos'), findsOneWidget);
      expect(find.byKey(const Key('activo_card_2')), findsOneWidget);

      final card = tester.widget<CambiosJugadorCard>(find.byKey(const Key('activo_card_2')));
      expect(card.nombre, 'Pedro Gómez');
      expect(card.playerId, 200);
      expect(card.subtitleExtra, 'en la plaza de Carlos Ruiz');

      // No card for the titular's plaza shows up a second time in Section 2.
      expect(find.byKey(const Key('activo_card_1')), findsNothing);
    });

    testWidgets('"Confirmar fin del cambio" — ready (0 fechas faltantes): enabled',
        (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(plazaId: 2, fechasFaltantesLiberacion: 0),
        ]),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
      )));

      final button =
          tester.widget<OutlinedButton>(find.byKey(const Key('confirmar_fin_cambio_2')));
      expect(button.onPressed, isNotNull);
      expect(
        find.text('Faltan 0 fecha(s) para que el titular pueda volver.'),
        findsNothing,
      );
    });

    testWidgets('"Confirmar fin del cambio" — N fechas faltantes: disabled, labelled',
        (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(plazaId: 2, fechasFaltantesLiberacion: 2),
        ]),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
      )));

      final button =
          tester.widget<OutlinedButton>(find.byKey(const Key('confirmar_fin_cambio_2')));
      expect(button.onPressed, isNull);
      expect(
        find.text('Faltan 2 fecha(s) para que el titular pueda volver.'),
        findsOneWidget,
      );
    });

    testWidgets('"Confirmar fin del cambio" — indeterminado: disabled, honest caption',
        (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(
            plazaId: 2,
            fechasFaltantesLiberacion: null,
            fechasFaltantesLiberacionIndeterminado: true,
          ),
        ]),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
      )));

      final button =
          tester.widget<OutlinedButton>(find.byKey(const Key('confirmar_fin_cambio_2')));
      expect(button.onPressed, isNull);
      expect(
        find.text('No pudimos calcular cuántas fechas faltan para que el '
            'titular pueda volver.'),
        findsOneWidget,
      );
    });

    testWidgets('"Cambiar este cambio" is present and enabled on the active-change card',
        (tester) async {
      CambiosPlaza? tapped;
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(plazaId: 2, fechasFaltantesLiberacion: 2),
        ]),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (p) => tapped = p,
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
      )));

      await tester.tap(find.byKey(const Key('cambiar_este_cambio_2')));
      expect(tapped?.plazaId, 2);
    });

    testWidgets('cerrada active-change plaza: no actions at all, visibly inert',
        (tester) async {
      await tester.pumpWidget(_wrap(CambiosPlantelView(
        state: CambiosPlantelLoaded(plazas: [
          _plaza(plazaId: 2, cerrada: true, fechasFaltantesLiberacion: 0),
        ]),
        onRetry: () {},
        onRefresh: () async {},
        onPedirCambio: (_) {},
        onPedirRegreso: (_) {},
        onVerSolicitudes: () {},
        onCambiarPorTitular: (_) {},
      )));

      expect(find.byKey(const Key('confirmar_fin_cambio_2')), findsNothing);
      expect(find.byKey(const Key('cambiar_este_cambio_2')), findsNothing);
    });
  });

  group('CambiosPlantelScreen (container) — navigation wiring', () {
    // These pump the REAL screen. A previous round of this feature had a
    // bug where "Pedir cambio" and "Pedir regreso" were wired to swapped
    // tipos — passing every CambiosPlantelView test above (each uses its
    // own inline closures, which have no opinion on what tipo means) while
    // silently sending the wrong request type. The fix was verified by
    // deliberately swapping `CambiosSolicitudTipo.sustitucion` and
    // `CambiosSolicitudTipo.regreso` in cambios_plantel_screen.dart's
    // onPedirCambio/onPedirRegreso callbacks and confirming these specific
    // tests — and only these — failed; reverting made them pass again.
    testWidgets('"Pedir cambio" (Section 1) pushes CambiosSolicitarScreen with tipo=sustitucion',
        (tester) async {
      await _pumpContainer(tester, plazas: [
        _plaza(plazaId: 1, esTitularElOcupante: true, ocupanteNombre: null, ocupantePlayerId: null),
      ]);

      await tester.tap(find.byKey(const Key('pedir_cambio_1')));
      await tester.pumpAndSettle();

      final screen = tester.widget<CambiosSolicitarScreen>(find.byType(CambiosSolicitarScreen));
      expect(screen.tipo, equals(CambiosSolicitudTipo.sustitucion));
      expect(screen.plaza.plazaId, 1);
    });

    testWidgets(
        '"Cambiar este cambio" (Section 2) pushes CambiosSolicitarScreen with tipo=sustitucion',
        (tester) async {
      await _pumpContainer(tester, plazas: [
        _plaza(plazaId: 2, esTitularElOcupante: false, ocupanteNombre: 'Pedro Gómez'),
      ]);

      await tester.tap(find.byKey(const Key('cambiar_este_cambio_2')));
      await tester.pumpAndSettle();

      final screen = tester.widget<CambiosSolicitarScreen>(find.byType(CambiosSolicitarScreen));
      expect(screen.tipo, equals(CambiosSolicitudTipo.sustitucion));
      expect(screen.plaza.plazaId, 2);
    });

    testWidgets(
        '"Confirmar fin del cambio" (Section 2, ready) pushes CambiosSolicitarScreen '
        'with tipo=regreso', (tester) async {
      await _pumpContainer(tester, plazas: [
        _plaza(plazaId: 2, fechasFaltantesLiberacion: 0),
      ]);

      await tester.tap(find.byKey(const Key('confirmar_fin_cambio_2')));
      await tester.pumpAndSettle();

      final screen = tester.widget<CambiosSolicitarScreen>(find.byType(CambiosSolicitarScreen));
      expect(screen.tipo, equals(CambiosSolicitudTipo.regreso));
      expect(screen.plaza.plazaId, 2);
    });

    testWidgets(
        '"Cambiar por Titular" pushes CambiosArcoTitularPickerScreen with this plaza as '
        'plazaArco, and the SAME plazas/jugadoresById this screen already resolved',
        (tester) async {
      final plazaArco = _plaza(
        plazaId: 1,
        titularPlayerId: 100,
        esTitularElOcupante: true,
        ocupanteNombre: null,
        ocupantePlayerId: null,
      );
      await _pumpContainer(
        tester,
        plazas: [plazaArco],
        apiService: _ArqueroRosterApiService(100),
      );

      await tester.tap(find.byKey(const Key('cambiar_por_titular_1')));
      await tester.pumpAndSettle();

      final screen = tester
          .widget<CambiosArcoTitularPickerScreen>(find.byType(CambiosArcoTitularPickerScreen));
      expect(screen.seasonId, equals(7));
      expect(screen.teamId, equals(1));
      expect(screen.plazaArco.plazaId, 1);
      expect(screen.plazas.map((p) => p.plazaId), [1]);
      expect(screen.jugadoresById[100]?.posicion, 'Arquero');
    });

    testWidgets('"Mis pedidos" pushes CambiosSolicitudesScreen for the same season/team',
        (tester) async {
      await _pumpContainer(tester, plazas: [
        _plaza(plazaId: 1, esTitularElOcupante: true, ocupanteNombre: null, ocupantePlayerId: null),
      ]);

      await tester.tap(find.byKey(const Key('ver_solicitudes_button')));
      await tester.pumpAndSettle();

      final screen =
          tester.widget<CambiosSolicitudesScreen>(find.byType(CambiosSolicitudesScreen));
      expect(screen.seasonId, equals(7));
      expect(screen.teamId, equals(1));
    });
  });
}

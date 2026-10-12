import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/models/cambios_candidato.dart';
import 'package:torneo_futbol_app/models/cambios_dictamen.dart';
import 'package:torneo_futbol_app/models/cambios_fecha_abierta.dart';
import 'package:torneo_futbol_app/models/cambios_plaza.dart';
import 'package:torneo_futbol_app/models/cambios_solicitud.dart';
import 'package:torneo_futbol_app/providers/cambios_providers.dart';
import 'package:torneo_futbol_app/providers/service_providers.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_arco_titular_picker_screen.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_plantel_screen.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_solicitar_screen.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_solicitudes_screen.dart';
import 'package:torneo_futbol_app/services/cambios_api_service.dart';
import 'package:torneo_futbol_app/services/cambios_candidatos_controller.dart';
import 'package:torneo_futbol_app/services/cambios_plantel_controller.dart';
import 'package:torneo_futbol_app/services/i_api_service.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';

/// Confirming a request lands the captain on "Mis pedidos", showing the
/// request he just filed, with "Mi Plantel" behind it — through BOTH entry
/// paths. These tests drive the REAL [CambiosPlantelScreen] so the stack is
/// built by the production `push` calls, and they do NOT stub the
/// solicitudes controller: the real autoDispose provider (whose body calls
/// `load()`) is what must fetch the fresh list.

const _prodeConfig = ProdeAuthConfig(
  prodeApiBaseUrl: 'https://nowhere.test/prode',
  googleWebClientId: 'test',
  appleTeamId: 'TEST',
);

CambiosApiService _baseFakeService() => CambiosApiService(
      baseUrl: 'https://nowhere.test/cambios',
      prodeApi: ProdeApiService(
          config: _prodeConfig, authRepo: ProdeAuthRepository()),
    );

/// Files requests successfully and serves `GET /solicitudes` from what was
/// filed so far — so a stale (never re-fetched) "Mis pedidos" is visibly
/// different from a fresh one.
class _FlowService extends CambiosApiService {
  int fetchSolicitudesCalls = 0;
  bool filed = false;

  _FlowService()
      : super(
          baseUrl: 'https://nowhere.test/cambios',
          prodeApi: ProdeApiService(
              config: _prodeConfig, authRepo: ProdeAuthRepository()),
        );

  @override
  Future<CambiosNuevaSolicitud> crearSolicitud({
    required int seasonId,
    required int teamId,
    required int plazaId,
    required CambiosSolicitudTipo tipo,
    required int fechaId,
    int? entrantePlayerId,
  }) async {
    filed = true;
    return const CambiosNuevaSolicitud(
      id: 99,
      estado: CambiosSolicitudEstado.pendiente,
      dictamen: CambiosDictamen(procede: true),
    );
  }

  @override
  Future<CambiosNuevaSolicitud> crearReasignacionArquero({
    required int seasonId,
    required int teamId,
    required int plazaArcoId,
    required int fechaId,
    required int entrantePlayerId,
    required int entranteCampoPlayerId,
  }) async {
    filed = true;
    return const CambiosNuevaSolicitud(
      id: 99,
      estado: CambiosSolicitudEstado.pendiente,
      dictamen: CambiosDictamen(procede: true),
    );
  }

  @override
  Future<List<CambiosSolicitud>> fetchSolicitudes({
    required int seasonId,
    required int teamId,
  }) async {
    fetchSolicitudesCalls++;
    if (!filed) return const [];
    return [
      CambiosSolicitud.fromJson({
        'id': 99,
        'plaza_id': 2,
        'tipo': 'sustitucion',
        'fecha_id': 42,
        'estado': 'pendiente',
        'solicitada_at': '2026-10-12 16:20:00',
        'dictamen': {'procede': true, 'motivos': []},
      }),
    ];
  }
}

class _StubPlantelController extends CambiosPlantelController {
  _StubPlantelController(List<CambiosPlaza> plazas)
      : super(_baseFakeService()) {
    state = CambiosPlantelLoaded(plazas: plazas);
  }

  @override
  Future<void> load({required int seasonId, required int teamId}) async {}

  @override
  Future<void> refresh({required int seasonId, required int teamId}) async {}
}

class _StubCandidatosController extends CambiosCandidatosController {
  _StubCandidatosController(CambiosCandidatosSeccion seccion)
      : super(
          _baseFakeService(),
          seasonId: 7,
          teamId: 1,
          plazaId: 2,
          seccion: seccion,
          autoLoad: false,
        ) {
    state = seccion == CambiosCandidatosSeccion.listaEspera
        ? const CambiosCandidatosLoaded(
            candidatos: [
              CambiosCandidato(
                playerId: 200,
                nombre: 'Pedro Gómez',
                esPadre: false,
                puntaje: 2.5,
                viable: true,
              ),
            ],
            query: '',
          )
        : const CambiosCandidatosIdle();
  }

  @override
  Future<void> load(
      {String query = '', List<double> puntajes = const []}) async {}

  @override
  Future<void> loadMore() async {}
}

/// Resolves the goalkeeper (100) and the field titular (101) so "Cambiar por
/// Titular" renders (it is gated on the resolved posicion).
class _RosterApiService implements IApiService {
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
            'id': 100,
            'title': {'rendered': 'El Arquero'},
            'posicion': 'Arquero',
          },
          {
            'id': 101,
            'title': {'rendered': 'Defensor Uno'},
            'posicion': 'Defensor',
          },
        ],
      };

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

const _plazaArco = CambiosPlaza(
  plazaId: 1,
  titularPlayerId: 100,
  titularNombre: 'El Arquero',
  ocupantePlayerId: 100,
  ocupanteNombre: 'El Arquero',
  esTitularElOcupante: true,
  cerrada: false,
  esArco: true,
  puntajeTecho: 5.0,
);

const _plazaCampo = CambiosPlaza(
  plazaId: 2,
  titularPlayerId: 101,
  titularNombre: 'Defensor Uno',
  ocupantePlayerId: 101,
  ocupanteNombre: 'Defensor Uno',
  esTitularElOcupante: true,
  cerrada: false,
  puntajeTecho: 3.0,
);

const _fecha = CambiosFechaAbierta(
  fechaId: 42,
  numeroEnTorneo: 3,
  torneo: 'Apertura',
  playDate: '2026-01-10',
  regresoFase: CambiosVentanaFase.abierta,
  sustitucionFase: CambiosVentanaFase.abierta,
);

Future<void> _pumpPlantel(WidgetTester tester, _FlowService service) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        apiServiceProvider.overrideWithValue(_RosterApiService()),
        cambiosApiServiceProvider.overrideWithValue(service),
        cambiosPlantelControllerProvider((seasonId: 7, teamId: 1)).overrideWith(
          (ref) => _StubPlantelController(const [_plazaArco, _plazaCampo]),
        ),
        for (final seccion in CambiosCandidatosSeccion.values)
          cambiosCandidatosControllerProvider((
            seasonId: 7,
            teamId: 1,
            plazaId: 2,
            seccion: seccion,
          )).overrideWith((ref) => _StubCandidatosController(seccion)),
        cambiosFechaAbiertaProvider(7)
            .overrideWith((ref) => Future.value(_fecha)),
      ],
      child:
          const MaterialApp(home: CambiosPlantelScreen(seasonId: 7, teamId: 1)),
    ),
  );
  await tester.pump();
  await tester.pump();
}

NavigatorState _navigator(WidgetTester tester) =>
    tester.state<NavigatorState>(find.byType(Navigator).first);

void _expectLandedOnMisPedidosOverPlantel(
    WidgetTester tester, _FlowService service) {
  expect(find.byType(CambiosSolicitudesScreen), findsOneWidget);
  // Nothing of the finished flow is left in the stack — not even hidden.
  expect(
      find.byType(CambiosSolicitarScreen, skipOffstage: false), findsNothing);
  expect(find.byType(CambiosArcoTitularPickerScreen, skipOffstage: false),
      findsNothing);
  // "Mi Plantel" is the ONLY route behind "Mis pedidos".
  expect(
      find.byType(CambiosPlantelScreen, skipOffstage: false), findsOneWidget);
  // ...and the captain SEES what he just filed.
  expect(find.byKey(const Key('solicitud_card_99')), findsOneWidget);
}

void main() {
  testWidgets(
      'ordinary request: confirming lands on "Mis pedidos" showing the new request, '
      '"Mi Plantel" behind, and Back goes straight to "Mi Plantel"',
      (tester) async {
    final service = _FlowService();
    await _pumpPlantel(tester, service);

    // The captain peeks at "Mis pedidos" first (empty) and leaves — the
    // trap a retained, stale provider instance would fall into.
    await tester.tap(find.byKey(const Key('ver_solicitudes_button')));
    await tester.pumpAndSettle();
    expect(service.fetchSolicitudesCalls, 1);
    expect(find.byKey(const Key('solicitud_card_99')), findsNothing);
    _navigator(tester).pop();
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('pedir_cambio_2')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('candidato_200')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('confirmar_solicitud_button')));
    await tester.pumpAndSettle();

    _expectLandedOnMisPedidosOverPlantel(tester, service);
    // A FRESH fetch happened for the pushed screen (the first visit's
    // instance was disposed, not reused).
    expect(service.fetchSolicitudesCalls, 2);

    // Back does not replay the flow he completed.
    _navigator(tester).pop();
    await tester.pumpAndSettle();
    expect(find.byType(CambiosSolicitudesScreen, skipOffstage: false),
        findsNothing);
    expect(find.byKey(const Key('plantel_list')), findsOneWidget);
    expect(_navigator(tester).canPop(), isFalse);
  });

  testWidgets(
      'grouped reasignación: confirming lands on "Mis pedidos" showing the new request, '
      'with the picker AND the solicitar screen gone and "Mi Plantel" behind',
      (tester) async {
    final service = _FlowService();
    await _pumpPlantel(tester, service);

    await tester.tap(find.byKey(const Key('cambiar_por_titular_1')));
    await tester.pumpAndSettle();
    expect(find.byType(CambiosArcoTitularPickerScreen), findsOneWidget);

    await tester.tap(find.byKey(const Key('arco_titular_card_2')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('candidato_200')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('confirmar_solicitud_button')));
    await tester.pumpAndSettle();

    _expectLandedOnMisPedidosOverPlantel(tester, service);
    expect(service.fetchSolicitudesCalls, 1);

    _navigator(tester).pop();
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('plantel_list')), findsOneWidget);
    expect(_navigator(tester).canPop(), isFalse);
  });
}

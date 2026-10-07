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
import 'package:torneo_futbol_app/screens/cambios/cambios_solicitar_screen.dart';
import 'package:torneo_futbol_app/services/cambios_api_service.dart';
import 'package:torneo_futbol_app/services/cambios_candidatos_controller.dart';
import 'package:torneo_futbol_app/services/cambios_plantel_controller.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';

/// Grouped-mode tests for `CambiosSolicitarScreen` — step 2 of the grouped
/// goalkeeper-reassignment flow (reached via
/// `CambiosArcoTitularPickerScreen`). This is a SEPARATE file on purpose:
/// `cambios_solicitar_screen_test.dart` is the regression guard for this
/// screen's ORDINARY (ungrouped) behaviour and must keep passing completely
/// untouched — see this slice's own task brief.
CambiosApiService _baseFakeService() {
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

class _StubCandidatosController extends CambiosCandidatosController {
  _StubCandidatosController(
    CambiosCandidatosState initialState, {
    CambiosCandidatosSeccion seccion = CambiosCandidatosSeccion.listaEspera,
  }) : super(
          _baseFakeService(),
          seasonId: 7,
          teamId: 1,
          plazaId: 20,
          seccion: seccion,
          autoLoad: false,
        ) {
    state = initialState;
  }

  @override
  Future<void> load({String query = '', List<double> puntajes = const []}) async {}

  @override
  Future<void> loadMore() async {}
}

class _StubPlantelController extends CambiosPlantelController {
  int refreshCalls = 0;
  _StubPlantelController() : super(_baseFakeService()) {
    state = const CambiosPlantelLoaded(plazas: []);
  }

  @override
  Future<void> load({required int seasonId, required int teamId}) async {}

  @override
  Future<void> refresh({required int seasonId, required int teamId}) async {
    refreshCalls++;
  }
}

/// Tracks BOTH submit methods — a grouped test asserting
/// `crearReasignacionArquero` was called must also assert the plain
/// `crearSolicitud` was NEVER called (the exact mistake of silently falling
/// back to an ordinary sustitucion would otherwise pass unnoticed).
class _FakeSubmitService extends CambiosApiService {
  Map<String, Object?>? lastSustitucionCall;
  Map<String, Object?>? lastGrupoCall;

  _FakeSubmitService()
      : super(
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

  @override
  Future<CambiosNuevaSolicitud> crearSolicitud({
    required int seasonId,
    required int teamId,
    required int plazaId,
    required CambiosSolicitudTipo tipo,
    required int fechaId,
    int? entrantePlayerId,
  }) async {
    lastSustitucionCall = {
      'seasonId': seasonId,
      'teamId': teamId,
      'plazaId': plazaId,
      'tipo': tipo,
      'fechaId': fechaId,
      'entrantePlayerId': entrantePlayerId,
    };
    return const CambiosNuevaSolicitud(
      id: 1,
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
    lastGrupoCall = {
      'seasonId': seasonId,
      'teamId': teamId,
      'plazaArcoId': plazaArcoId,
      'fechaId': fechaId,
      'entrantePlayerId': entrantePlayerId,
      'entranteCampoPlayerId': entranteCampoPlayerId,
    };
    return const CambiosNuevaSolicitud(
      id: 2,
      estado: CambiosSolicitudEstado.pendiente,
      dictamen: CambiosDictamen(procede: true),
    );
  }
}

// The field plaza (the chosen titular's OWN plaza) and the goal plaza carry
// DIFFERENT techos on purpose — pins "step 2 filters against the CHOSEN
// TITULAR's plaza, not the goal plaza", the exact mistake that would let an
// over-techo player in.
final _plazaCampo = const CambiosPlaza(
  plazaId: 20,
  titularPlayerId: 150,
  titularNombre: 'Titular de Campo',
  ocupantePlayerId: 150,
  ocupanteNombre: 'Titular de Campo',
  esTitularElOcupante: true,
  cerrada: false,
  puntajeTecho: 2.0,
);

final _plazaArco = const CambiosPlaza(
  plazaId: 30,
  titularPlayerId: 160,
  titularNombre: 'El Arquero',
  ocupantePlayerId: 160,
  ocupanteNombre: 'El Arquero',
  esTitularElOcupante: true,
  cerrada: false,
  puntajeTecho: 5.0,
);

CambiosTeamScope get _scope => (seasonId: 7, teamId: 1);

CambiosCandidatosParams _paramsFor(CambiosCandidatosSeccion seccion) => (
      seasonId: 7,
      teamId: 1,
      plazaId: _plazaCampo.plazaId,
      seccion: seccion,
    );

const _fecha = CambiosFechaAbierta(
  fechaId: 42,
  numeroEnTorneo: 3,
  torneo: 'Apertura',
  playDate: '2026-01-10',
  regresoFase: CambiosVentanaFase.abierta,
  sustitucionFase: CambiosVentanaFase.abierta,
);

Future<void> _pumpGrouped(
  WidgetTester tester, {
  CambiosCandidatosState listaEsperaState = const CambiosCandidatosLoaded(candidatos: [], query: ''),
  CambiosApiService? apiService,
  _StubPlantelController? plantelController,
  double? puntaje = 4.5,
}) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        cambiosCandidatosControllerProvider(_paramsFor(CambiosCandidatosSeccion.listaEspera))
            .overrideWith((ref) => _StubCandidatosController(listaEsperaState)),
        cambiosCandidatosControllerProvider(_paramsFor(CambiosCandidatosSeccion.padronCompleto))
            .overrideWith((ref) => _StubCandidatosController(
                  const CambiosCandidatosIdle(),
                  seccion: CambiosCandidatosSeccion.padronCompleto,
                )),
        cambiosPlantelControllerProvider(_scope)
            .overrideWith((ref) => plantelController ?? _StubPlantelController()),
        cambiosFechaAbiertaProvider(_scope.seasonId).overrideWith((ref) => Future.value(_fecha)),
        if (apiService != null) cambiosApiServiceProvider.overrideWithValue(apiService),
      ],
      child: MaterialApp(
        home: Navigator(
          onGenerateRoute: (settings) => MaterialPageRoute(
            builder: (_) => CambiosSolicitarScreen(
              seasonId: 7,
              teamId: 1,
              plaza: _plazaCampo,
              tipo: CambiosSolicitudTipo.sustitucion,
              puntaje: puntaje,
              grupoArco: CambiosGrupoArcoContext(plazaArco: _plazaArco),
            ),
          ),
        ),
      ),
    ),
  );
  await tester.pump();
  await tester.pump();
}

void main() {
  group('CambiosSolicitarScreen (grupoArco) — header y copy', () {
    testWidgets('AppBar title, grupo banner and submit label all say "reasignación", not '
        '"Pedir cambio"', (tester) async {
      await _pumpGrouped(tester);

      expect(find.text('Reasignación de arquero'), findsOneWidget);
      expect(find.text('Pedir cambio'), findsNothing);
      expect(find.byKey(const Key('grupo_arco_banner')), findsOneWidget);
      expect(find.widgetWithText(ElevatedButton, 'Confirmar Reasignación'), findsOneWidget);
      expect(find.text('Solicitar Cambio'), findsNothing);
    });

    testWidgets('the banner names both the titular moving to goal and the goalkeeper he '
        'replaces', (tester) async {
      await _pumpGrouped(tester);

      expect(find.textContaining('Titular de Campo'), findsWidgets);
      expect(find.textContaining('El Arquero'), findsWidgets);
    });
  });

  group('CambiosSolicitarScreen (grupoArco) — candidatos filtrados por la plaza del TITULAR', () {
    testWidgets('the displayed techo is the FIELD plaza\'s own (2.0), never the goal plaza\'s '
        '(5.0)', (tester) async {
      await _pumpGrouped(tester);

      expect(find.text('Puntaje máximo para este cambio: 2 pts.'), findsOneWidget);
      expect(find.text('Puntaje máximo para este cambio: 5 pts.'), findsNothing);
    });

    testWidgets('a puntaje chip above the FIELD plaza\'s techo (2.0) is disabled, even though '
        'it is within the GOAL plaza\'s techo (5.0)', (tester) async {
      await _pumpGrouped(tester);

      final chip3 = tester.widget<GestureDetector>(find.byKey(const Key('puntaje_chip_3.0')));
      // Disabled chips have a null onTap — see _PuntajeChips' own docblock.
      expect(chip3.onTap, isNull);
    });
  });

  group('CambiosSolicitarScreen (grupoArco) — envío agrupado', () {
    testWidgets('confirming posts tipo=reasignacion_arquero with plaza_id=GOAL plaza, '
        'entrante_player_id=the field titular, entrante_campo_player_id=the chosen candidate, '
        'and NEVER calls the plain crearSolicitud', (tester) async {
      final fakeService = _FakeSubmitService();
      final plantel = _StubPlantelController();

      await _pumpGrouped(
        tester,
        listaEsperaState: const CambiosCandidatosLoaded(
          candidatos: [
            CambiosCandidato(playerId: 900, nombre: 'Candidato Externo', esPadre: false, puntaje: 2.0, viable: true),
          ],
          query: '',
        ),
        apiService: fakeService,
        plantelController: plantel,
      );

      await tester.tap(find.byKey(const Key('candidato_900')));
      await tester.pump();
      await tester.tap(find.byKey(const Key('confirmar_solicitud_button')));
      await tester.pumpAndSettle();

      expect(fakeService.lastSustitucionCall, isNull,
          reason: 'grouped mode must never fall back to the plain sustitucion endpoint');
      expect(fakeService.lastGrupoCall, isNotNull);
      expect(fakeService.lastGrupoCall!['seasonId'], 7);
      expect(fakeService.lastGrupoCall!['teamId'], 1);
      expect(fakeService.lastGrupoCall!['plazaArcoId'], 30, reason: 'must be the GOAL plaza, not the field plaza');
      expect(fakeService.lastGrupoCall!['fechaId'], 42);
      expect(fakeService.lastGrupoCall!['entrantePlayerId'], 150, reason: 'the field titular moving into goal');
      expect(fakeService.lastGrupoCall!['entranteCampoPlayerId'], 900, reason: 'the outside candidate chosen here');
      expect(plantel.refreshCalls, 1);
      expect(find.byType(CambiosSolicitarScreen), findsNothing);
    });
  });

  group('CambiosSolicitarScreen (grupoArco) — narrow width', () {
    testWidgets('320px, long names: no RenderFlex overflow', (tester) async {
      tester.view.physicalSize = const Size(320, 640);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      final plazaCampoLarga = CambiosPlaza(
        plazaId: _plazaCampo.plazaId,
        titularPlayerId: _plazaCampo.titularPlayerId,
        titularNombre: 'Von Hohenzollern-Sigmaringen, Maximiliano Alejandro',
        ocupantePlayerId: _plazaCampo.ocupantePlayerId,
        ocupanteNombre: _plazaCampo.ocupanteNombre,
        esTitularElOcupante: _plazaCampo.esTitularElOcupante,
        cerrada: _plazaCampo.cerrada,
        puntajeTecho: _plazaCampo.puntajeTecho,
      );
      final plazaArcoLarga = CambiosPlaza(
        plazaId: _plazaArco.plazaId,
        titularPlayerId: _plazaArco.titularPlayerId,
        titularNombre: 'Fernández Etcheverrigaray, Juan Bautista Ignacio',
        ocupantePlayerId: _plazaArco.ocupantePlayerId,
        ocupanteNombre: _plazaArco.ocupanteNombre,
        esTitularElOcupante: _plazaArco.esTitularElOcupante,
        cerrada: _plazaArco.cerrada,
        puntajeTecho: _plazaArco.puntajeTecho,
      );

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            cambiosCandidatosControllerProvider(_paramsFor(CambiosCandidatosSeccion.listaEspera))
                .overrideWith((ref) => _StubCandidatosController(
                      const CambiosCandidatosLoaded(candidatos: [], query: ''),
                    )),
            cambiosCandidatosControllerProvider(_paramsFor(CambiosCandidatosSeccion.padronCompleto))
                .overrideWith((ref) => _StubCandidatosController(
                      const CambiosCandidatosIdle(),
                      seccion: CambiosCandidatosSeccion.padronCompleto,
                    )),
            cambiosPlantelControllerProvider(_scope).overrideWith((ref) => _StubPlantelController()),
            cambiosFechaAbiertaProvider(_scope.seasonId).overrideWith((ref) => Future.value(_fecha)),
          ],
          child: MaterialApp(
            home: CambiosSolicitarScreen(
              seasonId: 7,
              teamId: 1,
              plaza: plazaCampoLarga,
              tipo: CambiosSolicitudTipo.sustitucion,
              puntaje: 4.5,
              grupoArco: CambiosGrupoArcoContext(plazaArco: plazaArcoLarga),
            ),
          ),
        ),
      );
      await tester.pump();
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.byKey(const Key('grupo_arco_banner')), findsOneWidget);
    });
  });
}

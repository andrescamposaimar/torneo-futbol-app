import 'dart:async';

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

// ---------------------------------------------------------------------------
// Fakes / stubs
// ---------------------------------------------------------------------------

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
  _StubCandidatosController(CambiosCandidatosState initialState)
      : super(_baseFakeService(), seasonId: 7, teamId: 1, plazaId: 10) {
    state = initialState;
  }

  @override
  Future<void> load({String query = ''}) async {}
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

/// Fake service whose crearSolicitud() is fully controllable — succeeds
/// (and records the call) or throws, per test.
class _FakeSubmitService extends CambiosApiService {
  bool shouldFail;
  Map<String, Object?>? lastCall;

  _FakeSubmitService({this.shouldFail = false})
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
    lastCall = {
      'seasonId': seasonId,
      'teamId': teamId,
      'plazaId': plazaId,
      'tipo': tipo,
      'fechaId': fechaId,
      'entrantePlayerId': entrantePlayerId,
    };
    if (shouldFail) {
      throw const CambiosApiException(statusCode: 500, code: 'error_interno');
    }
    return const CambiosNuevaSolicitud(
      id: 1,
      estado: CambiosSolicitudEstado.pendiente,
      dictamen: CambiosDictamen(procede: true),
    );
  }
}

final _plaza = CambiosPlaza(
  plazaId: 10,
  tipo: 'campo',
  titularPlayerId: 100,
  titularNombre: 'Juan Pérez',
  ocupantePlayerId: 200,
  ocupanteNombre: 'Pedro Gómez',
  esTitularElOcupante: false,
  cerrada: false,
  fechasFaltantesLiberacion: 0,
  fechasFaltantesLiberacionIndeterminado: false,
);

CambiosCandidatosParams get _params => (seasonId: 7, teamId: 1, plazaId: 10);
CambiosTeamScope get _scope => (seasonId: 7, teamId: 1);

/// An open fecha with both windows open by default — the common case for
/// tests that are not specifically about the fecha-fetch machinery.
CambiosFechaAbierta _fechaAbierta({
  int fechaId = 42,
  bool regresoAbierta = true,
  bool sustitucionAbierta = true,
}) =>
    CambiosFechaAbierta(
      fechaId: fechaId,
      numeroEnTorneo: 3,
      torneo: 'Apertura',
      playDate: '2026-01-10',
      regresoAbierta: regresoAbierta,
      sustitucionAbierta: sustitucionAbierta,
    );

Future<void> _pumpScreen(
  WidgetTester tester, {
  required CambiosSolicitudTipo tipo,
  CambiosCandidatosState candidatosState = const CambiosCandidatosLoaded(candidatos: [], query: ''),
  CambiosApiService? apiService,
  _StubPlantelController? plantelController,
  // Defaults to an open fecha with both windows open — pass `null` to
  // simulate "no open fecha" (either {"fecha": null} or a failed fetch).
  CambiosFechaAbierta? fecha = _defaultFecha,
}) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        cambiosCandidatosControllerProvider(_params)
            .overrideWith((ref) => _StubCandidatosController(candidatosState)),
        cambiosPlantelControllerProvider(_scope)
            .overrideWith((ref) => plantelController ?? _StubPlantelController()),
        cambiosFechaAbiertaProvider(_scope.seasonId).overrideWith((ref) => Future.value(fecha)),
        if (apiService != null) cambiosApiServiceProvider.overrideWithValue(apiService),
      ],
      child: MaterialApp(
        home: CambiosSolicitarScreen(
          seasonId: 7,
          teamId: 1,
          plaza: _plaza,
          tipo: tipo,
        ),
      ),
    ),
  );
  await tester.pump();
  // Lets the FutureProvider resolve (it's already a completed Future, but
  // still needs a microtask turn).
  await tester.pump();
}

const _defaultFecha = CambiosFechaAbierta(
  fechaId: 42,
  numeroEnTorneo: 3,
  torneo: 'Apertura',
  playDate: '2026-01-10',
  regresoAbierta: true,
  sustitucionAbierta: true,
);

void main() {
  group('CambiosSolicitarScreen — sustitucion', () {
    testWidgets('candidatos loading -> shows a spinner', (tester) async {
      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.sustitucion,
        candidatosState: const CambiosCandidatosLoading(),
      );
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('candidatos error -> shows retry', (tester) async {
      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.sustitucion,
        candidatosState: const CambiosCandidatosError(),
      );
      expect(find.text('Reintentar'), findsOneWidget);
    });

    testWidgets('candidatos empty -> shows the empty message', (tester) async {
      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.sustitucion,
        candidatosState: const CambiosCandidatosLoaded(candidatos: [], query: ''),
      );
      expect(find.text('No encontramos candidatos disponibles para esta plaza.'), findsOneWidget);
    });

    testWidgets('candidatos loaded with an open fecha -> renders the list and selecting one is '
        'required to submit', (tester) async {
      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.sustitucion,
        candidatosState: const CambiosCandidatosLoaded(
          candidatos: [
            CambiosCandidato(playerId: 200, nombre: 'Pedro Gómez', esPadre: false, puntaje: 3.5, viable: true),
          ],
          query: '',
        ),
      );

      expect(find.byKey(const Key('candidatos_list')), findsOneWidget);
      final confirmButton = tester.widget<ElevatedButton>(
        find.byKey(const Key('confirmar_solicitud_button')),
      );
      expect(confirmButton.onPressed, isNull); // no candidate selected yet

      await tester.tap(find.byKey(const Key('candidato_200')));
      await tester.pump();

      final confirmAfter = tester.widget<ElevatedButton>(
        find.byKey(const Key('confirmar_solicitud_button')),
      );
      expect(confirmAfter.onPressed, isNotNull);
    });

    testWidgets('no open fecha -> shows the honest gap banner and disables submit even '
        'with a candidate selected', (tester) async {
      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.sustitucion,
        candidatosState: const CambiosCandidatosLoaded(
          candidatos: [
            CambiosCandidato(playerId: 200, nombre: 'Pedro Gómez', esPadre: false, puntaje: 3.5, viable: true),
          ],
          query: '',
        ),
        fecha: null,
      );

      expect(find.byKey(const Key('fecha_gap_banner')), findsOneWidget);

      await tester.tap(find.byKey(const Key('candidato_200')));
      await tester.pump();

      final confirmButton = tester.widget<ElevatedButton>(
        find.byKey(const Key('confirmar_solicitud_button')),
      );
      expect(confirmButton.onPressed, isNull);
    });

    testWidgets(
        '_FechaLoadingBanner shows while cambiosFechaAbiertaProvider has not resolved yet '
        '(FIX 7)', (tester) async {
      // Unlike _pumpScreen (which always overrides with an already-resolved
      // Future.value(...) and pumps twice to let it settle before any
      // assertion), this uses a Completer that never completes during the
      // test, so the FutureProvider stays in its loading state and
      // _FechaLoadingBanner (key 'fecha_loading_banner') is actually observed
      // instead of being settled past before the first expect().
      final completer = Completer<CambiosFechaAbierta?>();

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            cambiosCandidatosControllerProvider(_params)
                .overrideWith((ref) => _StubCandidatosController(const CambiosCandidatosLoaded(
                      candidatos: [],
                      query: '',
                    ))),
            cambiosPlantelControllerProvider(_scope).overrideWith((ref) => _StubPlantelController()),
            cambiosFechaAbiertaProvider(_scope.seasonId).overrideWith((ref) => completer.future),
          ],
          child: MaterialApp(
            home: CambiosSolicitarScreen(
              seasonId: 7,
              teamId: 1,
              plaza: _plaza,
              tipo: CambiosSolicitudTipo.sustitucion,
            ),
          ),
        ),
      );
      await tester.pump(); // one frame only: the future is still pending here.

      expect(find.byKey(const Key('fecha_loading_banner')), findsOneWidget);
      expect(find.byKey(const Key('fecha_gap_banner')), findsNothing);
      expect(find.byKey(const Key('ventana_cerrada_banner')), findsNothing);

      final confirmButton = tester.widget<ElevatedButton>(
        find.byKey(const Key('confirmar_solicitud_button')),
      );
      expect(confirmButton.onPressed, isNull);

      // Resolve the Future before the test ends so the FutureProvider does
      // not leave a dangling subscription across tests.
      completer.complete(null);
      await tester.pump();
    });

    testWidgets('sustitucion window already closed -> shows the ventana cerrada banner and '
        'disables submit even with a candidate selected', (tester) async {
      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.sustitucion,
        candidatosState: const CambiosCandidatosLoaded(
          candidatos: [
            CambiosCandidato(playerId: 200, nombre: 'Pedro Gómez', esPadre: false, puntaje: 3.5, viable: true),
          ],
          query: '',
        ),
        fecha: _fechaAbierta(sustitucionAbierta: false),
      );

      expect(find.byKey(const Key('ventana_cerrada_banner')), findsOneWidget);
      expect(find.byKey(const Key('fecha_gap_banner')), findsNothing);

      await tester.tap(find.byKey(const Key('candidato_200')));
      await tester.pump();

      final confirmButton = tester.widget<ElevatedButton>(
        find.byKey(const Key('confirmar_solicitud_button')),
      );
      expect(confirmButton.onPressed, isNull);
    });

    testWidgets('successful submit sends the real fechaId, pops the screen and refreshes the roster',
        (tester) async {
      final fakeService = _FakeSubmitService();
      final plantel = _StubPlantelController();

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            cambiosCandidatosControllerProvider(_params).overrideWith(
              (ref) => _StubCandidatosController(const CambiosCandidatosLoaded(
                candidatos: [
                  CambiosCandidato(
                      playerId: 200, nombre: 'Pedro Gómez', esPadre: false, puntaje: 3.5, viable: true),
                ],
                query: '',
              )),
            ),
            cambiosPlantelControllerProvider(_scope).overrideWith((ref) => plantel),
            cambiosFechaAbiertaProvider(_scope.seasonId)
                .overrideWith((ref) => Future.value(_fechaAbierta(fechaId: 42))),
            cambiosApiServiceProvider.overrideWithValue(fakeService),
          ],
          child: MaterialApp(
            home: Navigator(
              onGenerateRoute: (settings) => MaterialPageRoute(
                builder: (_) => CambiosSolicitarScreen(
                  seasonId: 7,
                  teamId: 1,
                  plaza: _plaza,
                  tipo: CambiosSolicitudTipo.sustitucion,
                ),
              ),
            ),
          ),
        ),
      );
      await tester.pump();
      await tester.pump();

      await tester.tap(find.byKey(const Key('candidato_200')));
      await tester.pump();
      await tester.tap(find.byKey(const Key('confirmar_solicitud_button')));
      await tester.pumpAndSettle();

      expect(fakeService.lastCall, isNotNull);
      expect(fakeService.lastCall!['tipo'], CambiosSolicitudTipo.sustitucion);
      expect(fakeService.lastCall!['entrantePlayerId'], 200);
      expect(fakeService.lastCall!['fechaId'], 42);
      expect(plantel.refreshCalls, 1);
      // The screen itself is gone after popping.
      expect(find.byType(CambiosSolicitarScreen), findsNothing);
    });

    testWidgets('a failed submit shows an inline friendly error and stays on screen', (tester) async {
      final fakeService = _FakeSubmitService(shouldFail: true);

      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.sustitucion,
        candidatosState: const CambiosCandidatosLoaded(
          candidatos: [
            CambiosCandidato(playerId: 200, nombre: 'Pedro Gómez', esPadre: false, puntaje: 3.5, viable: true),
          ],
          query: '',
        ),
        apiService: fakeService,
      );

      await tester.tap(find.byKey(const Key('candidato_200')));
      await tester.pump();
      await tester.tap(find.byKey(const Key('confirmar_solicitud_button')));
      await tester.pumpAndSettle();

      expect(find.text('No se pudo enviar el pedido. Probá de nuevo en unos minutos.'),
          findsOneWidget);
      expect(find.byType(CambiosSolicitarScreen), findsOneWidget);
    });
  });

  group('CambiosSolicitarScreen — regreso', () {
    testWidgets('shows a direct confirm, no search field or candidate list', (tester) async {
      await _pumpScreen(tester, tipo: CambiosSolicitudTipo.regreso);

      expect(find.byKey(const Key('candidato_search_field')), findsNothing);
      expect(find.textContaining('¿Confirmás pedir el regreso de Juan Pérez'), findsOneWidget);

      final confirmButton = tester.widget<ElevatedButton>(
        find.byKey(const Key('confirmar_solicitud_button')),
      );
      // No candidate needed for a regreso — submit is enabled as soon as the
      // fecha is open for it.
      expect(confirmButton.onPressed, isNotNull);
    });

    testWidgets('regreso window already closed but sustitucion still open -> disables submit '
        'and explains itself', (tester) async {
      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.regreso,
        fecha: _fechaAbierta(regresoAbierta: false, sustitucionAbierta: true),
      );

      expect(find.byKey(const Key('ventana_cerrada_banner')), findsOneWidget);

      final confirmButton = tester.widget<ElevatedButton>(
        find.byKey(const Key('confirmar_solicitud_button')),
      );
      expect(confirmButton.onPressed, isNull);
    });
  });
}

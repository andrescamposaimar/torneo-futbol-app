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

/// A hand-written stub `StateNotifier` subclass — same convention as every
/// other Cambios controller stub in this app (no mocking package). Tracks
/// [loadCalls] so a test can assert a section's controller was NEVER asked
/// to fetch (the lazy `padronCompleto` section, until the captain opens it),
/// and [lastQuery]/[lastPuntajes] so a test can assert WHAT a reload was
/// asked to filter by — the server-side search/puntaje contract this slice
/// moved into [CambiosCandidatosController] (see that class's own docblock).
class _StubCandidatosController extends CambiosCandidatosController {
  int loadCalls = 0;
  int loadMoreCalls = 0;
  String? lastQuery;
  List<double>? lastPuntajes;
  final CambiosCandidatosState Function(String query, List<double> puntajes)? onLoad;

  _StubCandidatosController(
    CambiosCandidatosState initialState, {
    CambiosCandidatosSeccion seccion = CambiosCandidatosSeccion.listaEspera,
    this.onLoad,
  }) : super(
          _baseFakeService(),
          seasonId: 7,
          teamId: 1,
          plazaId: 10,
          seccion: seccion,
          autoLoad: false,
        ) {
    state = initialState;
  }

  @override
  Future<void> load({String query = '', List<double> puntajes = const []}) async {
    loadCalls++;
    lastQuery = query;
    lastPuntajes = puntajes;
    if (onLoad != null) {
      state = onLoad!(query, puntajes);
    }
  }

  @override
  Future<void> loadMore() async {
    loadMoreCalls++;
  }
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
  titularPlayerId: 100,
  titularNombre: 'Juan Pérez',
  ocupantePlayerId: 200,
  ocupanteNombre: 'Pedro Gómez',
  esTitularElOcupante: false,
  cerrada: false,
  puntajeTecho: 3.0,
  fechasFaltantesLiberacion: 0,
  fechasFaltantesLiberacionIndeterminado: false,
);

CambiosCandidatosParams _paramsFor(CambiosCandidatosSeccion seccion) => (
      seasonId: 7,
      teamId: 1,
      plazaId: 10,
      seccion: seccion,
    );
final _paramsListaEspera = _paramsFor(CambiosCandidatosSeccion.listaEspera);
final _paramsPadronCompleto = _paramsFor(CambiosCandidatosSeccion.padronCompleto);

CambiosTeamScope get _scope => (seasonId: 7, teamId: 1);

/// An open fecha with both windows open by default — the common case for
/// tests that are not specifically about the fecha-fetch machinery.
CambiosFechaAbierta _fechaAbierta({
  int fechaId = 42,
  CambiosVentanaFase regresoFase = CambiosVentanaFase.abierta,
  CambiosVentanaFase sustitucionFase = CambiosVentanaFase.abierta,
  DateTime? aperturaSolicitudesUtc,
}) =>
    CambiosFechaAbierta(
      fechaId: fechaId,
      numeroEnTorneo: 3,
      torneo: 'Apertura',
      playDate: '2026-01-10',
      regresoFase: regresoFase,
      sustitucionFase: sustitucionFase,
      aperturaSolicitudesUtc: aperturaSolicitudesUtc,
    );

const _defaultFecha = CambiosFechaAbierta(
  fechaId: 42,
  numeroEnTorneo: 3,
  torneo: 'Apertura',
  playDate: '2026-01-10',
  regresoFase: CambiosVentanaFase.abierta,
  sustitucionFase: CambiosVentanaFase.abierta,
);

/// [listaEsperaState] backs the eagerly-loaded section (the screen's default
/// view); [padronCompletoController], if given, backs the LAZY section — a
/// test only needs to pass it when it actually switches to "Padrón
/// Completo".
Future<void> _pumpScreen(
  WidgetTester tester, {
  required CambiosSolicitudTipo tipo,
  CambiosCandidatosState listaEsperaState = const CambiosCandidatosLoaded(candidatos: [], query: ''),
  _StubCandidatosController? padronCompletoController,
  CambiosApiService? apiService,
  _StubPlantelController? plantelController,
  // Defaults to an open fecha with both windows open — pass `null` to
  // simulate "no open fecha" (either {"fecha": null} or a failed fetch).
  CambiosFechaAbierta? fecha = _defaultFecha,
  double? puntaje,
  String? titularNombre,
}) async {
  final plaza = null == titularNombre
      ? _plaza
      : CambiosPlaza(
          plazaId: _plaza.plazaId,
          titularPlayerId: _plaza.titularPlayerId,
          titularNombre: titularNombre,
          ocupantePlayerId: _plaza.ocupantePlayerId,
          ocupanteNombre: _plaza.ocupanteNombre,
          esTitularElOcupante: _plaza.esTitularElOcupante,
          cerrada: _plaza.cerrada,
          puntajeTecho: _plaza.puntajeTecho,
          fechasFaltantesLiberacion: _plaza.fechasFaltantesLiberacion,
          fechasFaltantesLiberacionIndeterminado: _plaza.fechasFaltantesLiberacionIndeterminado,
        );

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        cambiosCandidatosControllerProvider(_paramsListaEspera).overrideWith(
          (ref) => _StubCandidatosController(
            listaEsperaState,
            seccion: CambiosCandidatosSeccion.listaEspera,
          ),
        ),
        cambiosCandidatosControllerProvider(_paramsPadronCompleto).overrideWith(
          (ref) =>
              padronCompletoController ??
              _StubCandidatosController(
                const CambiosCandidatosIdle(),
                seccion: CambiosCandidatosSeccion.padronCompleto,
              ),
        ),
        cambiosPlantelControllerProvider(_scope)
            .overrideWith((ref) => plantelController ?? _StubPlantelController()),
        cambiosFechaAbiertaProvider(_scope.seasonId).overrideWith((ref) => Future.value(fecha)),
        if (apiService != null) cambiosApiServiceProvider.overrideWithValue(apiService),
      ],
      child: MaterialApp(
        home: CambiosSolicitarScreen(
          seasonId: 7,
          teamId: 1,
          plaza: plaza,
          tipo: tipo,
          puntaje: puntaje,
        ),
      ),
    ),
  );
  await tester.pump();
  // Lets the FutureProvider resolve (it's already a completed Future, but
  // still needs a microtask turn).
  await tester.pump();
}

/// Like [_pumpScreen], but takes a fully custom [listaEsperaController] stub
/// instead of a plain initial state — needed by tests that must observe
/// calls made back to that controller ([_StubCandidatosController.loadCalls]
/// / `lastQuery` / `lastPuntajes` / `loadMoreCalls`), which a bare state
/// value cannot carry.
Future<void> _pumpScreenWithControllers(
  WidgetTester tester, {
  required _StubCandidatosController listaEsperaController,
}) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        cambiosCandidatosControllerProvider(_paramsListaEspera).overrideWith((ref) => listaEsperaController),
        cambiosCandidatosControllerProvider(_paramsPadronCompleto).overrideWith(
          (ref) => _StubCandidatosController(
            const CambiosCandidatosIdle(),
            seccion: CambiosCandidatosSeccion.padronCompleto,
          ),
        ),
        cambiosPlantelControllerProvider(_scope).overrideWith((ref) => _StubPlantelController()),
        cambiosFechaAbiertaProvider(_scope.seasonId).overrideWith((ref) => Future.value(_defaultFecha)),
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
  await tester.pump();
  await tester.pump();
}

void main() {
  group('CambiosSolicitarScreen — sustitucion', () {
    testWidgets('candidatos loading -> shows a spinner', (tester) async {
      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.sustitucion,
        listaEsperaState: const CambiosCandidatosLoading(),
      );
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('candidatos error -> shows retry', (tester) async {
      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.sustitucion,
        listaEsperaState: const CambiosCandidatosError(),
      );
      expect(find.text('Reintentar'), findsOneWidget);
    });

    testWidgets('candidatos empty -> shows the empty message', (tester) async {
      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.sustitucion,
        listaEsperaState: const CambiosCandidatosLoaded(candidatos: [], query: ''),
      );
      expect(find.text('No encontramos candidatos disponibles para esta plaza.'), findsOneWidget);
    });

    testWidgets('candidatos loaded with an open fecha -> renders the list and selecting one is '
        'required to submit', (tester) async {
      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.sustitucion,
        listaEsperaState: const CambiosCandidatosLoaded(
          candidatos: [
            CambiosCandidato(playerId: 200, nombre: 'Pedro Gómez', esPadre: false, puntaje: 2.5, viable: true),
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

    testWidgets('renders candidatos in the SERVER order, never re-sorted client-side',
        (tester) async {
      // Deliberately NOT puntaje-descending — the backend now owns the
      // whole-population ordering (`puntaje DESC, nombre ASC, player_id ASC`
      // — see `Plazas\CandidatosResolver::buscarPaginado()`'s own docblock,
      // "THE SORT KEY"). A lingering client-side sort (the regression this
      // test guards against — see `_CandidatosList`'s own docblock, "ORDERING
      // IS THE SERVER'S, NEVER RE-SORTED HERE") would silently re-order this
      // into 303 (5.0), 302 (3.0), 301 (1.0) instead of rendering the three
      // rows exactly as the state hands them over.
      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.sustitucion,
        listaEsperaState: const CambiosCandidatosLoaded(
          candidatos: [
            CambiosCandidato(playerId: 301, nombre: 'Bajo Puntaje', esPadre: false, puntaje: 1.0, viable: true),
            CambiosCandidato(playerId: 302, nombre: 'Medio Puntaje', esPadre: false, puntaje: 3.0, viable: true),
            CambiosCandidato(playerId: 303, nombre: 'Alto Puntaje', esPadre: false, puntaje: 5.0, viable: true),
          ],
          query: '',
        ),
      );

      final y301 = tester.getTopLeft(find.byKey(const Key('candidato_301'))).dy;
      final y302 = tester.getTopLeft(find.byKey(const Key('candidato_302'))).dy;
      final y303 = tester.getTopLeft(find.byKey(const Key('candidato_303'))).dy;

      expect(y301, lessThan(y302), reason: 'candidato_301 (1.0) must render ABOVE candidato_302 (3.0) — '
          'the state order, not puntaje descending.');
      expect(y302, lessThan(y303), reason: 'candidato_302 (3.0) must render ABOVE candidato_303 (5.0) — '
          'the state order, not puntaje descending.');
    });

    testWidgets('no open fecha -> shows the honest gap banner and disables submit even '
        'with a candidate selected', (tester) async {
      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.sustitucion,
        listaEsperaState: const CambiosCandidatosLoaded(
          candidatos: [
            CambiosCandidato(playerId: 200, nombre: 'Pedro Gómez', esPadre: false, puntaje: 2.5, viable: true),
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
            cambiosCandidatosControllerProvider(_paramsListaEspera).overrideWith(
              (ref) => _StubCandidatosController(const CambiosCandidatosLoaded(
                candidatos: [],
                query: '',
              )),
            ),
            cambiosCandidatosControllerProvider(_paramsPadronCompleto).overrideWith(
              (ref) => _StubCandidatosController(
                const CambiosCandidatosIdle(),
                seccion: CambiosCandidatosSeccion.padronCompleto,
              ),
            ),
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
        listaEsperaState: const CambiosCandidatosLoaded(
          candidatos: [
            CambiosCandidato(playerId: 200, nombre: 'Pedro Gómez', esPadre: false, puntaje: 2.5, viable: true),
          ],
          query: '',
        ),
        fecha: _fechaAbierta(sustitucionFase: CambiosVentanaFase.cerrada),
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

    testWidgets('sustitucion window not open yet -> shows the ventana antes banner with the '
        'opening date, not the ya-cerró copy', (tester) async {
      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.sustitucion,
        listaEsperaState: const CambiosCandidatosLoaded(
          candidatos: [
            CambiosCandidato(playerId: 200, nombre: 'Pedro Gómez', esPadre: false, puntaje: 2.5, viable: true),
          ],
          query: '',
        ),
        fecha: _fechaAbierta(
          sustitucionFase: CambiosVentanaFase.antes,
          // 2026-10-11 03:00:00 UTC == 2026-10-11 00:00:00 in Buenos Aires
          // (UTC-3, no DST) — a Sunday.
          aperturaSolicitudesUtc: DateTime.utc(2026, 10, 11, 3),
        ),
      );

      expect(find.byKey(const Key('ventana_antes_banner')), findsOneWidget);
      expect(find.byKey(const Key('ventana_cerrada_banner')), findsNothing);
      expect(
        find.text('Los cambios se habilitan a partir del domingo 11/10.'),
        findsOneWidget,
      );

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
            cambiosCandidatosControllerProvider(_paramsListaEspera).overrideWith(
              (ref) => _StubCandidatosController(const CambiosCandidatosLoaded(
                candidatos: [
                  CambiosCandidato(
                      playerId: 200, nombre: 'Pedro Gómez', esPadre: false, puntaje: 2.5, viable: true),
                ],
                query: '',
              )),
            ),
            cambiosCandidatosControllerProvider(_paramsPadronCompleto).overrideWith(
              (ref) => _StubCandidatosController(
                const CambiosCandidatosIdle(),
                seccion: CambiosCandidatosSeccion.padronCompleto,
              ),
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
        listaEsperaState: const CambiosCandidatosLoaded(
          candidatos: [
            CambiosCandidato(playerId: 200, nombre: 'Pedro Gómez', esPadre: false, puntaje: 2.5, viable: true),
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

  group('CambiosSolicitarScreen — las dos secciones de candidatos', () {
    testWidgets('both sections render — Lista de Espera by default, Padrón Completo after toggling',
        (tester) async {
      final padronController = _StubCandidatosController(
        const CambiosCandidatosLoaded(
          candidatos: [
            CambiosCandidato(playerId: 900, nombre: 'Nico del Padrón', esPadre: false, puntaje: 2.5, viable: true),
          ],
          query: '',
        ),
        seccion: CambiosCandidatosSeccion.padronCompleto,
      );

      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.sustitucion,
        listaEsperaState: const CambiosCandidatosLoaded(
          candidatos: [
            CambiosCandidato(playerId: 200, nombre: 'Pedro Gómez', esPadre: false, puntaje: 2.5, viable: true),
          ],
          query: '',
        ),
        padronCompletoController: padronController,
      );

      // Default view: Lista de Espera.
      expect(find.byKey(const Key('candidato_200')), findsOneWidget);
      expect(find.byKey(const Key('candidato_900')), findsNothing);

      await tester.tap(find.text('Padrón Completo'));
      await tester.pump();

      expect(find.byKey(const Key('candidato_200')), findsNothing);
      expect(find.byKey(const Key('candidato_900')), findsOneWidget);
    });

    testWidgets('Padrón Completo issues no request until the captain opens it', (tester) async {
      final padronController = _StubCandidatosController(
        const CambiosCandidatosIdle(),
        seccion: CambiosCandidatosSeccion.padronCompleto,
        onLoad: (query, puntajes) => CambiosCandidatosLoaded(candidatos: const [], query: query, puntajes: puntajes),
      );

      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.sustitucion,
        padronCompletoController: padronController,
      );

      // The common case: opening the step only ever touched Lista de
      // Espera's own controller — Padrón Completo's was never asked to load.
      expect(padronController.loadCalls, 0);

      await tester.tap(find.text('Padrón Completo'));
      await tester.pump();

      expect(padronController.loadCalls, 1);

      // Switching back and forth again must not re-fetch.
      await tester.tap(find.text('Lista de Espera'));
      await tester.pump();
      await tester.tap(find.text('Padrón Completo'));
      await tester.pump();

      expect(padronController.loadCalls, 1);
    });

    testWidgets('selecting a candidate from Padrón Completo submits the same way as Lista de Espera',
        (tester) async {
      final fakeService = _FakeSubmitService();
      final padronController = _StubCandidatosController(
        const CambiosCandidatosLoaded(
          candidatos: [
            CambiosCandidato(playerId: 900, nombre: 'Nico del Padrón', esPadre: false, puntaje: 2.5, viable: true),
          ],
          query: '',
        ),
        seccion: CambiosCandidatosSeccion.padronCompleto,
      );
      final plantel = _StubPlantelController();

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            cambiosCandidatosControllerProvider(_paramsListaEspera)
                .overrideWith((ref) => _StubCandidatosController(const CambiosCandidatosLoaded(
                      candidatos: [],
                      query: '',
                    ))),
            cambiosCandidatosControllerProvider(_paramsPadronCompleto)
                .overrideWith((ref) => padronController),
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

      await tester.tap(find.text('Padrón Completo'));
      await tester.pump();

      await tester.tap(find.byKey(const Key('candidato_900')));
      await tester.pump();
      await tester.tap(find.byKey(const Key('confirmar_solicitud_button')));
      await tester.pumpAndSettle();

      expect(fakeService.lastCall!['entrantePlayerId'], 900);
      expect(plantel.refreshCalls, 1);
    });
  });

  group('CambiosSolicitarScreen — filtro por puntaje (techo de la plaza)', () {
    /// `_puntajesFiltro` is now a SERVER-SIDE filter — see
    /// `CambiosCandidatosController`'s own docblock — so this test drives a
    /// controller stub whose `onLoad` simulates the backend's own exact
    /// match over whatever candidatos are seeded, rather than asserting a
    /// client-side filter that no longer exists.
    testWidgets(
        'every puntaje is shown, chips above the ceiling are disabled, and an enabled chip '
        're-queries the section with the selected puntaje', (tester) async {
      // _plaza's own puntajeTecho is 3.0 — 3.5/4/4.5/5 exceed it.
      const candidatos = [
        CambiosCandidato(playerId: 200, nombre: 'Pedro Gómez', esPadre: false, puntaje: 2.5, viable: true),
        CambiosCandidato(playerId: 201, nombre: 'Marcos Díaz', esPadre: false, puntaje: 3.0, viable: true),
      ];

      final listaEsperaController = _StubCandidatosController(
        const CambiosCandidatosLoaded(candidatos: candidatos, query: ''),
        onLoad: (query, puntajes) => CambiosCandidatosLoaded(
          candidatos: puntajes.isEmpty
              ? candidatos
              : candidatos.where((c) => puntajes.contains(c.puntaje)).toList(),
          query: query,
          puntajes: puntajes,
        ),
      );

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            cambiosCandidatosControllerProvider(_paramsListaEspera)
                .overrideWith((ref) => listaEsperaController),
            cambiosCandidatosControllerProvider(_paramsPadronCompleto).overrideWith(
              (ref) => _StubCandidatosController(
                const CambiosCandidatosIdle(),
                seccion: CambiosCandidatosSeccion.padronCompleto,
              ),
            ),
            cambiosPlantelControllerProvider(_scope).overrideWith((ref) => _StubPlantelController()),
            cambiosFechaAbiertaProvider(_scope.seasonId).overrideWith((ref) => Future.value(_defaultFecha)),
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
      await tester.pump();
      await tester.pump();

      // Every one of the 9 valid puntajes is rendered, enabled or not.
      for (final valor in const <double>[5, 4.5, 4, 3.5, 3, 2.5, 2, 1.5, 1]) {
        expect(find.byKey(Key('puntaje_chip_$valor')), findsOneWidget,
            reason: 'puntaje $valor should always render, disabled or not');
      }

      // Tapping a DISABLED chip (above techo 3.0) must not even trigger a
      // reload — its onTap is null.
      await tester.tap(find.byKey(const Key('puntaje_chip_5.0')));
      await tester.pump();
      expect(listaEsperaController.loadCalls, 0);
      expect(find.byKey(const Key('candidato_200')), findsOneWidget);
      expect(find.byKey(const Key('candidato_201')), findsOneWidget);

      // Tapping an ENABLED chip (within techo) re-queries the section from
      // page 1 with the selected puntaje — simulated here by the stub's own
      // `onLoad`, exactly like the real backend would narrow it.
      await tester.tap(find.byKey(const Key('puntaje_chip_2.5')));
      await tester.pump();
      expect(listaEsperaController.loadCalls, 1);
      expect(listaEsperaController.lastPuntajes, [2.5]);
      expect(find.byKey(const Key('candidato_200')), findsOneWidget);
      expect(find.byKey(const Key('candidato_201')), findsNothing);
    });

    testWidgets('the plaza ceiling is displayed as text', (tester) async {
      await _pumpScreen(tester, tipo: CambiosSolicitudTipo.sustitucion);

      // _plaza's own puntajeTecho is 3.0 (see this file's top-level fixture).
      expect(find.text('Puntaje máximo para este cambio: 3 pts.'), findsOneWidget);
    });
  });

  group('CambiosSolicitarScreen — búsqueda y scroll infinito', () {
    /// THE correctness contract this slice's task brief names explicitly: a
    /// search must re-query the SERVER (debounced), never filter only the
    /// pages already loaded on the client — see
    /// `CambiosCandidatosController`'s own docblock.
    testWidgets('typing in the search field debounces and re-queries the section from page 1',
        (tester) async {
      final listaEsperaController = _StubCandidatosController(
        const CambiosCandidatosLoaded(candidatos: [], query: ''),
      );

      await _pumpScreenWithControllers(
        tester,
        listaEsperaController: listaEsperaController,
      );

      await tester.enterText(find.byKey(const Key('candidato_search_field')), 'zapata');
      // Before the debounce window elapses, no reload has fired yet.
      await tester.pump(const Duration(milliseconds: 100));
      expect(listaEsperaController.loadCalls, 0);

      await tester.pump(const Duration(milliseconds: 250));
      expect(listaEsperaController.loadCalls, 1);
      expect(listaEsperaController.lastQuery, 'zapata');
    });

    testWidgets('scrolling near the bottom of the list calls loadMore() on the visible section',
        (tester) async {
      final muchosCandidatos = List.generate(
        30,
        (i) => CambiosCandidato(playerId: i, nombre: 'Jugador $i', esPadre: false, puntaje: 2.5, viable: true),
      );

      final listaEsperaController = _StubCandidatosController(
        CambiosCandidatosLoaded(candidatos: muchosCandidatos, query: '', hasMore: true),
      );

      await _pumpScreenWithControllers(
        tester,
        listaEsperaController: listaEsperaController,
      );

      await tester.drag(find.byKey(const Key('candidatos_list')), const Offset(0, -4000));
      await tester.pump();

      expect(listaEsperaController.loadMoreCalls, greaterThan(0));
    });

    /// THE correctness contract fixed in this slice: a failed `loadMore()`
    /// used to make the bottom spinner simply disappear, rendering a list
    /// that STOPPED scrolling look indistinguishable from "that's the whole
    /// population" — the full-list [CambiosCandidatosError] case already got
    /// a retry affordance ([_CandidatosErrorView]); this proves the
    /// bottom-of-list failure now gets its own, equivalent one.
    testWidgets('a failed loadMore() renders a retry affordance that calls loadMore() again',
        (tester) async {
      final muchosCandidatos = List.generate(
        30,
        (i) => CambiosCandidato(playerId: i, nombre: 'Jugador $i', esPadre: false, puntaje: 2.5, viable: true),
      );

      final listaEsperaController = _StubCandidatosController(
        CambiosCandidatosLoaded(
          candidatos: muchosCandidatos,
          query: '',
          hasMore: true,
          loadMoreError: true,
        ),
      );

      await _pumpScreenWithControllers(
        tester,
        listaEsperaController: listaEsperaController,
      );

      // The retry row sits at the bottom of 30 items — scroll it into view,
      // same as this group's own loadMore()-triggering test does. The
      // scroll listener itself may also call loadMore() once it nears the
      // bottom (it has no reason to special-case a prior failure) — this
      // test cares only about the TAP, so it captures the call count right
      // before tapping rather than asserting an exact total.
      await tester.drag(find.byKey(const Key('candidatos_list')), const Offset(0, -4000));
      await tester.pump();

      expect(find.byKey(const Key('candidatos_load_more_error')), findsOneWidget);
      expect(find.text('No pudimos cargar más candidatos.'), findsOneWidget);

      final callsBeforeTap = listaEsperaController.loadMoreCalls;

      await tester.tap(find.text('Reintentar'));
      await tester.pump();

      expect(listaEsperaController.loadMoreCalls, callsBeforeTap + 1);
    });
  });

  group('CambiosSolicitarScreen — header puntaje', () {
    testWidgets('renders the titular name and the formatted puntaje, right-aligned',
        (tester) async {
      await _pumpScreen(tester, tipo: CambiosSolicitudTipo.sustitucion, puntaje: 4.5);

      expect(find.text('Juan Pérez'), findsOneWidget);
      expect(find.text('4.5 ptos'), findsOneWidget);

      // Right-aligned relative to the name: the puntaje's left edge sits
      // after the name's left edge within the same header row.
      final nombrePos = tester.getTopLeft(find.text('Juan Pérez'));
      final puntajePos = tester.getTopLeft(find.text('4.5 ptos'));
      expect(puntajePos.dx, greaterThan(nombrePos.dx));
    });

    testWidgets('a whole-number puntaje renders without a trailing .0', (tester) async {
      await _pumpScreen(tester, tipo: CambiosSolicitudTipo.sustitucion, puntaje: 5);

      expect(find.text('5 ptos'), findsOneWidget);
      expect(find.text('5.0 ptos'), findsNothing);
    });

    testWidgets('a null puntaje (roster fetch pending or failed) renders nothing on the right',
        (tester) async {
      await _pumpScreen(tester, tipo: CambiosSolicitudTipo.sustitucion, puntaje: null);

      expect(find.text('Juan Pérez'), findsOneWidget);
      expect(find.textContaining('ptos'), findsNothing);
      // Never an invented placeholder either.
      expect(find.text('0 ptos'), findsNothing);
      expect(find.text('- ptos'), findsNothing);
    });

    testWidgets('a puntaje of 0 ("sin calificar") renders nothing on the right, same as null',
        (tester) async {
      await _pumpScreen(tester, tipo: CambiosSolicitudTipo.sustitucion, puntaje: 0);

      expect(find.text('Juan Pérez'), findsOneWidget);
      expect(find.textContaining('ptos'), findsNothing);
    });

    testWidgets('a sustitucion leads the name with a DOWN arrow (the player leaving)', (tester) async {
      await _pumpScreen(tester, tipo: CambiosSolicitudTipo.sustitucion, puntaje: 4.5);

      expect(find.byIcon(Icons.arrow_downward), findsOneWidget);
      expect(find.byIcon(Icons.arrow_upward), findsNothing);

      // Reading order: icon first, then the name it qualifies.
      final iconoX = tester.getTopLeft(find.byIcon(Icons.arrow_downward)).dx;
      final nombreX = tester.getTopLeft(find.text('Juan Pérez')).dx;
      expect(nombreX, greaterThan(iconoX));

      // Red off, green on — the substitution board's own colours.
      expect(
        tester.widget<Icon>(find.byIcon(Icons.arrow_downward)).color,
        Colors.red.shade700,
      );
    });

    testWidgets('a regreso leads with an UP arrow, never the down one', (tester) async {
      // The two tipos move this player in OPPOSITE directions: a sustitucion
      // takes the titular OUT of the plaza, a regreso brings him BACK. The
      // down arrow here would state the opposite of what is happening.
      await _pumpScreen(tester, tipo: CambiosSolicitudTipo.regreso, puntaje: 4.5);

      expect(find.byIcon(Icons.arrow_upward), findsOneWidget);
      expect(find.byIcon(Icons.arrow_downward), findsNothing);
      expect(
        tester.widget<Icon>(find.byIcon(Icons.arrow_upward)).color,
        Colors.green.shade700,
      );
    });

    testWidgets('a long name ellipsizes rather than overflowing, even at a double text scale',
        (tester) async {
      // 320px with the widget-test font, whose glyphs are square: the same
      // pressure a real device puts on this row when the reader has large
      // text turned on. An earlier version of this header carried a
      // "Pedir cambio por: " prefix and overflowed here by 111px.
      tester.view.physicalSize = const Size(320, 640);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.sustitucion,
        puntaje: 4.5,
        titularNombre: 'Von Hohenzollern-Sigmaringen, Maximiliano Alejandro',
      );

      expect(tester.takeException(), isNull);
      // The puntaje survives whole — the NAME is what gives way.
      expect(find.text('4.5 ptos'), findsOneWidget);
      expect(find.byIcon(Icons.arrow_downward), findsOneWidget);
    });
  });

  group('CambiosSolicitarScreen — botón de envío', () {
    testWidgets('a sustitucion names the action: "Solicitar Cambio"', (tester) async {
      await _pumpScreen(tester, tipo: CambiosSolicitudTipo.sustitucion);

      expect(find.widgetWithText(ElevatedButton, 'Solicitar Cambio'), findsOneWidget);
      expect(find.text('Confirmar'), findsNothing);
    });

    testWidgets('a regreso says "Solicitar Regreso", never "Solicitar Cambio"', (tester) async {
      // A regreso is the END of a cambio, never a new one — labelling it
      // "Solicitar Cambio" would name the opposite action.
      await _pumpScreen(tester, tipo: CambiosSolicitudTipo.regreso);

      expect(find.widgetWithText(ElevatedButton, 'Solicitar Regreso'), findsOneWidget);
      expect(find.text('Solicitar Cambio'), findsNothing);
    });

    testWidgets('the button carries its own weight: tall, and primary-filled, not a tonal default',
        (tester) async {
      await _pumpScreen(tester, tipo: CambiosSolicitudTipo.sustitucion);

      final boton = find.byKey(const Key('confirmar_solicitud_button'));
      expect(tester.getSize(boton).height, greaterThanOrEqualTo(52));

      // Under Material 3 an ElevatedButton defaults to a surface-tinted
      // background; this screen's primary action sets its own colour, so a
      // future theme change cannot quietly turn it back into a low-emphasis
      // button.
      final style = tester.widget<ElevatedButton>(boton).style;
      expect(style?.backgroundColor, isNotNull);
      expect(style?.foregroundColor, isNotNull);
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
        fecha: _fechaAbierta(
          regresoFase: CambiosVentanaFase.cerrada,
          sustitucionFase: CambiosVentanaFase.abierta,
        ),
      );

      expect(find.byKey(const Key('ventana_cerrada_banner')), findsOneWidget);

      final confirmButton = tester.widget<ElevatedButton>(
        find.byKey(const Key('confirmar_solicitud_button')),
      );
      expect(confirmButton.onPressed, isNull);
    });

    testWidgets('regreso window not open yet -> shows the ventana antes banner with the plural '
        'regreso copy', (tester) async {
      await _pumpScreen(
        tester,
        tipo: CambiosSolicitudTipo.regreso,
        fecha: _fechaAbierta(
          regresoFase: CambiosVentanaFase.antes,
          // 2026-10-11 03:00:00 UTC == 2026-10-11 00:00:00 in Buenos Aires
          // (UTC-3, no DST) — a Sunday.
          aperturaSolicitudesUtc: DateTime.utc(2026, 10, 11, 3),
        ),
      );

      expect(find.byKey(const Key('ventana_antes_banner')), findsOneWidget);
      expect(find.byKey(const Key('ventana_cerrada_banner')), findsNothing);
      expect(
        find.text('Los regresos se habilitan a partir del domingo 11/10.'),
        findsOneWidget,
      );
    });
  });
}

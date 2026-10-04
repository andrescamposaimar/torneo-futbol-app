import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/models/cambios_candidato.dart';
import 'package:torneo_futbol_app/services/cambios_api_service.dart';
import 'package:torneo_futbol_app/services/cambios_candidatos_controller.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';

/// One `fetchCandidatos()` invocation captured by [_FakeCambiosApiService]:
/// its request params (so a test can tell WHICH call this is) plus a
/// [Completer] the test resolves on its own schedule. This is what lets a
/// test simulate an OLDER request resolving AFTER a newer one started — the
/// exact race [CambiosCandidatosController]'s request-generation guard
/// exists to survive (see that class's own docblock on `_requestGeneration`).
class _FetchCall {
  final String? search;
  final int page;
  final Completer<CambiosCandidatosPagina> completer = Completer<CambiosCandidatosPagina>();

  _FetchCall({required this.search, required this.page});
}

/// Real [CambiosApiService] subclass with `fetchCandidatos()` overridden to
/// never touch the network: every call is captured into [calls] and returns
/// a controllable, independently-resolvable future instead. Same
/// hand-written-fake convention every other Cambios test in this app already
/// uses (no mocking package) — see e.g.
/// `cambios_solicitar_screen_test.dart`'s `_baseFakeService()` for the same
/// "construct the real service with throwaway config, never actually used
/// because the network-facing method is overridden" pattern.
class _FakeCambiosApiService extends CambiosApiService {
  final List<_FetchCall> calls = [];

  _FakeCambiosApiService()
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
  Future<CambiosCandidatosPagina> fetchCandidatos({
    required int seasonId,
    required int teamId,
    required int plazaId,
    CambiosCandidatosSeccion? seccion,
    String? search,
    List<double> puntajes = const [],
    int page = 1,
    int perPage = 20,
  }) {
    final call = _FetchCall(search: search, page: page);
    calls.add(call);
    return call.completer.future;
  }
}

CambiosCandidato _candidato(int id) => CambiosCandidato(
      playerId: id,
      nombre: 'Jugador #$id',
      esPadre: false,
      viable: true,
    );

void main() {
  late _FakeCambiosApiService service;
  late CambiosCandidatosController controller;

  setUp(() {
    service = _FakeCambiosApiService();
    controller = CambiosCandidatosController(
      service,
      seasonId: 7,
      teamId: 1,
      plazaId: 10,
      seccion: CambiosCandidatosSeccion.listaEspera,
      autoLoad: false,
    );
  });

  tearDown(() {
    controller.dispose();
  });

  test(
    'an older loadMore() resolving after a newer load() must not win',
    () async {
      // 1) Initial load('a') — call 0, page 1.
      final loadA = controller.load(query: 'a');
      expect(service.calls, hasLength(1));
      service.calls[0].completer.complete(
        (candidatos: [_candidato(1), _candidato(2)], total: 100),
      );
      await loadA;

      final loadedA = controller.state as CambiosCandidatosLoaded;
      expect(loadedA.query, 'a');
      expect(loadedA.hasMore, isTrue);

      // 2) loadMore() starts while nothing else has happened yet — call 1,
      // page 2. Left deliberately unresolved (simulates the captain
      // scrolling into Padrón Completo while this request is in flight).
      final loadMoreFuture = controller.loadMore();
      expect(service.calls, hasLength(2));

      // 3) Before call 1 resolves, a NEWER load('b') starts (the debounced
      // search box) — call 2, page 1.
      final loadB = controller.load(query: 'b');
      expect(service.calls, hasLength(3));

      // 4) The newer load('b') resolves first.
      service.calls[2].completer.complete(
        (candidatos: [_candidato(9)], total: 1),
      );
      await loadB;

      final loadedB = controller.state as CambiosCandidatosLoaded;
      expect(loadedB.query, 'b');
      expect(loadedB.candidatos.map((c) => c.playerId), [9]);

      // 5) THEN the stale loadMore() (call 1, still for query 'a') finally
      // resolves.
      service.calls[1].completer.complete(
        (candidatos: [_candidato(3)], total: 100),
      );
      await loadMoreFuture;

      // The stale result must never have touched state — it must still be
      // exactly what load('b') produced, not a merge with the 'a' page.
      final finalState = controller.state as CambiosCandidatosLoaded;
      expect(finalState.query, 'b');
      expect(finalState.candidatos.map((c) => c.playerId), [9]);
    },
  );

  test(
    "two overlapping load() calls must leave the newer one's result in place",
    () async {
      final loadA = controller.load(query: 'a');
      final loadB = controller.load(query: 'b');

      expect(service.calls, hasLength(2));

      // Resolve the NEWER call first, then the stale one — order must not
      // matter: 'b' must win either way.
      service.calls[1].completer.complete(
        (candidatos: [_candidato(20)], total: 1),
      );
      await loadB;

      service.calls[0].completer.complete(
        (candidatos: [_candidato(10)], total: 1),
      );
      await loadA;

      final finalState = controller.state as CambiosCandidatosLoaded;
      expect(finalState.query, 'b');
      expect(finalState.candidatos.map((c) => c.playerId), [20]);
    },
  );

  test(
    'a failed loadMore() sets loadMoreError on the state, keeping the already-loaded '
    'candidatos intact',
    () async {
      final load = controller.load(query: '');
      service.calls[0].completer.complete(
        (candidatos: [_candidato(1), _candidato(2)], total: 100),
      );
      await load;

      final loadMoreFuture = controller.loadMore();
      service.calls[1].completer.completeError(Exception('network failure'));
      await loadMoreFuture;

      final finalState = controller.state as CambiosCandidatosLoaded;
      expect(finalState.loadMoreError, isTrue);
      expect(finalState.isLoadingMore, isFalse);
      // The already-loaded candidatos must survive a failed loadMore() —
      // there is something real on screen to lose here, unlike load().
      expect(finalState.candidatos.map((c) => c.playerId), [1, 2]);
    },
  );

  test(
    'a failed stale load() must not overwrite a newer, already-successful one',
    () async {
      final loadA = controller.load(query: 'a');
      final loadB = controller.load(query: 'b');

      service.calls[1].completer.complete(
        (candidatos: [_candidato(20)], total: 1),
      );
      await loadB;

      service.calls[0].completer.completeError(Exception('network failure'));
      await loadA;

      final finalState = controller.state;
      expect(finalState, isA<CambiosCandidatosLoaded>());
      expect((finalState as CambiosCandidatosLoaded).query, 'b');
    },
  );
}

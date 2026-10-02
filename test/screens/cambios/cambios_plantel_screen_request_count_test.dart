import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/models/cambios_plaza.dart';
import 'package:torneo_futbol_app/providers/cambios_providers.dart';
import 'package:torneo_futbol_app/providers/service_providers.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_plantel_screen.dart';
import 'package:torneo_futbol_app/services/api_service.dart';
import 'package:torneo_futbol_app/services/cambios_api_service.dart';
import 'package:torneo_futbol_app/services/cambios_plantel_controller.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';

/// Counts real HTTP requests "Mi Plantel" fires against the player endpoints
/// (`GET /jugadores?equipo_id=` for the roster, `GET /jugadores/{id}` per
/// fallback), using a REAL [ApiService] behind [http.runWithClient] + a
/// [MockClient] — the same technique `remote_data_service_test.dart` uses
/// for a service that calls the package-level `http.get` directly rather
/// than taking an injected client (`ApiService` has no such constructor
/// param, so this is the honest equivalent of the `httpClient:`-injection
/// convention `test/services/` otherwise uses).
///
/// This is the regression guard for the bug fixed in
/// `cambios_plantel_screen.dart`: branching the per-id fallback on
/// `AsyncValue.valueOrNull` instead of `.hasValue` treated "roster still
/// loading" as "player not on this roster", firing one `/jugadores/{id}`
/// request per titular/occupant the instant the screen opened — 9 requests
/// measured on a real device where 1 should do. A test that merely counts
/// calls through a hand-written fake `IApiService` would not have caught
/// this: the bug is specifically about WHEN the fallback provider is read
/// relative to the roster's async state, and a fake with an immediately
/// resolved future collapses that timing window away. Only a client whose
/// roster response can be held in flight (the [Completer] below) exercises
/// the window where the bug lived.

const _scope = (seasonId: 7, teamId: 1);
const _baseUrl = 'https://test.example.com/wp-json/entre-redes/v1';

CambiosPlaza _titular(int id, {required int plazaId}) => CambiosPlaza(
      plazaId: plazaId,
      titularPlayerId: id,
      titularNombre: 'Titular $id',
      esTitularElOcupante: true,
      cerrada: false,
    );

List<CambiosPlaza> _elevenTitulares() =>
    [for (var i = 0; i < 11; i++) _titular(100 + i, plazaId: i + 1)];

Map<String, dynamic> _jugadorJson(int id) => {
      'id': id,
      'title': {'rendered': 'Jugador $id'},
    };

/// Bundles the [MockClient] with the counters/gate a test needs, so each
/// `testWidgets` body gets a fresh, isolated set.
class _CountingClient {
  final Completer<void> rosterGate = Completer<void>();
  final List<int> rosterRequests = [];
  final List<int> perIdRequests = [];
  late final MockClient client;

  /// - answers `GET /jugadores?equipo_id=...` (the roster) only once
  ///   [rosterGate] completes, recording the request the instant it is MADE
  ///   (not when it resolves) — so a test can pump once, inspect counts
  ///   while the request is still in flight, and only then complete the
  ///   gate.
  /// - answers `GET /jugadores/{id}` (the per-id fallback) immediately,
  ///   recording every id requested in [perIdRequests] in call order.
  /// - throws on anything else, so an unexpected endpoint fails the test
  ///   loudly instead of silently returning garbage.
  _CountingClient({required List<int> rosterIds}) {
    client = MockClient((request) async {
      final uri = request.url;
      final idMatch = RegExp(r'/jugadores/(\d+)$').firstMatch(uri.path);

      if (idMatch != null) {
        final id = int.parse(idMatch.group(1)!);
        perIdRequests.add(id);
        return http.Response(jsonEncode(_jugadorJson(id)), 200,
            headers: {'content-type': 'application/json'});
      }

      if (uri.path.endsWith('/jugadores') && uri.queryParameters['equipo_id'] != null) {
        rosterRequests.add(1);
        await rosterGate.future;
        return http.Response(
          jsonEncode([for (final id in rosterIds) _jugadorJson(id)]),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      throw Exception('Unexpected request to $uri');
    });
  }
}

Future<void> _pumpMiPlantel(
  WidgetTester tester, {
  required List<CambiosPlaza> plazas,
}) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        apiServiceProvider.overrideWithValue(const ApiService(baseUrl: _baseUrl)),
        cambiosPlantelControllerProvider(_scope).overrideWith(
          (ref) => _StubPlantelController(CambiosPlantelLoaded(plazas: plazas)),
        ),
      ],
      child: MaterialApp(
        home: CambiosPlantelScreen(seasonId: _scope.seasonId, teamId: _scope.teamId),
      ),
    ),
  );
  await tester.pump();
}

void main() {
  testWidgets(
      'while the roster is in flight, zero per-id requests are issued '
      '(this is the exact bug: valueOrNull treats "loading" as "not found")',
      (tester) async {
    final titulares = _elevenTitulares();
    final counting = _CountingClient(
      rosterIds: [for (final p in titulares) p.titularPlayerId],
    );

    await http.runWithClient(() async {
      await _pumpMiPlantel(tester, plazas: titulares);

      // The roster request has gone out, but NOT resolved yet.
      expect(counting.rosterRequests, hasLength(1));
      expect(
        counting.perIdRequests,
        isEmpty,
        reason: 'the old `valueOrNull` code fired one /jugadores/{id} '
            'request per titular the instant the screen opened, before the '
            'roster had a chance to resolve — this must never happen again',
      );

      // Let the in-flight roster request resolve before the test ends —
      // otherwise the real `.timeout(Duration(seconds: 15))` Timer inside
      // ApiService.getJugadoresRaw is still pending when the widget tree is
      // torn down, which flutter_test flags as a leaked Timer.
      counting.rosterGate.complete();
      await tester.pump();
    }, () => counting.client);
  });

  testWidgets(
      'once the roster resolves covering every needed id, total requests = 1 '
      'and no per-id call ever happens', (tester) async {
    final titulares = _elevenTitulares();
    final counting = _CountingClient(
      rosterIds: [for (final p in titulares) p.titularPlayerId],
    );

    await http.runWithClient(() async {
      await _pumpMiPlantel(tester, plazas: titulares);
      counting.rosterGate.complete();
      // Two pumps: one to let the roster Future's then-callback run, one to
      // let the rebuilt widget tree settle on the resolved jugadoresById.
      await tester.pump();
      await tester.pump();

      expect(counting.rosterRequests, hasLength(1));
      expect(counting.perIdRequests, isEmpty);
    }, () => counting.client);
  });

  testWidgets(
      'an occupant absent from the roster produces exactly one per-id '
      'request, for that id only — the fallback still works', (tester) async {
    const titularId = 100;
    const ocupanteFueraDelPlantelId = 999; // not part of this team's roster
    final plazas = [
      CambiosPlaza(
        plazaId: 1,
        titularPlayerId: titularId,
        titularNombre: 'Titular Cien',
        ocupantePlayerId: ocupanteFueraDelPlantelId,
        ocupanteNombre: 'Ocupante Afuera',
        esTitularElOcupante: false,
        cerrada: false,
      ),
    ];
    final counting = _CountingClient(rosterIds: [titularId]);

    await http.runWithClient(() async {
      await _pumpMiPlantel(tester, plazas: plazas);
      counting.rosterGate.complete();
      await tester.pump();
      await tester.pump();

      expect(counting.rosterRequests, hasLength(1));
      expect(counting.perIdRequests, equals([ocupanteFueraDelPlantelId]));
    }, () => counting.client);
  });

  testWidgets(
      'a roster fetch error does NOT trigger one per-id request per needed '
      'id — it would just retry the same failing backend call', (tester) async {
    final titulares = _elevenTitulares();
    final rosterRequests = <int>[];
    final perIdRequests = <int>[];

    final client = MockClient((request) async {
      final uri = request.url;
      if (uri.path.endsWith('/jugadores') && uri.queryParameters['equipo_id'] != null) {
        rosterRequests.add(1);
        return http.Response('Internal Server Error', 500);
      }
      final idMatch = RegExp(r'/jugadores/(\d+)$').firstMatch(uri.path);
      if (idMatch != null) {
        perIdRequests.add(int.parse(idMatch.group(1)!));
        return http.Response(jsonEncode(_jugadorJson(perIdRequests.last)), 200,
            headers: {'content-type': 'application/json'});
      }
      throw Exception('Unexpected request to $uri');
    });

    await http.runWithClient(() async {
      await _pumpMiPlantel(tester, plazas: titulares);
      await tester.pump();
      await tester.pump();

      expect(rosterRequests, hasLength(1));
      expect(perIdRequests, isEmpty);
    }, () => client);
  });
}

class _StubPlantelController extends CambiosPlantelController {
  _StubPlantelController(CambiosPlantelState initialState)
      : super(
          CambiosApiService(
            baseUrl: 'https://nowhere.test/cambios',
            prodeApi: ProdeApiService(
              config: const ProdeAuthConfig(
                prodeApiBaseUrl: 'https://nowhere.test/prode',
                googleWebClientId: 'test',
                appleTeamId: 'TEST',
              ),
              authRepo: ProdeAuthRepository(),
            ),
          ),
        ) {
    state = initialState;
  }

  @override
  Future<void> load({required int seasonId, required int teamId}) async {}

  @override
  Future<void> refresh({required int seasonId, required int teamId}) async {}
}

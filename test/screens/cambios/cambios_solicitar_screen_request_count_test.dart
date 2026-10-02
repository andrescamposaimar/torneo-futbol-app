import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/models/cambios_fecha_abierta.dart';
import 'package:torneo_futbol_app/models/cambios_plaza.dart';
import 'package:torneo_futbol_app/models/cambios_solicitud.dart';
import 'package:torneo_futbol_app/providers/cambios_providers.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_solicitar_screen.dart';
import 'package:torneo_futbol_app/services/cambios_api_service.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';

/// Counts REAL HTTP requests the "Pedir cambio" candidate step fires against
/// `GET /cambios/plazas/candidatos`, one `seccion` at a time — the regression
/// guard for the laziness claim this slice's own PR makes: Lista de Espera
/// loads the instant the step opens (one request), Padrón Completo fetches
/// NOTHING until the captain opens it.
///
/// *** WHY THIS INJECTS [MockClient] DIRECTLY, UNLIKE
/// cambios_plantel_screen_request_count_test.dart'S `http.runWithClient` ***
/// That sibling file's own docblock explains it reaches for the
/// `http.runWithClient` + zone-override technique because `ApiService` (the
/// transport `cambiosEquipoRosterProvider` uses) calls the package-level
/// `http.get()` with no constructor seam to inject a client into. Candidatos
/// requests do NOT go through `ApiService` — they go through
/// `CambiosApiService` → `ProdeApiService`, and `ProdeApiService` already
/// takes an injectable `httpClient:` constructor parameter (see its own
/// class). Injecting a [MockClient] there directly is the HONEST equivalent
/// for a service that already has that seam — no zone trick needed, and
/// none of the "was the client constructed before or after the zone wrapped
/// anything" timing subtlety that technique carries.
///
/// A valid access token is seeded into a fake [FlutterSecureStoragePlatform]
/// (same technique as `test/services/prode_api_service_test.dart`) so
/// [ProdeApiService.request] never short-circuits on "no token" before ever
/// reaching the mock transport.
const _testConfig = ProdeAuthConfig(
  prodeApiBaseUrl: 'https://test.example.com/wp-json/entre-redes/v1/prode',
  googleWebClientId: 'test-google',
  appleTeamId: 'TEST_TEAM',
);

const _baseUrl = 'https://test.example.com/wp-json/entre-redes/v1/cambios';

const _seasonId = 7;
const _teamId = 1;
const _plazaId = 10;

final _plaza = CambiosPlaza(
  plazaId: _plazaId,
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

const _fechaAbierta = CambiosFechaAbierta(
  fechaId: 42,
  numeroEnTorneo: 3,
  torneo: 'Apertura',
  playDate: '2026-01-10',
  regresoAbierta: true,
  sustitucionAbierta: true,
);

void _seedAccessToken(Map<String, String> store) {
  FlutterSecureStoragePlatform.instance = TestFlutterSecureStoragePlatform(store);
  store['prode_tokens'] = json.encode({'access_token': 'test-access-token'});
}

/// Bundles the [MockClient] with the per-`seccion` counters a test needs.
/// Answers `GET /plazas/candidatos` with an empty `candidatos` list — these
/// tests are about HOW MANY requests fire, never about the payload — and
/// throws on any other path, so an unexpected endpoint fails loud instead of
/// silently returning garbage.
class _CountingClient {
  final List<String> listaEsperaRequests = [];
  final List<String> padronCompletoRequests = [];
  late final MockClient client;

  _CountingClient() {
    client = MockClient((request) async {
      final uri = request.url;

      if (uri.path.endsWith('/plazas/candidatos')) {
        final seccion = uri.queryParameters['seccion'];
        switch (seccion) {
          case 'lista_espera':
            listaEsperaRequests.add(uri.toString());
            break;
          case 'padron_completo':
            padronCompletoRequests.add(uri.toString());
            break;
          default:
            throw Exception('Unexpected (or missing) seccion in $uri');
        }

        return http.Response(
          json.encode({'candidatos': <Map<String, dynamic>>[]}),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      throw Exception('Unexpected request to $uri');
    });
  }
}

Future<void> _pumpSolicitarScreen(
  WidgetTester tester, {
  required CambiosApiService cambiosApiService,
}) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        cambiosApiServiceProvider.overrideWithValue(cambiosApiService),
        cambiosFechaAbiertaProvider(_seasonId).overrideWith((ref) => Future.value(_fechaAbierta)),
      ],
      child: MaterialApp(
        home: CambiosSolicitarScreen(
          seasonId: _seasonId,
          teamId: _teamId,
          plaza: _plaza,
          tipo: CambiosSolicitudTipo.sustitucion,
        ),
      ),
    ),
  );
  // Lets both the fecha Future and the real candidatos HTTP calls resolve.
  await tester.pump();
  await tester.pump();
}

void main() {
  late _CountingClient counting;
  late CambiosApiService cambiosApiService;

  setUp(() {
    final store = <String, String>{};
    _seedAccessToken(store);

    counting = _CountingClient();
    cambiosApiService = CambiosApiService(
      baseUrl: _baseUrl,
      prodeApi: ProdeApiService(
        config: _testConfig,
        authRepo: ProdeAuthRepository(),
        httpClient: counting.client,
      ),
    );
  });

  testWidgets(
      'opening the candidate step issues exactly one request (Lista de Espera) '
      'and zero for Padrón Completo', (tester) async {
    await _pumpSolicitarScreen(tester, cambiosApiService: cambiosApiService);

    expect(counting.listaEsperaRequests, hasLength(1));
    expect(
      counting.padronCompletoRequests,
      isEmpty,
      reason: 'Padrón Completo must stay idle — fetching nothing — until '
          'the captain actually opens it',
    );
  });

  testWidgets(
      'opening Padrón Completo issues exactly one more request, for that '
      'section only', (tester) async {
    await _pumpSolicitarScreen(tester, cambiosApiService: cambiosApiService);

    await tester.tap(find.text('Padrón Completo'));
    await tester.pump();
    await tester.pump();

    expect(counting.listaEsperaRequests, hasLength(1),
        reason: 'opening Padrón Completo must not re-fetch Lista de Espera');
    expect(counting.padronCompletoRequests, hasLength(1));
  });

  testWidgets(
      'switching back to Lista de Espera issues no further request — it is '
      'already loaded', (tester) async {
    await _pumpSolicitarScreen(tester, cambiosApiService: cambiosApiService);

    await tester.tap(find.text('Padrón Completo'));
    await tester.pump();
    await tester.pump();

    await tester.tap(find.text('Lista de Espera'));
    await tester.pump();
    await tester.pump();

    // Switching back and forth once more, for good measure — the Idle check
    // is what buys this, so prove it buys it more than once.
    await tester.tap(find.text('Padrón Completo'));
    await tester.pump();
    await tester.tap(find.text('Lista de Espera'));
    await tester.pump();

    expect(counting.listaEsperaRequests, hasLength(1),
        reason: 'Lista de Espera was already loaded — switching back to it '
            'must never re-fetch');
    expect(counting.padronCompletoRequests, hasLength(1),
        reason: 'Padrón Completo was already loaded by the earlier open — '
            'switching away and back must never re-fetch it either');
  });
}

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/models/cambios_solicitud.dart';
import 'package:torneo_futbol_app/services/cambios_api_service.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';

const _testProdeConfig = ProdeAuthConfig(
  prodeApiBaseUrl: 'https://test.example.com/wp-json/entre-redes/v1/prode',
  googleWebClientId: 'test-google',
  appleTeamId: 'TEST_TEAM',
);

const _cambiosBaseUrl = 'https://test.example.com/wp-json/entre-redes/v1/cambios';

void _setUpFakeStorage(Map<String, String> store) {
  FlutterSecureStoragePlatform.instance = TestFlutterSecureStoragePlatform(store);
}

/// Builds a [CambiosApiService] wired to [client], with [repo] pre-loaded
/// with a valid access token so [ProdeApiService.request] attaches a Bearer
/// header and actually calls [client] instead of short-circuiting on "no
/// token available".
CambiosApiService _makeService(ProdeAuthRepository repo, http.Client client) {
  final prodeApi = ProdeApiService(
    config: _testProdeConfig,
    authRepo: repo,
    httpClient: client,
  );
  return CambiosApiService(baseUrl: _cambiosBaseUrl, prodeApi: prodeApi);
}

Future<ProdeAuthRepository> _repoWithAccessToken({String? refreshToken}) async {
  final repo = ProdeAuthRepository();
  await repo.write(
    accessToken: 'valid-access-token',
    refreshToken: refreshToken ?? 'valid-refresh-token',
    sessionVersion: '1',
    tenantId: 'marianista',
  );
  return repo;
}

http.Response _jsonResponse(Object? body, int statusCode) {
  return http.Response(
    json.encode(body),
    statusCode,
    headers: {'content-type': 'application/json'},
  );
}

void main() {
  late Map<String, String> store;

  setUp(() {
    store = {};
    _setUpFakeStorage(store);
  });

  group('CambiosApiService — happy path', () {
    test('fetchMisEquipos() parses the envelope on 200', () async {
      final repo = await _repoWithAccessToken();
      final service = _makeService(
        repo,
        MockClient((request) async {
          expect(request.url.path, endsWith('/cambios/mis-equipos'));
          expect(request.headers['Authorization'], 'Bearer valid-access-token');
          return _jsonResponse({
            'season_id': 7,
            'player_id': 42,
            'teams': [
              {'team_id': 1, 'nombre': 'Sub 13 A'},
            ],
          }, 200);
        }),
      );

      final result = await service.fetchMisEquipos();

      expect(result.seasonId, 7);
      expect(result.playerId, 42);
      expect(result.teams, hasLength(1));
      expect(result.teams.single.teamId, 1);
      expect(result.teams.single.nombre, 'Sub 13 A');
    });

    test('fetchPlazas() parses the plazas list and passes season/team query params', () async {
      final repo = await _repoWithAccessToken();
      final service = _makeService(
        repo,
        MockClient((request) async {
          expect(request.url.queryParameters['season_id'], '7');
          expect(request.url.queryParameters['team_id'], '1');
          return _jsonResponse({
            'plazas': [
              {
                'plaza_id': 10,
                'tipo': 'campo',
                'titular_player_id': 100,
                'titular_nombre': 'Juan Pérez',
                'ocupante_player_id': 200,
                'ocupante_nombre': 'Pedro Gómez',
                'es_titular_el_ocupante': false,
                'cerrada': false,
                'fechas_faltantes_liberacion': 2,
                'fechas_faltantes_liberacion_indeterminado': false,
              },
            ],
          }, 200);
        }),
      );

      final plazas = await service.fetchPlazas(seasonId: 7, teamId: 1);

      expect(plazas, hasLength(1));
      expect(plazas.single.plazaId, 10);
      expect(plazas.single.tipoLabel, 'Campo');
      expect(plazas.single.regresoElegible, isFalse); // 2 fechas faltantes
    });

    test('fetchCandidatos() parses the candidatos list', () async {
      final repo = await _repoWithAccessToken();
      final service = _makeService(
        repo,
        MockClient((request) async {
          expect(request.url.queryParameters['plaza_id'], '10');
          expect(request.url.queryParameters['search'], 'gom');
          return _jsonResponse({
            'candidatos': [
              {
                'player_id': 200,
                'nombre': 'Pedro Gómez',
                'es_padre': false,
                'puntaje': 3.5,
                'viable': true,
                'motivo': null,
              },
            ],
          }, 200);
        }),
      );

      final candidatos = await service.fetchCandidatos(
        seasonId: 7,
        teamId: 1,
        plazaId: 10,
        search: 'gom',
      );

      expect(candidatos, hasLength(1));
      expect(candidatos.single.nombre, 'Pedro Gómez');
      expect(candidatos.single.puntaje, 3.5);
      expect(candidatos.single.viable, isTrue);
    });

    test('fetchSolicitudes() parses the solicitudes list including a dictamen', () async {
      final repo = await _repoWithAccessToken();
      final service = _makeService(
        repo,
        MockClient((_) async => _jsonResponse({
              'solicitudes': [
                {
                  'id': 5,
                  'plaza_id': 10,
                  'tipo': 'sustitucion',
                  'entrante_player_id': 200,
                  'fecha_id': 3,
                  'estado': 'rechazada',
                  'solicitada_at': '2026-03-01 10:00:00',
                  'resuelta_at': '2026-03-02 09:00:00',
                  'nota': 'No corresponde',
                  'dictamen': {
                    'procede': false,
                    'motivos': [
                      {
                        'codigo': 'puntaje_excede_techo',
                        'mensaje': 'internal message',
                        'datos': {},
                      },
                    ],
                  },
                },
              ],
            }, 200)),
      );

      final solicitudes = await service.fetchSolicitudes(seasonId: 7, teamId: 1);

      expect(solicitudes, hasLength(1));
      final s = solicitudes.single;
      expect(s.id, 5);
      expect(s.tipo, CambiosSolicitudTipo.sustitucion);
      expect(s.estado, CambiosSolicitudEstado.rechazada);
      expect(s.dictamen.procede, isFalse);
      expect(s.dictamen.motivos.single.codigo, 'puntaje_excede_techo');
    });

    test('crearSolicitud() POSTs the expected body and parses the response', () async {
      final repo = await _repoWithAccessToken();
      Map<String, dynamic>? sentBody;
      final service = _makeService(
        repo,
        MockClient((request) async {
          sentBody = json.decode(request.body) as Map<String, dynamic>;
          return _jsonResponse({
            'id': 99,
            'estado': 'pendiente',
            'dictamen': {'procede': true, 'motivos': []},
          }, 200);
        }),
      );

      final result = await service.crearSolicitud(
        seasonId: 7,
        teamId: 1,
        plazaId: 10,
        tipo: CambiosSolicitudTipo.sustitucion,
        fechaId: 3,
        entrantePlayerId: 200,
      );

      expect(sentBody, isNotNull);
      expect(sentBody!['tipo'], 'sustitucion');
      expect(sentBody!['entrante_player_id'], 200);
      expect(sentBody!['fecha_id'], 3);
      expect(result.id, 99);
      expect(result.estado, CambiosSolicitudEstado.pendiente);
      expect(result.dictamen.procede, isTrue);
    });

    test('crearSolicitud() for a regreso omits entrante_player_id from the body', () async {
      final repo = await _repoWithAccessToken();
      Map<String, dynamic>? sentBody;
      final service = _makeService(
        repo,
        MockClient((request) async {
          sentBody = json.decode(request.body) as Map<String, dynamic>;
          return _jsonResponse({
            'id': 100,
            'estado': 'pendiente',
            'dictamen': {'procede': true, 'motivos': []},
          }, 200);
        }),
      );

      await service.crearSolicitud(
        seasonId: 7,
        teamId: 1,
        plazaId: 10,
        tipo: CambiosSolicitudTipo.regreso,
        fechaId: 3,
      );

      expect(sentBody!['tipo'], 'regreso');
      expect(sentBody!.containsKey('entrante_player_id'), isFalse);
    });
  });

  group('CambiosApiService — 401 handling', () {
    test('a 401 whose refresh also fails throws ProdeAuthRequired', () async {
      // ProdeApiService.request() sees the 401, attempts a single refresh
      // with the stored refresh token, and — since the refresh call ALSO
      // comes back 401 here — clears storage and throws.
      final repo = await _repoWithAccessToken();
      final service = _makeService(
        repo,
        MockClient((request) async {
          if (request.url.path.endsWith('/auth/refresh')) {
            return _jsonResponse({'code': 'refresh_token_invalid'}, 401);
          }
          return _jsonResponse({'code': 'token_expired'}, 401);
        }),
      );

      await expectLater(
        service.fetchPlazas(seasonId: 7, teamId: 1),
        throwsA(isA<ProdeAuthRequired>()),
      );
    });

    test('a generic 403 no_autorizado throws CambiosApiException', () async {
      final repo = await _repoWithAccessToken();
      final service = _makeService(
        repo,
        MockClient((_) async => _jsonResponse({
              'code': 'no_autorizado',
              'message': 'No estás autorizado.',
            }, 403)),
      );

      await expectLater(
        service.fetchPlazas(seasonId: 7, teamId: 1),
        throwsA(isA<CambiosApiException>()
            .having((e) => e.statusCode, 'statusCode', 403)
            .having((e) => e.code, 'code', 'no_autorizado')),
      );
    });
  });

  group('CambiosApiService — malformed payload', () {
    test('a 200 whose body is not the expected shape throws CambiosMalformedResponseException', () async {
      final repo = await _repoWithAccessToken();
      final service = _makeService(
        repo,
        // Valid JSON ("null"), but not the {"plazas": [...]} envelope this
        // endpoint requires.
        MockClient((_) async => http.Response('null', 200,
            headers: {'content-type': 'application/json'})),
      );

      await expectLater(
        service.fetchPlazas(seasonId: 7, teamId: 1),
        throwsA(isA<CambiosMalformedResponseException>()),
      );
    });
  });
}

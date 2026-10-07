import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/models/cambios_fecha_abierta.dart';
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
                'titular_player_id': 100,
                'titular_nombre': 'Juan Pérez',
                'ocupante_player_id': 200,
                'ocupante_nombre': 'Pedro Gómez',
                'es_titular_el_ocupante': false,
                'cerrada': false,
                'es_arco': true,
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
      expect(plazas.single.titularNombre, 'Juan Pérez');
      expect(plazas.single.regresoElegible, isFalse); // 2 fechas faltantes
      expect(plazas.single.esArco, isTrue);
    });

    test('fetchCandidatos() parses the candidatos list, foto_url, and the X-WP-Total header',
        () async {
      final repo = await _repoWithAccessToken();
      final service = _makeService(
        repo,
        MockClient((request) async {
          expect(request.url.queryParameters['plaza_id'], '10');
          expect(request.url.queryParameters['search'], 'gom');
          expect(request.url.queryParameters['page'], '2');
          expect(request.url.queryParameters['per_page'], '20');
          expect(request.url.queryParametersAll['puntajes[]'], ['2.5', '4']);
          return http.Response(
            json.encode({
              'candidatos': [
                {
                  'player_id': 200,
                  'nombre': 'Pedro Gómez',
                  'es_padre': false,
                  'puntaje': 3.5,
                  'viable': true,
                  'motivo': null,
                  'foto_url': 'https://entreredespadres.com.ar/foto-200.jpg',
                },
              ],
            }),
            200,
            headers: {'content-type': 'application/json', 'x-wp-total': '37'},
          );
        }),
      );

      final pagina = await service.fetchCandidatos(
        seasonId: 7,
        teamId: 1,
        plazaId: 10,
        search: 'gom',
        puntajes: const [2.5, 4],
        page: 2,
      );

      expect(pagina.candidatos, hasLength(1));
      expect(pagina.candidatos.single.nombre, 'Pedro Gómez');
      expect(pagina.candidatos.single.puntaje, 3.5);
      expect(pagina.candidatos.single.viable, isTrue);
      expect(pagina.candidatos.single.fotoUrl, 'https://entreredespadres.com.ar/foto-200.jpg');
      expect(pagina.total, 37);
    });

    test('fetchCandidatos() leaves total null when X-WP-Total is missing, even for a full page',
        () async {
      // THE dangerous case: a FULL page (20 of 20, the default perPage) with
      // no header. Falling back to `candidatos.length` here would fabricate
      // `total: 20`, which `CambiosCandidatosController.hasMoreFor()` reads
      // as "that's the whole population" — silently truncating infinite
      // scroll at page 1. See this method's own docblock and
      // [CambiosCandidatosPagina]'s for why the fallback was removed.
      final repo = await _repoWithAccessToken();
      final service = _makeService(
        repo,
        MockClient((request) async => _jsonResponse({
              'candidatos': List.generate(
                20,
                (i) => {
                  'player_id': 200 + i,
                  'nombre': 'Jugador #${200 + i}',
                  'es_padre': false,
                  'puntaje': 3.5,
                  'viable': true,
                  'motivo': null,
                },
              ),
            }, 200)),
      );

      final pagina = await service.fetchCandidatos(seasonId: 7, teamId: 1, plazaId: 10);

      expect(pagina.total, isNull);
      expect(pagina.candidatos, hasLength(20),
          reason: 'A missing header must never truncate the page itself.');
    });

    test('fetchCandidatos() parses a null foto_url as null', () async {
      final repo = await _repoWithAccessToken();
      final service = _makeService(
        repo,
        MockClient((request) async => _jsonResponse({
              'candidatos': [
                {
                  'player_id': 201,
                  'nombre': 'Sin Foto',
                  'es_padre': false,
                  'puntaje': 2.0,
                  'viable': true,
                  'motivo': null,
                  'foto_url': null,
                },
              ],
            }, 200)),
      );

      final pagina = await service.fetchCandidatos(seasonId: 7, teamId: 1, plazaId: 10);

      expect(pagina.candidatos.single.fotoUrl, isNull);
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

    test('crearReasignacionArquero() POSTs plaza_id=the GOAL plaza, tipo=reasignacion_arquero, '
        'both entrante ids, and NEVER a vacated-plaza field', () async {
      final repo = await _repoWithAccessToken();
      Map<String, dynamic>? sentBody;
      final service = _makeService(
        repo,
        MockClient((request) async {
          sentBody = json.decode(request.body) as Map<String, dynamic>;
          return _jsonResponse({
            'id': 55,
            'estado': 'pendiente',
            'dictamen': {'procede': true, 'motivos': []},
          }, 200);
        }),
      );

      final result = await service.crearReasignacionArquero(
        seasonId: 7,
        teamId: 1,
        plazaArcoId: 30,
        fechaId: 3,
        entrantePlayerId: 101,
        entranteCampoPlayerId: 900,
      );

      expect(sentBody, isNotNull);
      expect(sentBody!['plaza_id'], 30);
      expect(sentBody!['tipo'], 'reasignacion_arquero');
      expect(sentBody!['fecha_id'], 3);
      expect(sentBody!['entrante_player_id'], 101);
      expect(sentBody!['entrante_campo_player_id'], 900);
      // The vacated field plaza is NEVER a body field — the backend derives
      // it from entrante_player_id's own vigent occupation (see this
      // method's own docblock).
      expect(sentBody!.containsKey('plaza_campo_id'), isFalse);
      expect(sentBody!.keys, hasLength(7));
      expect(result.id, 55);
      expect(result.estado, CambiosSolicitudEstado.pendiente);
      expect(result.dictamen.procede, isTrue);
    });
  });

  group('CambiosApiService — fetchFechaAbierta', () {
    test('parses the fecha envelope, including ventanas, on 200', () async {
      final repo = await _repoWithAccessToken();
      final service = _makeService(
        repo,
        MockClient((request) async {
          expect(request.url.path, endsWith('/cambios/fecha-abierta'));
          expect(request.url.queryParameters['season_id'], '7');
          return _jsonResponse({
            'fecha': {
              'fecha_id': 42,
              'numero_en_torneo': 3,
              'torneo': 'Apertura',
              'play_date': '2026-01-10',
              'plazos_utc': {
                'apertura_solicitudes': '2026-01-04 03:00:00',
                'cierre_regresos': '2026-01-07 02:59:59',
                'cierre_solicitudes': '2026-01-09 02:59:59',
                'publicacion': '2026-01-09 03:00:00',
              },
              'ventanas': {'regreso': 'cerrada', 'sustitucion': 'abierta'},
            },
          }, 200);
        }),
      );

      final fecha = await service.fetchFechaAbierta(seasonId: 7);

      expect(fecha, isNotNull);
      expect(fecha!.fechaId, 42);
      expect(fecha.numeroEnTorneo, 3);
      expect(fecha.torneo, 'Apertura');
      expect(fecha.playDate, '2026-01-10');
      expect(fecha.regresoFase, CambiosVentanaFase.cerrada);
      expect(fecha.sustitucionFase, CambiosVentanaFase.abierta);
      expect(
        fecha.aperturaSolicitudesUtc,
        DateTime.utc(2026, 1, 4, 3, 0, 0),
      );
    });

    test('returns null when the backend answers {"fecha": null}', () async {
      final repo = await _repoWithAccessToken();
      final service = _makeService(
        repo,
        MockClient((_) async => _jsonResponse({'fecha': null}, 200)),
      );

      final fecha = await service.fetchFechaAbierta(seasonId: 7);

      expect(fecha, isNull);
    });

    test('a 401 token_expired refreshes silently and retries once', () async {
      final repo = await _repoWithAccessToken();
      var refreshCalled = false;
      final service = _makeService(
        repo,
        MockClient((request) async {
          if (request.url.path.endsWith('/auth/refresh')) {
            refreshCalled = true;
            return _jsonResponse({
              'access_token': 'new-access-token',
              'refresh_token': 'new-refresh-token',
              'user': {
                'user_id': 1,
                'player_id': 2,
                'name': 'Test',
                'session_version': 2,
              },
            }, 200);
          }
          if (request.headers['Authorization'] == 'Bearer new-access-token') {
            return _jsonResponse({'fecha': null}, 200);
          }
          return _jsonResponse({'code': 'token_expired'}, 401);
        }),
      );

      final fecha = await service.fetchFechaAbierta(seasonId: 7);

      expect(refreshCalled, isTrue);
      expect(fecha, isNull);
    });

    test('a 401 session_revoked throws ProdeAuthRequired', () async {
      final repo = await _repoWithAccessToken();
      final service = _makeService(
        repo,
        MockClient((_) async => _jsonResponse({'code': 'session_revoked'}, 401)),
      );

      await expectLater(
        service.fetchFechaAbierta(seasonId: 7),
        throwsA(isA<ProdeAuthRequired>()
            .having((e) => e.code, 'code', 'session_revoked')),
      );
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

    // Backend contract (Rest\HandlesCapitanAuthorization): a 403 is now ONLY
    // `no_capitan` — the caller is authenticated fine, just not the captain
    // of this team/season. ProdeApiService.request()'s 401-interceptor never
    // touches a 403, so this reaches CambiosApiService's own error mapping
    // unchanged.
    test('a 403 no_capitan throws CambiosApiException', () async {
      final repo = await _repoWithAccessToken();
      final service = _makeService(
        repo,
        MockClient((_) async => _jsonResponse({
              'code': 'no_capitan',
              'message': 'No estás autorizado.',
            }, 403)),
      );

      await expectLater(
        service.fetchPlazas(seasonId: 7, teamId: 1),
        throwsA(isA<CambiosApiException>()
            .having((e) => e.statusCode, 'statusCode', 403)
            .having((e) => e.code, 'code', 'no_capitan')),
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

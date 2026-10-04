import 'dart:convert';

import 'package:http/http.dart' as http;

import '../models/cambios_candidato.dart';
import '../models/cambios_fecha_abierta.dart';
import '../models/cambios_mis_equipos.dart';
import '../models/cambios_plaza.dart';
import '../models/cambios_solicitud.dart';
import 'prode_api_service.dart';

/// Thrown by any [CambiosApiService] method on a non-200 response the
/// transport did not already turn into a more specific exception.
///
/// [code] is the machine-readable `code` from the response body, extracted by
/// this class's own private `_extractErrorCode` (a deliberate duplicate of
/// [ProdeApiService.extractErrorCode]'s logic — that method is
/// `@visibleForTesting` and scoped to its own library plus tests, so it
/// cannot be called from here; see `_extractErrorCode`'s own docblock) —
/// notably `'no_capitan'` for the 403
/// every captain-facing Cambios endpoint returns when the caller is
/// authenticated fine but is not the captain of this team/season. A token or
/// session failure never reaches here as a 403 any more — see
/// `Rest\HandlesCapitanAuthorization`'s docblock on the backend: those are
/// now 401 (`token_expired`, `token_invalid`, `session_revoked`), which
/// [ProdeApiService.request] already intercepts before a response ever
/// reaches this class (silent refresh on `token_expired`, [ProdeAuthRequired]
/// otherwise). Screens must still render a friendly, generic message for a
/// 403 `no_capitan`, never the raw code or the server's `message`.
class CambiosApiException implements Exception {
  final int statusCode;
  final String code;

  const CambiosApiException({required this.statusCode, this.code = 'unknown'});

  @override
  String toString() => 'CambiosApiException($statusCode, $code)';
}

/// Thrown when a 200 response body could not be parsed into the expected
/// shape (missing/malformed required fields at the envelope level).
class CambiosMalformedResponseException implements Exception {
  const CambiosMalformedResponseException();

  @override
  String toString() => 'CambiosMalformedResponseException';
}

/// HTTP transport for the captain-facing "Cambios" (player substitution)
/// REST API.
///
/// *** WHY THIS DOES NOT IMPLEMENT ITS OWN BEARER/REFRESH LOGIC ***
/// Every Cambios endpoint validates the SAME Prode access token
/// (`Capitania\CapitanAuthorizer` wraps the same `TokenVerifier` /
/// `ProdeSessionGateway` the Prode feature itself uses) — so this service
/// routes every call through the injected [ProdeApiService.request], which
/// already attaches the bearer, does single-flight refresh on a 401
/// `token_expired`, and clears tokens + throws [ProdeAuthRequired] on a
/// `session_revoked` or missing token. A second copy of that logic here
/// would inevitably drift from the one in [ProdeApiService] — see this
/// slice's own task brief.
///
/// *** THE 401-TRIGGERED REFRESH NOW ACTUALLY FIRES FOR THIS API ***
/// An earlier version of `Rest\HandlesCapitanAuthorization` (the backend
/// trait every Cambios controller uses) mapped EVERY authorization failure —
/// including a merely EXPIRED token — to a generic 403 `no_autorizado`,
/// never a 401. That meant [ProdeApiService.request]'s 401-interceptor
/// (which is what triggers the silent refresh) never engaged for these
/// endpoints: an expired-but-not-yet-refreshed token surfaced here as an
/// opaque, unrecoverable 403. That backend/client mismatch is fixed: the
/// trait now answers 401 `token_expired` / `token_invalid` / `session_revoked`
/// for a token or session failure, and reserves 403 for `no_capitan` alone
/// (authenticated fine, just not captain of this team/season) — exactly the
/// codes [ProdeApiService.request] already knows how to act on. Flagged
/// here so a future reader doesn't reintroduce a second, cambios-specific
/// 403-triggered refresh in THIS class: the backend contract now matches
/// [ProdeApiService]'s existing 401 handling, so there is nothing left for
/// this service to do differently.
class CambiosApiService {
  final String _baseUrl;
  final ProdeApiService _prodeApi;

  /// [baseUrl] has no trailing slash, e.g.
  /// `'https://entreredespadres.com.ar/wp-json/entre-redes/v1/cambios'`.
  CambiosApiService({required String baseUrl, required ProdeApiService prodeApi})
      : _baseUrl = baseUrl,
        _prodeApi = prodeApi;

  // ---------------------------------------------------------------------------
  // GET /mis-equipos
  // ---------------------------------------------------------------------------

  Future<CambiosMisEquipos> fetchMisEquipos() async {
    final req = http.Request('GET', Uri.parse('$_baseUrl/mis-equipos'))
      ..headers['Accept'] = 'application/json';
    final response = await _prodeApi.request(req).timeout(const Duration(seconds: 15));

    if (response.statusCode != 200) {
      throw _errorFor(response);
    }

    try {
      return CambiosMisEquipos.fromJson(_decodeBody(response));
    } catch (_) {
      throw const CambiosMalformedResponseException();
    }
  }

  // ---------------------------------------------------------------------------
  // GET /fecha-abierta
  // ---------------------------------------------------------------------------

  /// Fetches the season's currently open fecha — the one `POST /solicitudes`
  /// requires a `fecha_id` for, and the ONE route that exposes it (see
  /// `Calendario\FechaRepository::listBySeason()` on the backend, wired to
  /// no other route).
  ///
  /// Returns `null` when the backend answers `{"fecha": null}` — the season
  /// has no unresolved fecha right now. That is a normal state, never an
  /// exception: the "Pedir cambio" screen renders it as a sentence, exactly
  /// like the honest gap banner it already showed before this endpoint
  /// existed.
  Future<CambiosFechaAbierta?> fetchFechaAbierta({required int seasonId}) async {
    final uri = Uri.parse('$_baseUrl/fecha-abierta').replace(queryParameters: {
      'season_id': '$seasonId',
    });
    final req = http.Request('GET', uri)..headers['Accept'] = 'application/json';
    final response = await _prodeApi.request(req).timeout(const Duration(seconds: 15));

    if (response.statusCode != 200) {
      throw _errorFor(response);
    }

    final body = _decodeBody(response);
    final raw = body['fecha'];

    if (raw == null) return null;

    if (raw is! Map) {
      throw const CambiosMalformedResponseException();
    }

    try {
      return CambiosFechaAbierta.fromJson(raw.cast<String, dynamic>());
    } catch (_) {
      throw const CambiosMalformedResponseException();
    }
  }

  // ---------------------------------------------------------------------------
  // GET /plazas
  // ---------------------------------------------------------------------------

  Future<List<CambiosPlaza>> fetchPlazas({
    required int seasonId,
    required int teamId,
  }) async {
    final uri = Uri.parse('$_baseUrl/plazas').replace(queryParameters: {
      'season_id': '$seasonId',
      'team_id': '$teamId',
    });
    final req = http.Request('GET', uri)..headers['Accept'] = 'application/json';
    final response = await _prodeApi.request(req).timeout(const Duration(seconds: 15));

    if (response.statusCode != 200) {
      throw _errorFor(response);
    }

    try {
      final body = _decodeBody(response);
      final raw = body['plazas'];
      if (raw is! List) throw const CambiosMalformedResponseException();
      return raw
          .whereType<Map>()
          .map((e) => CambiosPlaza.fromJson(e.cast<String, dynamic>()))
          .toList(growable: false);
    } on CambiosMalformedResponseException {
      rethrow;
    } catch (_) {
      throw const CambiosMalformedResponseException();
    }
  }

  // ---------------------------------------------------------------------------
  // GET /plazas/candidatos
  // ---------------------------------------------------------------------------

  /// [seccion] selects ONE of the two widened candidate pools (see
  /// [CambiosCandidatosSeccion]'s own docblock) via `?seccion=`. `null`
  /// omits the param entirely, which keeps the backend's pre-existing
  /// season-registered pool — kept for backward compatibility, but this
  /// app's own "Pedir cambio" screen always passes one of the two values.
  ///
  /// [page]/[perPage] drive the endpoint's OWN pagination — see
  /// `Rest\PlazasController::listarCandidatos()`'s own docblock on the
  /// backend, "PAGINATION". [puntajes] is sent as a repeatable
  /// `puntajes[]=<decimal>` param (e.g. `puntajes[]=3&puntajes[]=4.5`),
  /// matching the puntaje chips' own values — see
  /// [CambiosCandidatosController] for how a page's worth of candidatos is
  /// combined with the ones already loaded.
  ///
  /// Returns a [CambiosCandidatosPagina] — see that typedef's own docblock
  /// for exactly what `total` means and where it comes from (the
  /// `X-WP-Total` response header, read the SAME way
  /// [ApiService.getJugadoresRaw] already reads it, never invented
  /// differently here).
  Future<CambiosCandidatosPagina> fetchCandidatos({
    required int seasonId,
    required int teamId,
    required int plazaId,
    CambiosCandidatosSeccion? seccion,
    String? search,
    List<double> puntajes = const [],
    int page = 1,
    int perPage = 20,
  }) async {
    final uri = Uri.parse('$_baseUrl/plazas/candidatos').replace(queryParameters: {
      'season_id': '$seasonId',
      'team_id': '$teamId',
      'plaza_id': '$plazaId',
      if (seccion != null) 'seccion': seccion.toWire(),
      if (search != null && search.isNotEmpty) 'search': search,
      if (puntajes.isNotEmpty) 'puntajes[]': puntajes.map(_formatPuntaje).toList(),
      'page': '$page',
      'per_page': '$perPage',
    });
    final req = http.Request('GET', uri)..headers['Accept'] = 'application/json';
    final response = await _prodeApi.request(req).timeout(const Duration(seconds: 15));

    if (response.statusCode != 200) {
      throw _errorFor(response);
    }

    try {
      final body = _decodeBody(response);
      final raw = body['candidatos'];
      if (raw is! List) throw const CambiosMalformedResponseException();
      final candidatos = raw
          .whereType<Map>()
          .map((e) => CambiosCandidato.fromJson(e.cast<String, dynamic>()))
          .toList(growable: false);

      final totalHeader = response.headers['x-wp-total'];
      final total = totalHeader != null ? int.tryParse(totalHeader) : null;

      return (candidatos: candidatos, total: total ?? candidatos.length);
    } on CambiosMalformedResponseException {
      rethrow;
    } catch (_) {
      throw const CambiosMalformedResponseException();
    }
  }

  /// `3` for `3.0`, `3.5` for `3.5` — the same literal shape
  /// `_PuntajeChips` already renders, so the query string a captain's chip
  /// selection produces reads the same as what the screen shows.
  static String _formatPuntaje(double valor) =>
      valor == valor.truncateToDouble() ? valor.toInt().toString() : valor.toString();

  // ---------------------------------------------------------------------------
  // GET /solicitudes
  // ---------------------------------------------------------------------------

  Future<List<CambiosSolicitud>> fetchSolicitudes({
    required int seasonId,
    required int teamId,
  }) async {
    final uri = Uri.parse('$_baseUrl/solicitudes').replace(queryParameters: {
      'season_id': '$seasonId',
      'team_id': '$teamId',
    });
    final req = http.Request('GET', uri)..headers['Accept'] = 'application/json';
    final response = await _prodeApi.request(req).timeout(const Duration(seconds: 15));

    if (response.statusCode != 200) {
      throw _errorFor(response);
    }

    try {
      final body = _decodeBody(response);
      final raw = body['solicitudes'];
      if (raw is! List) throw const CambiosMalformedResponseException();
      return raw
          .whereType<Map>()
          .map((e) => CambiosSolicitud.fromJson(e.cast<String, dynamic>()))
          .toList(growable: false);
    } on CambiosMalformedResponseException {
      rethrow;
    } catch (_) {
      throw const CambiosMalformedResponseException();
    }
  }

  // ---------------------------------------------------------------------------
  // POST /solicitudes
  // ---------------------------------------------------------------------------

  /// Creates a solicitud. [tipo] is `sustitucion` or `regreso`;
  /// [entrantePlayerId] is required for `sustitucion` and ignored (omitted
  /// from the body) for `regreso` — see `Dictamen\SolicitudDeCambio`'s own
  /// docblock: who returns is never a choice this request makes.
  ///
  /// Returns normally for EVERY dictamen, favorable or not — a rejecting
  /// dictamen is a successful 200 (see `SolicitudesController`'s docblock).
  /// Only a genuine transport/server error throws.
  Future<CambiosNuevaSolicitud> crearSolicitud({
    required int seasonId,
    required int teamId,
    required int plazaId,
    required CambiosSolicitudTipo tipo,
    required int fechaId,
    int? entrantePlayerId,
  }) async {
    final req = http.Request('POST', Uri.parse('$_baseUrl/solicitudes'))
      ..headers['Content-Type'] = 'application/json'
      ..headers['Accept'] = 'application/json'
      ..body = json.encode({
        'season_id': seasonId,
        'team_id': teamId,
        'plaza_id': plazaId,
        'tipo': tipo.toWire(),
        'fecha_id': fechaId,
        if (tipo == CambiosSolicitudTipo.sustitucion && entrantePlayerId != null)
          'entrante_player_id': entrantePlayerId,
      });

    final response = await _prodeApi.request(req).timeout(const Duration(seconds: 15));

    if (response.statusCode != 200) {
      throw _errorFor(response);
    }

    try {
      return CambiosNuevaSolicitud.fromJson(_decodeBody(response));
    } catch (_) {
      throw const CambiosMalformedResponseException();
    }
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  CambiosApiException _errorFor(http.Response response) {
    return CambiosApiException(
      statusCode: response.statusCode,
      code: _extractErrorCode(_decodeBody(response)),
    );
  }

  Map<String, dynamic> _decodeBody(http.Response response) {
    try {
      final decoded = json.decode(response.body);
      if (decoded is Map<String, dynamic>) return decoded;
      return {};
    } on FormatException {
      return {};
    }
  }

  /// Extracts the machine-readable error `code` from a decoded error
  /// response. Every Cambios endpoint (`Rest\HandlesCapitanAuthorization`)
  /// uses the WP_Error shape `{"code": ...}` — mirrors
  /// [ProdeApiService.extractErrorCode]'s own logic, duplicated here (rather
  /// than called across a `@visibleForTesting` boundary) since that method is
  /// intentionally scoped to its own library plus tests.
  String _extractErrorCode(Map<String, dynamic> body) {
    final code = body['code'];
    if (code is String) return code;
    final error = body['error'];
    if (error is String) return error;
    return 'unknown';
  }
}

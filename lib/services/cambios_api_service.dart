import 'dart:convert';

import 'package:http/http.dart' as http;

import '../models/cambios_candidato.dart';
import '../models/cambios_mis_equipos.dart';
import '../models/cambios_plaza.dart';
import '../models/cambios_solicitud.dart';
import 'prode_api_service.dart';

/// Thrown by any [CambiosApiService] method on a non-200 response the
/// transport did not already turn into a more specific exception.
///
/// [code] is the machine-readable `code` from the response body (via
/// [ProdeApiService.extractErrorCode]) — notably `'no_autorizado'` for the
/// generic 403 every captain-facing Cambios endpoint returns on ANY
/// authorization failure (invalid token, revoked session, or "not captain of
/// this team/season") — see `Rest\HandlesCapitanAuthorization`'s docblock on
/// the backend. Screens must render a friendly, generic message for this,
/// never the raw code or the server's `message`.
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
/// *** WHY A 401-TRIGGERED REFRESH NEVER ACTUALLY FIRES FOR THIS API ***
/// `Rest\HandlesCapitanAuthorization` (the backend trait every Cambios
/// controller uses) maps EVERY authorization failure — including a merely
/// EXPIRED token — to a generic 403 `no_autorizado`, never a 401. That means
/// [ProdeApiService.request]'s 401-interceptor (which is what triggers the
/// silent refresh) never engages for these endpoints: an expired-but-not-yet
/// -refreshed token surfaces here as an opaque 403, not a transparent retry.
/// This is a real gap in the backend's contract with this client, not a bug
/// in this service — flagged here so a future reader doesn't "fix" this
/// class by inventing its own 403-triggered refresh (which would try to
/// distinguish "expired" from "not captain of this team" when the backend
/// deliberately does not say which one happened).
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

  Future<List<CambiosCandidato>> fetchCandidatos({
    required int seasonId,
    required int teamId,
    required int plazaId,
    String? search,
  }) async {
    final uri = Uri.parse('$_baseUrl/plazas/candidatos').replace(queryParameters: {
      'season_id': '$seasonId',
      'team_id': '$teamId',
      'plaza_id': '$plazaId',
      if (search != null && search.isNotEmpty) 'search': search,
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
      return raw
          .whereType<Map>()
          .map((e) => CambiosCandidato.fromJson(e.cast<String, dynamic>()))
          .toList(growable: false);
    } on CambiosMalformedResponseException {
      rethrow;
    } catch (_) {
      throw const CambiosMalformedResponseException();
    }
  }

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

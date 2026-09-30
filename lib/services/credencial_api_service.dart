import 'dart:convert';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

import '../models/credencial.dart';
import 'prode_api_service.dart';

/// Thrown by [CredencialApiService] for any non-2xx response that is NOT
/// already handled by [ProdeApiService.request]'s own auth machinery (which
/// throws [ProdeAuthRequired] on an unrecoverable 401 — see that method's own
/// docblock; this service never catches or reinterprets that exception).
///
/// [code] is one of the machine-readable codes from design D1 (GET, prode's
/// own auth codes are handled by [ProdeAuthRequired] instead, so a
/// [CredencialApiException] from [CredencialApiService.fetchCredencial] means
/// a 500 `error_interno`) or D9 (POST /foto: `empty_body`, `invalid_base64`,
/// `invalid_image`, `image_too_large`, `image_dimensions`,
/// `insufficient_memory`, `already_pending`, `blocked`, `not_a_player`,
/// `too_many_uploads`) — callers can branch on it without inspecting
/// [statusCode] directly.
class CredencialApiException implements Exception {
  final int statusCode;
  final String code;

  const CredencialApiException({required this.statusCode, required this.code});

  @override
  String toString() => 'CredencialApiException($statusCode, $code)';
}

/// Outcome of a successful photo upload (design Interfaces section:
/// `202 {request_id, status: pending}`).
class PhotoUploadAccepted {
  final int requestId;

  const PhotoUploadAccepted({required this.requestId});
}

/// HTTP client for `entre-redes/v1/credencial/*` (design "Technical
/// Approach": "Flutter reuses ProdeApiService.request() as its authenticated
/// transport").
///
/// Deliberately COMPOSES [ProdeApiService] rather than extending or wrapping
/// it — it calls the shared [ProdeApiService.request] transport (Bearer
/// attachment + silent refresh + [ProdeAuthRequired] on an unrecoverable
/// 401) for both of this plugin's routes, so a session going stale mid
/// credential-check is handled identically to every other authenticated
/// Prode endpoint, without duplicating that machinery.
class CredencialApiService {
  final ProdeApiService _prodeApi;
  final String _baseUrl;

  /// [credencialApiBaseUrl] must be `'$apiBaseUrl/credencial'` — no trailing
  /// slash (mirrors [ProdeAuthConfig.prodeApiBaseUrl]'s own convention: the
  /// tenant's plain `apiBaseUrl` plus this plugin's REST base,
  /// `RestController::BASE = 'credencial'`).
  CredencialApiService({
    required ProdeApiService prodeApi,
    required String credencialApiBaseUrl,
  })  : _prodeApi = prodeApi,
        _baseUrl = credencialApiBaseUrl;

  /// GET /credencial/credencial (design Interfaces section).
  ///
  /// Outcomes:
  /// - **200** — returns the parsed [CredencialResponse], regardless of
  ///   `state` (active/blocked/no_photo/not_a_player are ALL reported as
  ///   200s — see `Credencial\CredencialState`).
  /// - **401 surviving refresh** — [ProdeApiService.request] throws
  ///   [ProdeAuthRequired] (propagates unchanged; this method does not catch
  ///   it — design D6's revalidation matrix decides what to do with it).
  /// - **any other non-200** — throws [CredencialApiException] (in practice
  ///   only a 500 `error_interno`, per `CredencialController`'s own
  ///   docblock).
  Future<CredencialResponse> fetchCredencial() async {
    final req = http.Request('GET', Uri.parse('$_baseUrl/credencial'))
      ..headers['Accept'] = 'application/json';

    final response = await _prodeApi.request(req).timeout(
          const Duration(seconds: 15),
        );

    final body = _decodeBody(response);

    if (response.statusCode == 200) {
      return CredencialResponse.fromJson(body);
    }

    throw CredencialApiException(
      statusCode: response.statusCode,
      code: _extractErrorCode(body),
    );
  }

  /// POST /credencial/foto (design D7: JSON `{image_base64}` body; D9: the
  /// full validation pipeline and its error codes).
  ///
  /// [jpegBytes] must already have passed the client-side JPEG/PNG + 5 MB
  /// check (spec "Upload Constraints" — this method does not repeat it; that
  /// check lives in the slice 4 upload screen).
  ///
  /// Outcomes:
  /// - **202** — returns [PhotoUploadAccepted] with the new `request_id`.
  /// - **401 surviving refresh** — [ProdeAuthRequired] (propagates
  ///   unchanged).
  /// - **any other non-202** — throws [CredencialApiException] with one of
  ///   design D9's codes.
  Future<PhotoUploadAccepted> uploadPhoto(Uint8List jpegBytes) async {
    final req = http.Request('POST', Uri.parse('$_baseUrl/foto'))
      ..headers['Content-Type'] = 'application/json'
      ..headers['Accept'] = 'application/json'
      ..body = json.encode({'image_base64': base64Encode(jpegBytes)});

    // Longer timeout than fetchCredencial(): this request carries an
    // up-to-5MB base64 body, unlike every other endpoint on this transport.
    final response = await _prodeApi.request(req).timeout(
          const Duration(seconds: 30),
        );

    final body = _decodeBody(response);

    if (response.statusCode == 202) {
      final requestId = body['request_id'];
      if (requestId is! int) {
        throw const CredencialApiException(
          statusCode: 202,
          code: 'malformed_response',
        );
      }
      return PhotoUploadAccepted(requestId: requestId);
    }

    throw CredencialApiException(
      statusCode: response.statusCode,
      code: _extractErrorCode(body),
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

  String _extractErrorCode(Map<String, dynamic> body) {
    final code = body['code'];
    if (code is String) return code;
    return 'unknown';
  }
}

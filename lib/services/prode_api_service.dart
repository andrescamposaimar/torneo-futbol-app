import 'dart:convert';
import 'package:http/http.dart' as http;
import '../config/prode_auth_config.dart';
import 'prode_auth_repository.dart';

// ---------------------------------------------------------------------------
// Exceptions
// ---------------------------------------------------------------------------

/// Thrown when an authenticated Prode request fails because the user's
/// session is missing, expired, or revoked and automatic token refresh
/// either failed or was not attempted.
///
/// Callers (typically [ProdeAuthController]) should catch this and
/// transition the auth state to Unauthenticated or Revoked accordingly.
class ProdeAuthRequired implements Exception {
  /// Machine-readable error code from the server response, e.g.
  /// `token_expired`, `session_revoked`, `refresh_token_invalid`.
  final String code;
  final String message;

  const ProdeAuthRequired({required this.code, required this.message});

  @override
  String toString() => 'ProdeAuthRequired($code): $message';
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/// Minimal HTTP transport layer for the Prode REST API.
///
/// Responsibilities:
///   1. Attach `Authorization: Bearer <access_token>` to every request.
///   2. On 401 with `token_expired` — attempt a single token refresh via
///      `POST /prode/auth/refresh`, then retry the original request.
///   3. On 401 with `session_revoked` — clear tokens and throw
///      [ProdeAuthRequired] so the auth gate can transition to Revoked.
///   4. Bubble any other 401 as [ProdeAuthRequired].
///
/// This class is intentionally dumb about endpoint shape.
/// Endpoint methods (getActiveFecha, submitPredictions, etc.) will be
/// added in later PRs once the UI screens land.
class ProdeApiService {
  // ignore: unused_field — will be used by endpoint methods added in PR-05+
  final ProdeAuthConfig _config;
  final ProdeAuthRepository _authRepo;
  final http.Client _httpClient;

  ProdeApiService({
    required ProdeAuthConfig config,
    required ProdeAuthRepository authRepo,
    http.Client? httpClient,
  })  : _config = config,
        _authRepo = authRepo,
        _httpClient = httpClient ?? http.Client();

  // ---------------------------------------------------------------------------
  // Internal transport helpers
  // ---------------------------------------------------------------------------

  /// Base URL for Prode endpoints (no trailing slash).
  /// Derived from [ProdeAuthConfig] — in a future refactor the API base URL
  /// may be moved to [TenantConfig.apiBaseUrl] + '/prode'; for now it is
  /// provided via [ProdeAuthConfig] to keep PR-04 self-contained.
  ///
  /// NOTE: The design places the Prode routes under the WP REST API base
  /// already stored in [TenantConfig.apiBaseUrl]. The actual base URL for
  /// token refresh is hardcoded here as a relative concern; the full URL
  /// is built at call sites using the tenant's apiBaseUrl. This method
  /// exists as a hook for future centralisation.
  String _refreshUrl(String apiBaseUrl) => '$apiBaseUrl/prode/auth/refresh';

  /// Executes [request] with a Bearer token attached.
  ///
  /// [apiBaseUrl] is the tenant's WP REST base URL — needed for the
  /// refresh endpoint if a 401 is encountered.
  ///
  /// Returns the final [http.Response] (possibly from a retry after refresh).
  /// Throws [ProdeAuthRequired] when the token cannot be refreshed or the
  /// session is revoked.
  Future<http.Response> request(
    http.Request request, {
    required String apiBaseUrl,
  }) async {
    final accessToken = await _authRepo.readAccessToken();
    final authedRequest = _cloneWithBearer(request, accessToken);
    final response = await _httpClient.send(authedRequest).then(http.Response.fromStream);

    if (response.statusCode != 401) {
      return response;
    }

    // --- 401 handling ---
    final body = _decodeBody(response);
    final errorCode = (body['error'] as String?) ?? 'unknown';

    if (errorCode == 'token_expired') {
      // Attempt a single refresh.
      final refreshToken = await _authRepo.readRefreshToken();
      if (refreshToken == null) {
        await _authRepo.clear();
        throw const ProdeAuthRequired(
          code: 'token_expired',
          message: 'No refresh token available.',
        );
      }

      final newTokens = await _attemptRefresh(
        refreshToken: refreshToken,
        refreshUrl: _refreshUrl(apiBaseUrl),
      );

      if (newTokens == null) {
        await _authRepo.clear();
        throw const ProdeAuthRequired(
          code: 'token_expired',
          message: 'Token refresh failed.',
        );
      }

      // Persist new tokens.
      await _authRepo.write(
        accessToken: newTokens['access_token'] as String,
        refreshToken: newTokens['refresh_token'] as String,
        sessionVersion: (newTokens['user']?['session_version'] as int? ?? 0).toString(),
        tenantId: await _authRepo.readTenantId() ?? '',
      );

      // Retry the original request once with the new access token.
      final retryRequest = _cloneWithBearer(request, newTokens['access_token'] as String);
      return _httpClient.send(retryRequest).then(http.Response.fromStream);
    }

    if (errorCode == 'session_revoked') {
      await _authRepo.clear();
      throw ProdeAuthRequired(
        code: 'session_revoked',
        message: (body['message'] as String?) ?? 'Session was revoked.',
      );
    }

    // Generic 401 (invalid token, missing header, etc.)
    throw ProdeAuthRequired(
      code: errorCode,
      message: (body['message'] as String?) ?? 'Authentication required.',
    );
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  /// Clones [original] and overwrites the Authorization header.
  http.Request _cloneWithBearer(http.Request original, String? token) {
    final copy = http.Request(original.method, original.url)
      ..headers.addAll(original.headers)
      ..body = original.body;
    if (token != null) {
      copy.headers['Authorization'] = 'Bearer $token';
    }
    return copy;
  }

  /// Calls `POST /prode/auth/refresh` with [refreshToken].
  ///
  /// Returns the parsed response body map on success (200),
  /// or null if the refresh request itself fails or returns non-200.
  Future<Map<String, dynamic>?> _attemptRefresh({
    required String refreshToken,
    required String refreshUrl,
  }) async {
    try {
      final response = await _httpClient.post(
        Uri.parse(refreshUrl),
        headers: {'Content-Type': 'application/json'},
        body: json.encode({'refresh_token': refreshToken}),
      );
      if (response.statusCode == 200) {
        return _decodeBody(response);
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  Map<String, dynamic> _decodeBody(http.Response response) {
    try {
      final decoded = json.decode(response.body);
      if (decoded is Map<String, dynamic>) return decoded;
      return {};
    } catch (_) {
      return {};
    }
  }
}

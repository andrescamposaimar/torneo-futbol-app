import 'dart:convert';
import 'package:flutter/foundation.dart';
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
///      Reads from the in-memory cache first; falls back to storage only
///      when the cache is empty (e.g., after a cold start or logout).
///   2. On 401 with `token_expired` — attempt a single token refresh via
///      `POST /prode/auth/refresh`, then retry the original request.
///      Updates the in-memory cache after a successful refresh.
///   3. On 401 with `session_revoked` — clear tokens and throw
///      [ProdeAuthRequired] so the auth gate can transition to Revoked.
///   4. Bubble any other 401 as [ProdeAuthRequired].
///
/// This class is intentionally dumb about endpoint shape.
/// Endpoint methods (getActiveFecha, submitPredictions, etc.) will be
/// added in later PRs once the UI screens land.
class ProdeApiService {
  final ProdeAuthConfig _config;
  final ProdeAuthRepository _authRepo;
  final http.Client _httpClient;

  /// In-memory cache for the current access token.
  ///
  /// Avoids a secure-storage read on every authenticated request.
  /// Invalidated on logout ([invalidateTokenCache]) and updated after
  /// every successful token refresh.
  ///
  /// INVARIANT: whenever [ProdeAuthRepository] is written to or cleared,
  /// the caller is responsible for also calling [invalidateTokenCache] so
  /// the cache stays consistent. [ProdeAuthController] orchestrates both.
  String? _cachedAccessToken;

  /// Derived once from config — no trailing slash.
  late final String _refreshUrl =
      '${_config.prodeApiBaseUrl}/auth/refresh';

  ProdeApiService({
    required ProdeAuthConfig config,
    required ProdeAuthRepository authRepo,
    http.Client? httpClient,
  })  : _config = config,
        _authRepo = authRepo,
        _httpClient = httpClient ?? http.Client();

  // ---------------------------------------------------------------------------
  // Public API
  // ---------------------------------------------------------------------------

  /// Clears the in-memory access-token cache.
  ///
  /// Call this whenever the underlying token storage is modified externally
  /// (e.g., logout clears the repository) so the cache stays in sync.
  void invalidateTokenCache() {
    _cachedAccessToken = null;
  }

  /// Executes [request] with a Bearer token attached.
  ///
  /// Reads the access token from the in-memory cache first; falls back to
  /// [ProdeAuthRepository.readAccessToken] only when the cache is null.
  ///
  /// Returns the final [http.Response] (possibly from a retry after refresh).
  /// Throws [ProdeAuthRequired] when the token cannot be refreshed or the
  /// session is revoked.
  Future<http.Response> request(http.Request request) async {
    // Resolve access token: cache → storage fallback.
    _cachedAccessToken ??= await _authRepo.readAccessToken();

    final authedRequest = _cloneWithBearer(request, _cachedAccessToken);
    final response =
        await _httpClient.send(authedRequest).then(http.Response.fromStream);

    if (response.statusCode != 401) {
      return response;
    }

    // --- 401 handling ---
    final body = _decodeBody(response);
    // AuthMiddleware-protected endpoints serialize WP_Error as {"code":...},
    // while /auth/refresh emits a custom envelope with {"error":...}.
    // Accept both so the recovery branches fire on every authenticated path.
    final errorCode = (body['code'] as String?) ??
        (body['error'] as String?) ??
        'unknown';

    if (errorCode == 'token_expired') {
      // Attempt a single refresh.
      final refreshToken = await _authRepo.readRefreshToken();
      if (refreshToken == null) {
        await _authRepo.clear();
        invalidateTokenCache();
        throw const ProdeAuthRequired(
          code: 'token_expired',
          message: 'No refresh token available.',
        );
      }

      final newTokens = await _attemptRefresh(refreshToken: refreshToken);

      if (newTokens == null) {
        await _authRepo.clear();
        invalidateTokenCache();
        throw const ProdeAuthRequired(
          code: 'token_expired',
          message: 'Token refresh failed.',
        );
      }

      // Safe extraction: a 200 with a malformed body still reaches here,
      // and casting null/wrong-typed fields would throw uncaught TypeError.
      final newAccess = newTokens['access_token'];
      final newRefresh = newTokens['refresh_token'];
      final user = newTokens['user'];
      final rawSessionVersion =
          user is Map ? user['session_version'] : null;

      if (newAccess is! String || newRefresh is! String) {
        await _authRepo.clear();
        invalidateTokenCache();
        throw const ProdeAuthRequired(
          code: 'token_expired',
          message: 'Refresh response missing or malformed tokens.',
        );
      }

      // session_version may arrive as int (PHP int) or string (proxy/driver
      // serialization). Both are accepted; anything else is a hard failure
      // — storing 0 would force an immediate session_revoked on the retry.
      final sessionVersion = rawSessionVersion is int
          ? rawSessionVersion
          : int.tryParse(rawSessionVersion?.toString() ?? '');
      if (sessionVersion == null) {
        await _authRepo.clear();
        invalidateTokenCache();
        throw const ProdeAuthRequired(
          code: 'token_expired',
          message: 'Refresh response missing session_version.',
        );
      }

      // Persist only the rotating fields; tenantId is set at login and
      // is NOT echoed in the refresh response.
      await _authRepo.writeTokens(
        accessToken: newAccess,
        refreshToken: newRefresh,
        sessionVersion: sessionVersion.toString(),
      );

      // Update the in-memory cache with the fresh access token.
      _cachedAccessToken = newAccess;

      // Retry the original request once with the new access token.
      final retryRequest = _cloneWithBearer(request, newAccess);
      return _httpClient.send(retryRequest).then(http.Response.fromStream);
    }

    if (errorCode == 'session_revoked') {
      await _authRepo.clear();
      invalidateTokenCache();
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
  }) async {
    try {
      final response = await _httpClient.post(
        Uri.parse(_refreshUrl),
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
    } on FormatException catch (e) {
      debugPrint(
        'ProdeApiService: failed to decode response body '
        '(status=${response.statusCode}): $e',
      );
      return {};
    }
  }
}

import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import '../config/prode_auth_config.dart';
import 'prode_auth_repository.dart';
import 'prode_auth_state.dart';

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
  /// Cache coherence is maintained automatically: [ProdeAuthRepository]
  /// fires [onTokensChanged] after every successful write or clear, and
  /// [prodeAuthControllerProvider] wires that callback to
  /// [invalidateTokenCache]. Any write to the repository — including from
  /// future code paths — will automatically keep this cache in sync.
  String? _cachedAccessToken;

  /// Derived once from config — no trailing slash.
  late final String _refreshUrl =
      '${_config.prodeApiBaseUrl}/auth/refresh';

  /// Single-flight guard for token refresh. When two concurrent callers
  /// (bootstrap + a request hitting 401) both need to refresh, the second
  /// one awaits the first's `http.Response?` instead of triggering a
  /// duplicate `POST /prode/auth/refresh` — which would cause the second
  /// to fail with `refresh_token_invalid` because the server rotated the
  /// token on the first call.
  ///
  /// Returns the raw response so both call sites can branch on status:
  /// the 401-interceptor in [request] treats any non-200 as failure,
  /// while [attemptSilentRefresh] distinguishes 401 (clear + revoke) from
  /// network failure (keep tokens).
  Future<http.Response?>? _inflightRefresh;

  /// Notified when a 401 cannot be recovered (refresh failed, session
  /// revoked, or no token present). [ProdeAuthController] sets this in
  /// [prodeAuthControllerProvider] so it can transition the state machine
  /// to Revoked / Unauthenticated. The service ALSO throws
  /// [ProdeAuthRequired] so callers can act on either signal.
  void Function(ProdeAuthRequired)? onAuthRequired;

  /// Notified after a successful 401-interceptor refresh in [request] with
  /// the freshly-parsed [ProdeUser] from the server's response.
  ///
  /// [ProdeAuthController] sets this in [prodeAuthControllerProvider] so
  /// the controller's state can be lifted out of the degraded-placeholder
  /// (userId=0, name='') that bootstrap emits when it could not reach the
  /// server on cold start. Without this wiring the placeholder would
  /// persist for the rest of the session.
  void Function(ProdeUser)? onTokensRefreshed;

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

    // Fail fast when no token is present — avoids a wasted unauthenticated
    // round-trip and a confusing 'unknown' error code in the 401 path.
    if (_cachedAccessToken == null) {
      _emitAuthRequired(
        code: 'unauthenticated',
        message: 'No access token available — caller must authenticate first.',
      );
    }

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
        _emitAuthRequired(
          code: 'token_expired',
          message: 'No refresh token available.',
        );
      }

      final refreshResponse =
          await _attemptRefresh(refreshToken: refreshToken);

      if (refreshResponse == null || refreshResponse.statusCode != 200) {
        await _authRepo.clear();
        invalidateTokenCache();
        _emitAuthRequired(
          code: 'token_expired',
          message: 'Token refresh failed.',
        );
      }

      // Safe extraction: a 200 with a malformed body still reaches here,
      // and casting null/wrong-typed fields would throw uncaught TypeError.
      final newTokens = _decodeBody(refreshResponse);
      final newAccess = newTokens['access_token'];
      final newRefresh = newTokens['refresh_token'];
      final user = newTokens['user'];
      final rawSessionVersion =
          user is Map ? user['session_version'] : null;

      if (newAccess is! String || newRefresh is! String) {
        await _authRepo.clear();
        invalidateTokenCache();
        _emitAuthRequired(
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
        _emitAuthRequired(
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

      // Notify the controller with the freshly-parsed user so the state
      // machine can lift any degraded-placeholder ProdeUser out of
      // Authenticated. Silently best-effort: a malformed user payload
      // doesn't fail the refresh — tokens already rotated successfully.
      if (user is Map<String, dynamic>) {
        final refreshedUser = parseProdeUser(user);
        if (refreshedUser != null) {
          onTokensRefreshed?.call(refreshedUser);
        }
      }

      // Retry the original request once with the new access token.
      final retryRequest = _cloneWithBearer(request, newAccess);
      return _httpClient.send(retryRequest).then(http.Response.fromStream);
    }

    if (errorCode == 'session_revoked') {
      await _authRepo.clear();
      invalidateTokenCache();
      _emitAuthRequired(
        code: 'session_revoked',
        message: (body['message'] as String?) ?? 'Session was revoked.',
      );
    }

    // Generic 401 (invalid token, missing header, etc.)
    _emitAuthRequired(
      code: errorCode,
      message: (body['message'] as String?) ?? 'Authentication required.',
    );
  }

  // ---------------------------------------------------------------------------
  // Silent-refresh (bootstrap path)
  // ---------------------------------------------------------------------------

  /// Attempts a token refresh using [refreshToken] during app bootstrap.
  ///
  /// Routes through the same single-flight [_attemptRefresh] slot as the
  /// 401-interceptor in [request], so concurrent bootstrap + 401-recovery
  /// flows share one underlying `POST /prode/auth/refresh` instead of
  /// racing to invalidate the same refresh token.
  ///
  /// Outcomes:
  /// - **200 with valid user payload** — persists new tokens via
  ///   [ProdeAuthRepository.writeTokens], updates [_cachedAccessToken],
  ///   and returns the parsed [ProdeUser].
  /// - **401 (any code)** — clears storage, fires [onAuthRequired] with the
  ///   error code (so the controller transitions to Revoked or
  ///   Unauthenticated), and THROWS [ProdeAuthRequired]. Callers should
  ///   wrap the call in `try/on ProdeAuthRequired` to respect the state
  ///   transition that already happened.
  /// - **Network failure / non-401 server error / malformed 200 body** —
  ///   returns null WITHOUT clearing storage. Tokens may still be valid;
  ///   we just could not confirm. The caller ([ProdeAuthController.bootstrap])
  ///   handles this as the degraded-fallback case.
  Future<ProdeUser?> attemptSilentRefresh({
    required String refreshToken,
  }) async {
    final response = await _attemptRefresh(refreshToken: refreshToken);
    if (response == null) {
      // Network failure — do NOT clear storage.
      return null;
    }

    if (response.statusCode == 200) {
      final body = _decodeBody(response);
      final newAccess = body['access_token'];
      final newRefresh = body['refresh_token'];
      final userMap = body['user'];

      if (newAccess is String &&
          newRefresh is String &&
          userMap is Map<String, dynamic>) {
        final user = parseProdeUser(userMap);
        if (user != null) {
          await _authRepo.writeTokens(
            accessToken: newAccess,
            refreshToken: newRefresh,
            sessionVersion: user.sessionVersion.toString(),
          );
          // writeTokens fires onTokensChanged → invalidateTokenCache;
          // update the cache with the fresh token.
          _cachedAccessToken = newAccess;
          return user;
        }
      }
      // 200 but malformed body — treat as non-fatal, return null without
      // clearing (same as network failure: tokens might still be usable).
      return null;
    }

    if (response.statusCode == 401) {
      final body = _decodeBody(response);
      final errorCode = (body['code'] as String?) ??
          (body['error'] as String?) ??
          'unknown';

      await _authRepo.clear();
      // clear() fires onTokensChanged → invalidateTokenCache automatically.

      if (errorCode == 'session_revoked') {
        _emitAuthRequired(
          code: 'session_revoked',
          message: (body['message'] as String?) ?? 'Session was revoked.',
        );
      } else {
        _emitAuthRequired(
          code: errorCode,
          message: (body['message'] as String?) ?? 'Silent refresh failed.',
        );
      }
    }

    // Non-401 server error — do NOT clear storage.
    return null;
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

  /// Calls `POST /prode/auth/refresh` with [refreshToken], deduplicating
  /// concurrent refreshes via [_inflightRefresh]. If a refresh is already
  /// in flight, this method returns the same Future as the in-flight call —
  /// the second caller does not trigger a duplicate HTTP request.
  ///
  /// Returns the raw [http.Response] so callers can branch on status code:
  /// [request]'s 401-interceptor treats any non-200 as failure, while
  /// [attemptSilentRefresh] distinguishes 401 (clear + revoke) from
  /// network failure (keep tokens). Returns null only on transport error
  /// (no response received).
  Future<http.Response?> _attemptRefresh({
    required String refreshToken,
  }) {
    final inflight = _inflightRefresh;
    if (inflight != null) {
      return inflight;
    }
    final future = _doRefresh(refreshToken: refreshToken);
    _inflightRefresh = future;
    // Clear the slot regardless of success/failure so the next refresh can
    // start fresh. whenComplete fires after awaiting callers receive the
    // result.
    future.whenComplete(() {
      if (identical(_inflightRefresh, future)) {
        _inflightRefresh = null;
      }
    });
    return future;
  }

  /// The actual refresh HTTP call. Separated from [_attemptRefresh] so the
  /// single-flight wrapper above can manage the in-flight slot cleanly.
  ///
  /// Returns the raw response (any status code) or null on transport error.
  Future<http.Response?> _doRefresh({
    required String refreshToken,
  }) async {
    try {
      return await _httpClient.post(
        Uri.parse(_refreshUrl),
        headers: {'Content-Type': 'application/json'},
        body: json.encode({'refresh_token': refreshToken}),
      );
    } catch (_) {
      return null;
    }
  }

  /// Parses a `user` map from an auth/refresh response into a [ProdeUser].
  ///
  /// Returns null when [raw] is null, any required field is absent, or any
  /// field has the wrong type. Callers treat null as a parse failure and
  /// fall back to the degraded-placeholder path.
  ///
  /// Annotated [@visibleForTesting] so parser semantics can be unit-tested
  /// directly without spinning up an HTTP mock + storage fake to exercise
  /// them through [attemptSilentRefresh].
  @visibleForTesting
  static ProdeUser? parseProdeUser(Map<String, dynamic>? raw) {
    if (raw == null) return null;

    final rawUserId = raw['user_id'];
    final rawPlayerId = raw['player_id'];
    final rawName = raw['name'];
    final rawSv = raw['session_version'];

    final userId = rawUserId is int ? rawUserId : null;
    final playerId = rawPlayerId is int ? rawPlayerId : null;
    final name = rawName is String ? rawName : null;
    // session_version may arrive as int (PHP) or String (some proxies).
    final sessionVersion = rawSv is int
        ? rawSv
        : (rawSv is String ? int.tryParse(rawSv) : null);

    if (userId == null ||
        playerId == null ||
        name == null ||
        sessionVersion == null) {
      return null;
    }

    return ProdeUser(
      userId: userId,
      playerId: playerId,
      name: name,
      sessionVersion: sessionVersion,
    );
  }

  /// Notifies [onAuthRequired] and throws [ProdeAuthRequired].
  ///
  /// `Never` return type tells Dart this call never returns normally, so the
  /// compiler treats subsequent code as unreachable.
  Never _emitAuthRequired({required String code, required String message}) {
    final exc = ProdeAuthRequired(code: code, message: message);
    onAuthRequired?.call(exc);
    throw exc;
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

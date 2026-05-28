import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'prode_auth_repository.dart';
import 'prode_api_service.dart';
import 'prode_auth_state.dart';

/// State machine controller for Prode authentication.
///
/// Manages the [ProdeAuthState] lifecycle:
///   - [bootstrap] — called once on app start; reads stored tokens.
///   - [logout] — clears tokens and service cache; transitions to Unauthenticated.
///   - [onAuthRequired] — called by [ProdeApiService] when a 401 cannot be
///     recovered; transitions to Revoked or Unauthenticated based on the error.
///
/// SSO and DNI-confirmation methods ([signInWithGoogle], [signInWithApple],
/// [confirmDni]) are placeholders — they throw [UnimplementedError] until
/// PR-06 wires the real SDKs.
class ProdeAuthController extends StateNotifier<ProdeAuthState> {
  final ProdeAuthRepository _repository;
  final ProdeApiService _service;

  ProdeAuthController({
    required ProdeAuthRepository repository,
    required ProdeApiService service,
  })  : _repository = repository,
        _service = service,
        super(const ProdeAuthUnauthenticated());

  // ---------------------------------------------------------------------------
  // Implemented methods
  // ---------------------------------------------------------------------------

  /// Called once at app start to restore session from secure storage.
  ///
  /// Transitions:
  ///   Unauthenticated → Hydrating → Authenticated (tokens found)
  ///   Unauthenticated → Hydrating → Unauthenticated (no tokens)
  ///
  /// Intentionally does NOT make a network call — just checks storage.
  /// Silent token refresh (if needed) is deferred to the first actual
  /// API call via the 401 interceptor in [ProdeApiService].
  Future<void> bootstrap() async {
    state = const ProdeAuthHydrating();
    try {
      final accessToken = await _repository.readAccessToken();
      final refreshToken = await _repository.readRefreshToken();

      if (accessToken != null && refreshToken != null) {
        // Tokens present — treat the session as authenticated.
        // Full user profile (userId, playerId, name) is not persisted in
        // storage; we use a bootstrap placeholder. PR-06 will perform a
        // silent refresh on first Prode screen access, which returns the
        // real user object and lets the controller update state.
        final sessionVersion =
            _parseSessionVersion(await _repository.readSessionVersion());
        state = ProdeAuthAuthenticated(
          user: ProdeUser(
            userId: 0, // placeholder — resolved by PR-06 silent refresh
            playerId: 0, // placeholder — resolved by PR-06 silent refresh
            name: '', // placeholder — resolved by PR-06 silent refresh
            sessionVersion: sessionVersion,
          ),
        );
      } else {
        state = const ProdeAuthUnauthenticated();
      }
    } catch (e) {
      state = ProdeAuthError(
        code: 'bootstrap_error',
        message: e.toString(),
      );
    }
  }

  /// Clears all stored tokens and the service's in-memory cache, then
  /// transitions to [ProdeAuthUnauthenticated].
  ///
  /// Per ADR-P004, logout is client-side only — no server round-trip.
  Future<void> logout() async {
    try {
      await _repository.clear();
      _service.invalidateTokenCache();
      state = const ProdeAuthUnauthenticated();
    } catch (e) {
      state = ProdeAuthError(
        code: 'logout_error',
        message: e.toString(),
      );
    }
  }

  /// Called by [ProdeApiService] (or any caller) when a 401 response could
  /// not be recovered by the refresh flow.
  ///
  /// - `session_revoked` → [ProdeAuthRevoked]
  /// - any other code → [ProdeAuthUnauthenticated] (tokens stale/missing)
  void onAuthRequired(ProdeAuthRequired exception) {
    if (exception.code == 'session_revoked') {
      state = ProdeAuthRevoked(reason: exception.code);
    } else {
      state = const ProdeAuthUnauthenticated();
    }
  }

  // ---------------------------------------------------------------------------
  // PR-06 placeholders
  // ---------------------------------------------------------------------------

  /// Initiates Google Sign-In flow.
  ///
  /// TODO(PR-06): wire google_sign_in SDK, call POST /prode/auth/google,
  /// handle step=authenticated → Authenticated and step=dni_confirmation
  /// → NeedsDniConfirmation transitions.
  Future<void> signInWithGoogle() {
    throw UnimplementedError(
      'signInWithGoogle is not implemented in PR-05. '
      'Implement in PR-06 with google_sign_in SDK.',
    );
  }

  /// Initiates Apple Sign-In flow.
  ///
  /// TODO(PR-06): wire sign_in_with_apple SDK, call POST /prode/auth/apple,
  /// handle the same step transitions as [signInWithGoogle].
  Future<void> signInWithApple() {
    throw UnimplementedError(
      'signInWithApple is not implemented in PR-05. '
      'Implement in PR-06 with sign_in_with_apple SDK.',
    );
  }

  /// Confirms the user's DNI after a successful SSO step.
  ///
  /// TODO(PR-06): call POST /prode/auth/dni with [dni] and the intentToken
  /// from [ProdeAuthNeedsDniConfirmation]; on success transition to
  /// Authenticated; on failure surface the 422/409 error as ProdeAuthError.
  Future<void> confirmDni(String dni) {
    throw UnimplementedError(
      'confirmDni is not implemented in PR-05. '
      'Implement in PR-06 once ProdeDniConfirmScreen lands.',
    );
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  /// Parses a stored session_version string to int, defaulting to 1.
  int _parseSessionVersion(String? raw) {
    if (raw == null) return 1;
    return int.tryParse(raw) ?? 1;
  }
}

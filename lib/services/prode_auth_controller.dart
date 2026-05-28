import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'prode_auth_repository.dart';
import 'prode_api_service.dart';
import 'prode_auth_state.dart';

/// State machine controller for Prode authentication.
///
/// Manages the [ProdeAuthState] lifecycle:
///   - [bootstrap] — called once on app start; reads stored tokens and
///     performs a silent token refresh to resolve the real [ProdeUser].
///   - [logout] — clears tokens and service cache; transitions to Unauthenticated.
///   - [onAuthRequired] — called by [ProdeApiService] when a 401 cannot be
///     recovered; transitions to Revoked or Unauthenticated based on the error.
///
/// SSO and DNI-confirmation methods ([signInWithGoogle], [signInWithApple],
/// [confirmDni]) are placeholders — they throw [UnimplementedError] until
/// PR-07 wires the real SDKs.
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
  ///   Unauthenticated → Hydrating → Unauthenticated (no tokens)
  ///   Unauthenticated → Hydrating → Authenticated(realUser) (tokens + refresh ok)
  ///   Unauthenticated → Hydrating → Revoked (tokens + session_revoked)
  ///   Unauthenticated → Hydrating → Unauthenticated (tokens + other 401)
  ///   Unauthenticated → Hydrating → Authenticated(placeholder) (tokens + network failure)
  ///
  /// The last transition (network failure) is an intentional ADR-P004-aligned
  /// trade-off: we don't know whether the tokens are still valid, so we optimistically
  /// allow the user into the authenticated state with a placeholder ProdeUser.
  /// The placeholder fields (userId=0, playerId=0, name='') will be resolved on
  /// the first successful API call via the 401-interceptor → refresh → retry path
  /// in [ProdeApiService]. This avoids blocking app startup on a network round-trip
  /// when the backend is temporarily unreachable.
  Future<void> bootstrap() async {
    state = const ProdeAuthHydrating();
    try {
      final snapshot = await _repository.readAll();

      if (snapshot.accessToken == null || snapshot.refreshToken == null) {
        state = const ProdeAuthUnauthenticated();
        return;
      }

      // Tokens present — attempt silent refresh to get real user data.
      // _service.attemptSilentRefresh may call onAuthRequired (which
      // transitions this state machine) before returning null on a terminal 401.
      ProdeUser? user;
      try {
        user = await _service.attemptSilentRefresh(
          refreshToken: snapshot.refreshToken!,
        );
      } on ProdeAuthRequired {
        // onAuthRequired was already called inside attemptSilentRefresh,
        // which set the state. Respect that transition — do not overwrite.
        return;
      }

      if (user != null) {
        // Happy path: server confirmed the session and returned real user data.
        state = ProdeAuthAuthenticated(user: user);
        return;
      }

      // Network failure / non-401 server error: user is null but state was
      // NOT changed by onAuthRequired. Use a degraded-placeholder so the app
      // remains usable offline.
      //
      // ADR-P004 alignment: we optimistically trust the stored tokens.
      // The placeholder fields (userId=0, playerId=0, name='') will be
      // resolved the next time the user makes a successful authenticated
      // API call (the 401-interceptor refresh path in ProdeApiService will
      // then update storage and state via the controller). This is an
      // intentional trade-off between UX (instant app-open) and data
      // freshness (real user profile only after connectivity is restored).
      final sessionVersion =
          _parseSessionVersion(snapshot.sessionVersion);
      state = ProdeAuthAuthenticated(
        user: ProdeUser(
          userId: 0, // degraded placeholder — resolved on next API call
          playerId: 0, // degraded placeholder — resolved on next API call
          name: '', // degraded placeholder — resolved on next API call
          sessionVersion: sessionVersion,
        ),
      );
    } on PlatformException catch (e) {
      state = ProdeAuthError(
        code: e.code,
        message: e.message ?? e.toString(),
      );
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
  /// Note: [ProdeAuthRepository.clear] fires [onTokensChanged] which
  /// automatically invalidates the service cache via the callback wired
  /// in [prodeAuthControllerProvider]. The explicit [invalidateTokenCache]
  /// call below is kept for clarity and as a belt-and-suspenders guard.
  Future<void> logout() async {
    try {
      await _repository.clear();
      _service.invalidateTokenCache();
      state = const ProdeAuthUnauthenticated();
    } on PlatformException catch (e) {
      state = ProdeAuthError(
        code: e.code,
        message: e.message ?? e.toString(),
      );
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
  // PR-07 placeholders
  // ---------------------------------------------------------------------------

  /// Initiates Google Sign-In flow.
  ///
  /// TODO(PR-07): wire google_sign_in SDK, call POST /prode/auth/google,
  /// handle step=authenticated → Authenticated and step=dni_confirmation
  /// → NeedsDniConfirmation transitions.
  Future<void> signInWithGoogle() {
    throw UnimplementedError(
      'signInWithGoogle is not implemented. '
      'Implement in PR-07 with google_sign_in SDK.',
    );
  }

  /// Initiates Apple Sign-In flow.
  ///
  /// TODO(PR-07): wire sign_in_with_apple SDK, call POST /prode/auth/apple,
  /// handle the same step transitions as [signInWithGoogle].
  Future<void> signInWithApple() {
    throw UnimplementedError(
      'signInWithApple is not implemented. '
      'Implement in PR-07 with sign_in_with_apple SDK.',
    );
  }

  /// Confirms the user's DNI after a successful SSO step.
  ///
  /// TODO(PR-07): call POST /prode/auth/dni with [dni] and the intentToken
  /// from [ProdeAuthNeedsDniConfirmation]; on success transition to
  /// Authenticated; on failure surface the 422/409 error as ProdeAuthError.
  Future<void> confirmDni(String dni) {
    throw UnimplementedError(
      'confirmDni is not implemented. '
      'Implement in PR-07 once ProdeDniConfirmScreen lands.',
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

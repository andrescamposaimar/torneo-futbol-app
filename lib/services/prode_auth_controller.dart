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
/// [signInWithGoogle] and [confirmDni] are wired to the SSO + DNI flow.
/// [signInWithApple] remains a placeholder until its Team ID/keys are set up.
class ProdeAuthController extends StateNotifier<ProdeAuthState> {
  final ProdeAuthRepository _repository;
  final ProdeApiService _service;

  /// Tenant id stamped on tokens at initial login (see
  /// [ProdeAuthRepository.write]). Provided by the provider from the active
  /// tenant config.
  final String _tenantId;

  /// Returns a Google id_token (aud = web client id) or null if the user
  /// cancelled. Injected so the controller stays unit-testable without the
  /// google_sign_in SDK; the provider wires the real implementation.
  final Future<String?> Function()? _googleIdToken;

  ProdeAuthController({
    required ProdeAuthRepository repository,
    required ProdeApiService service,
    required String tenantId,
    Future<String?> Function()? googleIdToken,
  })  : _repository = repository,
        _service = service,
        _tenantId = tenantId,
        _googleIdToken = googleIdToken,
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
        // stale: false explicit (matches the style of the degraded-fallback
        // and onTokensRefreshed call sites).
        state = ProdeAuthAuthenticated(user: user, stale: false);
        return;
      }

      // Network failure / non-401 server error: user is null but state was
      // NOT changed by onAuthRequired. Use a degraded-placeholder so the app
      // remains usable offline. The placeholder fields will be lifted by
      // ProdeApiService.onTokensRefreshed → controller.onTokensRefreshed on
      // the first successful 401-interceptor refresh.
      final sessionVersion =
          _parseSessionVersion(snapshot.sessionVersion);
      if (sessionVersion == null) {
        // session_version unparseable — can't reconstruct a coherent session.
        // Clear and force re-authentication instead of emitting a guess that
        // the server's strict equality check would reject as session_revoked.
        await _repository.clear();
        state = const ProdeAuthUnauthenticated();
        return;
      }
      state = ProdeAuthAuthenticated(
        user: ProdeUser(
          userId: 0, // degraded placeholder — resolved on next API call
          playerId: 0, // degraded placeholder — resolved on next API call
          name: '', // degraded placeholder — resolved on next API call
          sessionVersion: sessionVersion,
        ),
        stale: true,
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

  /// Called by [ProdeApiService] after a successful 401-interceptor token
  /// refresh with the freshly-parsed [ProdeUser]. Lifts the controller out
  /// of the degraded-placeholder shape that [bootstrap] emits when it
  /// couldn't reach the server on cold start.
  ///
  /// Only transitions when the controller is already in
  /// [ProdeAuthAuthenticated]. From any other state (Unauthenticated,
  /// Revoked, Error), a refresh-driven user update would be inconsistent
  /// — the refresh should not silently re-authenticate a session that
  /// the state machine considers closed.
  void onTokensRefreshed(ProdeUser user) {
    final current = state;
    if (current is ProdeAuthAuthenticated) {
      // stale: false explicitly documents that real server-confirmed data arrived.
      state = ProdeAuthAuthenticated(user: user, stale: false);
    }
  }

  // ---------------------------------------------------------------------------
  // PR-07 placeholders
  // ---------------------------------------------------------------------------

  /// Runs the Google Sign-In flow:
  ///   1. Obtain a Google id_token via the injected provider (the SDK call).
  ///   2. Exchange it at `POST /prode/auth/google`.
  ///   3. On `authenticated` → persist tokens (stamped with the tenant id) and
  ///      transition to [ProdeAuthAuthenticated].
  ///   4. On `dni_confirmation` → transition to [ProdeAuthNeedsDniConfirmation]
  ///      so the DNI screen (later slice) can complete the link.
  ///
  /// Cancellation (provider returns null) returns to [ProdeAuthUnauthenticated]
  /// silently. Any failure surfaces as [ProdeAuthError].
  Future<void> signInWithGoogle() async {
    final getIdToken = _googleIdToken;
    if (getIdToken == null) {
      state = const ProdeAuthError(
        code: 'google_unavailable',
        message: 'Google Sign-In no está configurado.',
      );
      return;
    }

    state = const ProdeAuthAuthenticating(provider: 'google');
    try {
      final idToken = await getIdToken();
      if (idToken == null) {
        // User cancelled the Google sheet.
        state = const ProdeAuthUnauthenticated();
        return;
      }

      final result = await _service.exchangeGoogleToken(idToken);

      switch (result) {
        case ProdeSsoAuthenticated(
            :final user,
            :final accessToken,
            :final refreshToken,
          ):
          await _repository.write(
            accessToken: accessToken,
            refreshToken: refreshToken,
            sessionVersion: user.sessionVersion.toString(),
            tenantId: _tenantId,
          );
          state = ProdeAuthAuthenticated(user: user, stale: false);
        case ProdeSsoNeedsDni(:final intentToken, :final nameHint):
          state = ProdeAuthNeedsDniConfirmation(
            intentToken: intentToken,
            nameHint: nameHint,
          );
      }
    } on ProdeSsoException catch (e) {
      state = ProdeAuthError(code: e.code, message: e.message);
    } on PlatformException catch (e) {
      state = ProdeAuthError(code: e.code, message: e.message ?? e.toString());
    } catch (e) {
      state = ProdeAuthError(code: 'google_signin_error', message: e.toString());
    }
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
  /// Uses the intent token from the current [ProdeAuthNeedsDniConfirmation]
  /// state. On success, persists the final tokens (stamped with the tenant id)
  /// and transitions to [ProdeAuthAuthenticated]. On failure, the state is left
  /// unchanged (the DNI screen stays open) and a user-facing message is
  /// RETURNED for inline display — this keeps the form's submission UX local to
  /// the screen rather than spread through the state machine.
  ///
  /// Returns null on success, or a friendly error message on failure.
  Future<String?> confirmDni(String dni) async {
    final current = state;
    if (current is! ProdeAuthNeedsDniConfirmation) {
      return 'No hay una confirmación de DNI en curso.';
    }

    try {
      final result = await _service.confirmDni(
        intentToken: current.intentToken,
        dni: dni,
      );
      await _repository.write(
        accessToken: result.accessToken,
        refreshToken: result.refreshToken,
        sessionVersion: result.user.sessionVersion.toString(),
        tenantId: _tenantId,
      );
      state = ProdeAuthAuthenticated(user: result.user, stale: false);
      return null;
    } on ProdeSsoException catch (e) {
      return _dniErrorMessage(e.code);
    } catch (e) {
      return 'Algo salió mal. Reintentá en unos minutos.';
    }
  }

  /// Maps a backend error code from the DNI step to user-facing Spanish copy.
  String _dniErrorMessage(String code) {
    switch (code) {
      case 'dni_not_in_roster':
        return 'Ese DNI no figura en el padrón de jugadores del torneo. '
            'Revisá el número e intentá de nuevo.';
      case 'dni_already_associated':
        return 'Ese DNI ya está vinculado a otra cuenta.';
      case 'invalid_intent_token':
        return 'Tu ingreso expiró. Volvé atrás e iniciá sesión con Google de '
            'nuevo.';
      case 'network_error':
        return 'No se pudo contactar el servidor. Revisá tu conexión.';
      default:
        return 'No se pudo confirmar el DNI. Reintentá en unos minutos.';
    }
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  /// Parses a stored session_version string to int.
  ///
  /// Returns null when the value is missing or unparseable. The caller must
  /// treat null as "cannot reconstruct session" and force re-authentication —
  /// silently defaulting to a guess (e.g., 1) would emit a JWT the server's
  /// strict-equality check would reject as session_revoked.
  int? _parseSessionVersion(String? raw) {
    if (raw == null) return null;
    return int.tryParse(raw);
  }
}

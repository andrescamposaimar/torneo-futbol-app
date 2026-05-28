import 'package:meta/meta.dart';

// ---------------------------------------------------------------------------
// User data model
// ---------------------------------------------------------------------------

/// Authenticated Prode user — carried by [ProdeAuthAuthenticated].
///
/// Pure value class: const constructor, ==, hashCode, toString.
/// JSON parsing lives in ProdeApiService._parseProdeUser — infrastructure
/// concerns do not belong on a domain value type.
@immutable
class ProdeUser {
  final int userId;
  final int playerId;
  final String name;
  final int sessionVersion;

  const ProdeUser({
    required this.userId,
    required this.playerId,
    required this.name,
    required this.sessionVersion,
  });

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ProdeUser &&
          runtimeType == other.runtimeType &&
          userId == other.userId &&
          playerId == other.playerId &&
          name == other.name &&
          sessionVersion == other.sessionVersion;

  @override
  int get hashCode =>
      Object.hash(userId, playerId, name, sessionVersion);

  @override
  String toString() =>
      'ProdeUser(userId: $userId, playerId: $playerId, '
      'name: $name, sessionVersion: $sessionVersion)';
}

// ---------------------------------------------------------------------------
// Sealed auth state
// ---------------------------------------------------------------------------

/// Discriminated state machine for Prode authentication.
///
/// Used by [ProdeAuthController] as the single source of truth for auth
/// state across the entire Prode feature subtree.
///
/// Transition diagram (simplified):
///
/// ```
///   Unauthenticated ──bootstrap/SSO──► Hydrating/Authenticating
///   Hydrating ──tokens present──► Authenticated
///   Hydrating ──no tokens──► Unauthenticated
///   Authenticating ──SSO ok, assoc. exists──► Authenticated
///   Authenticating ──SSO ok, no assoc──► NeedsDniConfirmation
///   NeedsDniConfirmation ──DNI confirmed──► Authenticated
///   Authenticated ──logout / clear──► Unauthenticated
///   Authenticated ──session_revoked──► Revoked
///   * ──transient failure──► Error
/// ```
sealed class ProdeAuthState {
  const ProdeAuthState();
}

/// Initial state before any check has been made, and the resting state when
/// the user is not authenticated (after logout, after clear, after a failed
/// refresh with no revocation).
final class ProdeAuthUnauthenticated extends ProdeAuthState {
  const ProdeAuthUnauthenticated();

  @override
  String toString() => 'ProdeAuthUnauthenticated()';
}

/// Transient state during app startup while [ProdeAuthController.bootstrap]
/// reads tokens from secure storage.
///
/// Distinct from [ProdeAuthAuthenticating] which covers SSO in-flight.
/// UI typically shows a loading indicator or an empty splash.
final class ProdeAuthHydrating extends ProdeAuthState {
  const ProdeAuthHydrating();

  @override
  String toString() => 'ProdeAuthHydrating()';
}

/// SSO sign-in is in flight (Google or Apple SDK call in progress).
///
/// [provider] is `'google'` or `'apple'` for UI feedback.
/// Placeholder for PR-06 — the controller enters this state before
/// delegating to the (yet-unimplemented) SSO SDK methods.
final class ProdeAuthAuthenticating extends ProdeAuthState {
  final String provider;

  const ProdeAuthAuthenticating({required this.provider});

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ProdeAuthAuthenticating &&
          runtimeType == other.runtimeType &&
          provider == other.provider;

  @override
  int get hashCode => Object.hash(runtimeType, provider);

  @override
  String toString() => 'ProdeAuthAuthenticating(provider: $provider)';
}

/// SSO succeeded but no association record exists for this provider identity.
///
/// The user must confirm their DNI before a full session is issued.
/// [intentToken] is the short-lived (5 min) JWT from the backend that
/// the DNI-confirm endpoint requires.
/// [nameHint] is the display name from the SSO profile, if available,
/// so the UI can greet the user.
final class ProdeAuthNeedsDniConfirmation extends ProdeAuthState {
  final String intentToken;
  final String? nameHint;

  const ProdeAuthNeedsDniConfirmation({
    required this.intentToken,
    this.nameHint,
  });

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ProdeAuthNeedsDniConfirmation &&
          runtimeType == other.runtimeType &&
          intentToken == other.intentToken &&
          nameHint == other.nameHint;

  @override
  int get hashCode => Object.hash(runtimeType, intentToken, nameHint);

  @override
  String toString() =>
      'ProdeAuthNeedsDniConfirmation(intentToken: [redacted], '
      'nameHint: $nameHint)';
}

/// The user has a valid session (tokens present and not revoked).
///
/// [stale] is true when the session was reconstructed from a degraded
/// placeholder during bootstrap (network failure path) and the real
/// [ProdeUser] fields have not yet been confirmed by the server.
/// It transitions to false on the first successful 401-interceptor refresh
/// via [ProdeAuthController.onTokensRefreshed].
final class ProdeAuthAuthenticated extends ProdeAuthState {
  final ProdeUser user;

  /// Whether this state carries a degraded placeholder [ProdeUser] from
  /// bootstrap rather than a server-confirmed user. UI consumers should
  /// treat stale=true as "identity pending" and show reduced functionality
  /// or a loading indicator until the next successful refresh clears it.
  final bool stale;

  const ProdeAuthAuthenticated({required this.user, this.stale = false});

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ProdeAuthAuthenticated &&
          runtimeType == other.runtimeType &&
          user == other.user &&
          stale == other.stale;

  @override
  int get hashCode => Object.hash(runtimeType, user, stale);

  @override
  String toString() => 'ProdeAuthAuthenticated(user: $user, stale: $stale)';
}

/// The session was invalidated server-side (admin unlink or account
/// deletion from another device).
///
/// [reason] is the human-readable or code string from the server.
/// The UI should show a friendly "Your session was closed" screen with a
/// CTA to re-authenticate.
final class ProdeAuthRevoked extends ProdeAuthState {
  final String reason;

  const ProdeAuthRevoked({required this.reason});

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ProdeAuthRevoked &&
          runtimeType == other.runtimeType &&
          reason == other.reason;

  @override
  int get hashCode => Object.hash(runtimeType, reason);

  @override
  String toString() => 'ProdeAuthRevoked(reason: $reason)';
}

/// A transient error occurred that did not result in revocation.
///
/// [code] is a machine-readable tag (e.g. `network_error`, `unknown`).
/// [message] is human-readable context for debugging or display.
final class ProdeAuthError extends ProdeAuthState {
  final String code;
  final String message;

  const ProdeAuthError({required this.code, required this.message});

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ProdeAuthError &&
          runtimeType == other.runtimeType &&
          code == other.code &&
          message == other.message;

  @override
  int get hashCode => Object.hash(runtimeType, code, message);

  @override
  String toString() => 'ProdeAuthError(code: $code, message: $message)';
}

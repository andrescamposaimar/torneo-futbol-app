import 'dart:io' show Platform;

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:sign_in_with_apple/sign_in_with_apple.dart';

import '../config/prode_auth_config.dart';
import '../config/tenant_provider.dart';
import '../services/prode_auth_controller.dart';
import '../services/prode_auth_repository.dart';
import '../services/prode_api_service.dart';
import '../services/prode_auth_state.dart';

/// Drives the native Google Sign-In sheet and returns a Google id_token whose
/// `aud` is the web client id (via [serverClientId]) — the audience the
/// backend's GoogleVerifier validates. Returns null if the user cancels.
Future<String?> _signInWithGoogleNative(ProdeAuthConfig cfg) async {
  final googleSignIn = GoogleSignIn(
    serverClientId: cfg.googleWebClientId,
    // On iOS the native SDK needs the iOS client id; on Android the client is
    // resolved from the package name + SHA-1 registered in Google Cloud.
    clientId: Platform.isIOS ? cfg.googleIosClientId : null,
  );
  final account = await googleSignIn.signIn();
  if (account == null) return null; // cancelled
  final auth = await account.authentication;
  return auth.idToken;
}

/// Drives the native Sign in with Apple sheet (iOS) and returns the Apple
/// identity_token, or null if the user cancelled. The backend's AppleVerifier
/// validates the token against Apple's JWKS, with `aud` = the app bundle id.
Future<String?> _signInWithAppleNative() async {
  try {
    final credential = await SignInWithApple.getAppleIDCredential(
      scopes: const [
        AppleIDAuthorizationScopes.email,
        AppleIDAuthorizationScopes.fullName,
      ],
    );
    return credential.identityToken;
  } on SignInWithAppleAuthorizationException catch (e) {
    // Apple surfaces cancellation as an exception; map it to "cancelled" (null)
    // so the controller returns to Unauthenticated instead of showing an error.
    if (e.code == AuthorizationErrorCode.canceled) return null;
    rethrow;
  }
}

/// Provides a single [ProdeAuthRepository] instance for the lifetime of the
/// enclosing [ProviderScope].
///
/// Uses the default [FlutterSecureStorage] under the hood. Override in tests
/// via [ProviderContainer] overrides to inject a fake storage implementation.
final prodeAuthRepositoryProvider = Provider<ProdeAuthRepository>((ref) {
  return ProdeAuthRepository();
});

/// Provides a [ProdeApiService] wired to the active tenant's Prode config.
///
/// Throws [StateError] if accessed when [TenantFeatures.prode] is disabled —
/// this should never happen in practice because all Prode routes are gated
/// behind the feature flag (enforced at bootstrap). This guard exists to
/// surface misconfigured tenants loudly at development time.
final prodeApiServiceProvider = Provider<ProdeApiService>((ref) {
  final cfg = ref.watch(tenantConfigProvider);

  if (!cfg.features.prode) {
    throw StateError(
      'prodeApiServiceProvider accessed but cfg.features.prode is false '
      'for tenant "${cfg.tenantId}". All Prode providers must only be '
      'read within the Prode feature gate.',
    );
  }

  final prodeAuth = cfg.integrations.prodeAuth;
  if (prodeAuth == null) {
    throw StateError(
      'prodeApiServiceProvider: cfg.features.prode is true but '
      'cfg.integrations.prodeAuth is null for tenant "${cfg.tenantId}". '
      'Check bootstrap assertion.',
    );
  }

  return ProdeApiService(
    config: prodeAuth,
    authRepo: ref.read(prodeAuthRepositoryProvider),
  );
});

/// Provides the [ProdeAuthController] and exposes the [ProdeAuthState]
/// state machine to the Prode feature subtree.
///
/// Screens and widgets that need auth state should `ref.watch` this provider.
/// [ProdeAuthController] methods are accessed via `ref.read(...notifier)`.
///
/// Uses `ref.watch` on its dependencies so the controller is rebuilt
/// together with the underlying service/repository when the tenant config
/// changes. Without this, a tenant switch (or any provider invalidation
/// upstream) would leave the controller holding a stale service whose
/// `invalidateTokenCache()` calls target a dead instance.
///
/// Wires [ProdeApiService.onAuthRequired] to [ProdeAuthController.onAuthRequired]
/// at construction time so that terminal 401 responses (session_revoked,
/// refresh failures) automatically transition the state machine.
final prodeAuthControllerProvider =
    StateNotifierProvider<ProdeAuthController, ProdeAuthState>((ref) {
  final repository = ref.watch(prodeAuthRepositoryProvider);
  final service = ref.watch(prodeApiServiceProvider);
  final cfg = ref.watch(tenantConfigProvider);

  final controller = ProdeAuthController(
    repository: repository,
    service: service,
    tenantId: cfg.tenantId,
    googleIdToken: () => _signInWithGoogleNative(cfg.integrations.prodeAuth!),
    // Apple sign-in is iOS-only (the backend supports only the native flow);
    // leaving this null on other platforms makes the UI hide the Apple button.
    appleIdentityToken: Platform.isIOS ? _signInWithAppleNative : null,
  );

  // Bridge service-side 401s into the state machine.
  service.onAuthRequired = controller.onAuthRequired;

  // Bridge refreshed-user-from-401-interceptor into the state machine so the
  // controller can lift a degraded-placeholder ProdeUser (from a network-failed
  // bootstrap) on the first successful API call.
  service.onTokensRefreshed = controller.onTokensRefreshed;

  // Automatic cache coherence: any write to the repository (write, writeTokens,
  // clear, and private _writeX helpers) fires onTokensChanged, which invalidates
  // the service's in-memory access-token cache. This removes the need for every
  // call site to manually pair storage writes with cache-invalidation calls.
  repository.onTokensChanged = service.invalidateTokenCache;

  return controller;
});

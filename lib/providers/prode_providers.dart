import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../config/tenant_provider.dart';
import '../services/prode_auth_controller.dart';
import '../services/prode_auth_repository.dart';
import '../services/prode_api_service.dart';
import '../services/prode_auth_state.dart';

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

  final controller = ProdeAuthController(
    repository: repository,
    service: service,
  );

  // Bridge service-side 401s into the state machine.
  service.onAuthRequired = controller.onAuthRequired;

  // Automatic cache coherence: any write to the repository (write, writeTokens,
  // clear, and private _writeX helpers) fires onTokensChanged, which invalidates
  // the service's in-memory access-token cache. This removes the need for every
  // call site to manually pair storage writes with cache-invalidation calls.
  repository.onTokensChanged = service.invalidateTokenCache;

  return controller;
});

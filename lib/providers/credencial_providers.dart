import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/tenant_provider.dart';
import '../services/credencial_api_service.dart';
import '../services/credencial_controller.dart';
import '../services/credencial_state.dart';
import 'credencial_repository_providers.dart';
import 'prode_providers.dart';

export 'credencial_repository_providers.dart'
    show credencialPhotoStoreProvider, credencialRepositoryProvider;

/// Provides a [CredencialApiService] wired to the active tenant's base URL
/// (`'$apiBaseUrl/credencial'`, no trailing slash — see
/// [CredencialApiService]'s own constructor docblock).
///
/// Throws [StateError] if accessed when [TenantFeatures.credencial] is
/// disabled — mirrors [prodeApiServiceProvider]'s own guard. Every credencial
/// UI entry point must check the flag BEFORE reading this provider (see
/// `more_screen.dart`'s "Mi Credencial" tile gate).
final credencialApiServiceProvider = Provider<CredencialApiService>((ref) {
  final cfg = ref.watch(tenantConfigProvider);

  if (!cfg.features.credencial) {
    throw StateError(
      'credencialApiServiceProvider accessed but cfg.features.credencial is '
      'false for tenant "${cfg.tenantId}". All credencial providers must '
      'only be read within the credencial feature gate.',
    );
  }

  return CredencialApiService(
    prodeApi: ref.watch(prodeApiServiceProvider),
    credencialApiBaseUrl: '${cfg.apiBaseUrl}/credencial',
  );
});

/// Provides the [CredencialController] and exposes [CredencialUiState] to the
/// "Mi Credencial" feature subtree.
///
/// NOT autoDispose: state persists for the session so navigating away and
/// back does not force a fresh Loading flash — [CredencialScreen] re-runs
/// [CredencialController.open] on every mount regardless (design: "on every
/// app open with connectivity re-GET"), so this only affects the very first
/// frame shown while that re-open is in flight.
final credencialControllerProvider =
    StateNotifierProvider<CredencialController, CredencialUiState>((ref) {
  return CredencialController(
    api: ref.watch(credencialApiServiceProvider),
    repository: ref.watch(credencialRepositoryProvider),
  );
});

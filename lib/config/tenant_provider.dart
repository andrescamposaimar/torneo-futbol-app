import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'tenant_config.dart';

/// Provider that MUST be overridden in the ProviderScope root by a flavor
/// entry point. Accessing it without an override is a programmer error and
/// will throw immediately — there is no implicit default tenant.
final tenantConfigProvider = Provider<TenantConfig>((ref) {
  throw UnimplementedError(
    'tenantConfigProvider must be overridden in ProviderScope. '
    'Use bootstrap(myTenant) to configure the active tenant.',
  );
});

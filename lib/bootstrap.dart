import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:firebase_core/firebase_core.dart';
import 'firebase_options.dart';
import 'config/tenant_config.dart';
import 'config/tenant_provider.dart';
import 'providers/service_providers.dart';
import 'services/config_service.dart';
import 'app.dart';

Future<void> bootstrap(TenantConfig cfg) async {
  // Fail fast: waitingLists=true requires a valid appsScriptUrl.
  if (cfg.features.waitingLists &&
      (cfg.integrations.appsScriptUrl == null ||
          cfg.integrations.appsScriptUrl!.isEmpty)) {
    throw StateError(
      'TenantConfig "${cfg.tenantId}": waitingLists=true but '
      'appsScriptUrl is null or empty. '
      'Provide a valid appsScriptUrl in TenantIntegrations.',
    );
  }

  // Fail fast: prode=true requires a non-null prodeAuth config.
  if (cfg.features.prode && cfg.integrations.prodeAuth == null) {
    throw StateError(
      'TenantConfig "${cfg.tenantId}": features.prode=true but '
      'integrations.prodeAuth is null. '
      'Provide a ProdeAuthConfig in TenantIntegrations.',
    );
  }

  WidgetsFlutterBinding.ensureInitialized();

  await initializeDateFormatting('es');

  await Firebase.initializeApp(
    options: DefaultFirebaseOptions.currentPlatform,
  );

  final overrides = <Override>[
    tenantConfigProvider.overrideWithValue(cfg),
  ];

  // Temporary container for startup operations before runApp.
  // Uses the same overrides so any provider reading tenantConfigProvider
  // during startup resolves the correct tenant.
  final container = ProviderContainer(overrides: overrides);
  await container.read(cacheServiceProvider).clearCacheOncePerWeekWindow();

  final config = await ConfigService.fetchConfig(cfg.mediaBaseUrl);
  if (config != null) {
    await container.read(cacheServiceProvider).applyRemoteConfig(config);
  }

  container.dispose();

  runApp(
    ProviderScope(
      overrides: overrides,
      child: const EntreRedesApp(),
    ),
  );
}

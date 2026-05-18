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

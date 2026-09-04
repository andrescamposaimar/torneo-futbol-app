import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_crashlytics/firebase_crashlytics.dart';
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

  // Every step below is individually guarded: a failure here must never
  // stop runApp() from being reached, or the native splash screen stays up
  // forever with no way for the user to recover (see MainNavigation error
  // state in app.dart for the equivalent guard around fetching startup
  // data after runApp).
  await _guardStep(
    'initializeDateFormatting',
    () => initializeDateFormatting('es'),
    crashlyticsReady: false,
  );

  // Wire Crashlytics as the error sink once Firebase is up. If Firebase
  // itself fails to initialize, there is no Crashlytics to report to, so
  // fall back to debugPrint — but startup must still continue.
  var crashlyticsReady = false;
  try {
    await Firebase.initializeApp(
      options: DefaultFirebaseOptions.currentPlatform,
    );
    // Both handlers swallow their own reporting failures: an error thrown
    // while reporting an error would itself go unhandled.
    FlutterError.onError = (FlutterErrorDetails details) {
      FlutterError.presentError(details);
      try {
        FirebaseCrashlytics.instance.recordFlutterFatalError(details);
      } catch (reportingError) {
        debugPrint('❌ Crashlytics recordFlutterFatalError: $reportingError');
      }
    };
    PlatformDispatcher.instance.onError = (Object error, StackTrace stack) {
      try {
        FirebaseCrashlytics.instance.recordError(error, stack, fatal: true);
      } catch (reportingError) {
        debugPrint('❌ Crashlytics recordError: $reportingError');
      }
      return true;
    };
    crashlyticsReady = true;
  } catch (e, st) {
    debugPrint(
      '❌ bootstrap: Firebase.initializeApp failed, continuing without '
      'Crashlytics: $e\n$st',
    );
  }

  final overrides = <Override>[
    tenantConfigProvider.overrideWithValue(cfg),
  ];

  // Temporary container for startup operations before runApp.
  // Uses the same overrides so any provider reading tenantConfigProvider
  // during startup resolves the correct tenant.
  //
  // Creating and disposing the container are guarded too: they sit between
  // ensureInitialized() and runApp(), so an error escaping here would leave
  // the native splash up forever exactly like an unguarded step would.
  ProviderContainer? container;
  try {
    container = ProviderContainer(overrides: overrides);

    await _guardStep(
      'clearCacheOncePerWeekWindow',
      () => container!.read(cacheServiceProvider).clearCacheOncePerWeekWindow(),
      crashlyticsReady: crashlyticsReady,
    );

    await _guardStep(
      'ConfigService.fetchConfig/applyRemoteConfig',
      () async {
        final config = await ConfigService.fetchConfig(cfg.mediaBaseUrl);
        if (config != null) {
          await container!.read(cacheServiceProvider).applyRemoteConfig(config);
        }
      },
      crashlyticsReady: crashlyticsReady,
    );
  } catch (e, st) {
    debugPrint('❌ bootstrap: startup container work failed: $e\n$st');
  } finally {
    try {
      container?.dispose();
    } catch (e, st) {
      debugPrint('❌ bootstrap: container.dispose() failed: $e\n$st');
    }
  }

  // Unconditional: every path above is guarded so this is always reached.
  runApp(
    ProviderScope(
      overrides: overrides,
      child: const EntreRedesApp(),
    ),
  );
}

/// Runs [step], catching and recording any error instead of letting it
/// propagate — so a single failed startup step can never prevent
/// [runApp] from being reached. Reports to Crashlytics as a non-fatal
/// error when it's available; always logs via [debugPrint] as well.
Future<void> _guardStep(
  String name,
  Future<void> Function() step, {
  required bool crashlyticsReady,
}) async {
  try {
    await step();
  } catch (e, st) {
    debugPrint('❌ bootstrap: startup step "$name" failed: $e\n$st');
    if (crashlyticsReady) {
      // Reporting must never throw a new error out of this handler: that
      // would escape bootstrap() and stop runApp() from being reached,
      // reintroducing the very hang this guard exists to prevent.
      try {
        await FirebaseCrashlytics.instance.recordError(
          e,
          st,
          reason: 'bootstrap step "$name" failed',
          fatal: false,
        );
      } catch (reportingError) {
        debugPrint(
          '❌ bootstrap: step "$name" failed ($e) and could not be reported '
          'to Crashlytics: $reportingError',
        );
      }
    }
  }
}

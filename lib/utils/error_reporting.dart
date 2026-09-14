import 'package:flutter/foundation.dart';
import 'package:firebase_crashlytics/firebase_crashlytics.dart';

/// Reports [error] to Crashlytics without ever throwing.
///
/// Crashlytics may be unavailable (Firebase.initializeApp failed during
/// bootstrap, or this is a test environment with no Firebase app at all),
/// so reporting an error must never raise a new, unhandled one. Every
/// non-fatal report in the app — startup checks, background/optional data
/// fetches — should go through this single, shared, never-throws path.
Future<void> reportNonFatal(
  Object error,
  StackTrace stack,
  String reason,
) async {
  try {
    await FirebaseCrashlytics.instance.recordError(
      error,
      stack,
      reason: reason,
      fatal: false,
    );
  } catch (reportingError) {
    debugPrint('❌ $reason ($error) — not reported: $reportingError');
  }
}

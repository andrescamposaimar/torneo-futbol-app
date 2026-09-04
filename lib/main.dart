import 'package:flutter/foundation.dart';
import 'bootstrap.dart';
import 'config/tenants/marianista.dart';

/// Default entry point — uses the Marianista tenant.
/// `flutter run` with no `-t` flag uses this file.
void main() {
  // bootstrap() only lets the tenant-misconfiguration StateErrors escape
  // (every other startup failure is already caught and recorded inside
  // bootstrap() itself) — this is a last-resort safety net so that Future
  // is never left unawaited and unguarded.
  bootstrap(marianistaTenant).catchError((Object error, StackTrace stack) {
    debugPrint('❌ Unhandled error during startup: $error\n$stack');
  });
}

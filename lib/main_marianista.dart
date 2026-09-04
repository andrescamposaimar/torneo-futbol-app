import 'package:flutter/foundation.dart';
import 'bootstrap.dart';
import 'config/tenants/marianista.dart';

void main() {
  // See main.dart for why this catchError exists.
  bootstrap(marianistaTenant).catchError((Object error, StackTrace stack) {
    debugPrint('❌ Unhandled error during startup: $error\n$stack');
  });
}

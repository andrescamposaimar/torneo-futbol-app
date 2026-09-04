import 'package:flutter/foundation.dart';
import 'bootstrap.dart';
import 'config/tenants/facundo.dart';

void main() {
  // See main.dart for why this catchError exists.
  bootstrap(facundoTenant).catchError((Object error, StackTrace stack) {
    debugPrint('❌ Unhandled error during startup: $error\n$stack');
  });
}

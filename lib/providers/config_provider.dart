import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/app_config.dart';
import '../services/config_service.dart';

/// Provee la configuración remota de la app.
/// El resultado se cachea en memoria por sesión (un solo request HTTP).
final appConfigProvider = FutureProvider<AppConfig?>((ref) {
  return ConfigService.fetchConfig();
});

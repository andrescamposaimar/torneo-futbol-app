import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import '../models/app_config.dart';

/// Servicio para leer la configuración remota desde `configuraciones.json`.
/// Cachea el resultado en memoria para usar un solo request HTTP por sesión.
class ConfigService {
  static AppConfig? _sessionConfig;

  /// Fetcha la configuración remota.
  /// [mediaBaseUrl] debe ser el baseUrl de media del tenant (sin trailing slash),
  /// e.g. `https://host.com/wp-content/uploads/media`.
  /// Si ya fue fetched en esta sesión, retorna el valor en memoria.
  /// Retorna null ante cualquier error de red o parseo.
  static Future<AppConfig?> fetchConfig(String mediaBaseUrl) async {
    if (_sessionConfig != null) return _sessionConfig;
    final url = '$mediaBaseUrl/configuraciones.json';
    try {
      final res = await http
          .get(Uri.parse(url))
          .timeout(const Duration(seconds: 5));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body) as Map<String, dynamic>;
        _sessionConfig = AppConfig.fromJson(data);
        return _sessionConfig;
      }
    } catch (e) {
      debugPrint('❌ ConfigService.fetchConfig error: $e');
    }
    return null;
  }

  /// Limpia la caché en memoria (útil para tests y hot restart en dev).
  static void resetSessionCache() => _sessionConfig = null;
}

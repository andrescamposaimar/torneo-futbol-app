import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import '../models/app_config.dart';

/// Servicio para leer la configuración remota desde `configuraciones.json`.
/// Cachea el resultado en memoria para usar un solo request HTTP por sesión.
class ConfigService {
  static const _prodUrl =
      'https://entreredespadres.com.ar/wp-content/uploads/media/configuraciones.json';

  static String get _url => _prodUrl;

  static AppConfig? _sessionConfig;

  /// Fetcha la configuración remota.
  /// Si ya fue fetched en esta sesión, retorna el valor en memoria.
  /// Retorna null ante cualquier error de red o parseo.
  static Future<AppConfig?> fetchConfig() async {
    if (_sessionConfig != null) return _sessionConfig;
    try {
      final res = await http
          .get(Uri.parse(_url))
          .timeout(const Duration(seconds: 5));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body) as Map<String, dynamic>;
        _sessionConfig = AppConfig.fromJson(data);
        return _sessionConfig;
      }
    } catch (_) {}
    return null;
  }

  /// Limpia la caché en memoria (útil para tests y hot restart en dev).
  static void resetSessionCache() => _sessionConfig = null;
}

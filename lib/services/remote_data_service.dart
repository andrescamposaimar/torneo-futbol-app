import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'i_api_service.dart';
import 'i_cache_service.dart';
import 'api_service.dart';
import 'cache_service.dart';

class RemoteDataService {
  static const _adsUrl = 'https://entreredespadres.com.ar/wp-content/uploads/media/publicidades.json';
  static const _listasUrl = 'https://entreredespadres.com.ar/wp-content/uploads/media/listas_jugadores.json';

  static Future<Map<String, String>> fetchAdImages() async {
    try {
      final res = await http.get(Uri.parse(_adsUrl));
      if (res.statusCode == 200) {
        final data = json.decode(res.body);
        return {
          'estadisticas': data['estadisticas_ad'] ?? '',
          'alineaciones': data['alineaciones_ad'] ?? '',
          'jugadores': data['jugadores_ad'] ?? '',
          'equipos': data['equipos_ad'] ?? '',
          'tabla': data['tabla_ad'] ?? '',
          'goleadores': data['goleadores_ad'] ?? '',
          'imbatibles': data['imbatibles_ad'] ?? '',
          'zocalo': data['zocalo_ad'] ?? '',
        };
      }
    } catch (e) {
      debugPrint('❌ Error al cargar publicidades: $e');
    }
    return {
      'estadisticas': '',
      'alineaciones': '',
      'jugadores': '',
      'equipos': '',
      'tabla': ''
    };
  }

  static Future<Map<String, List<int>>> fetchListasJugadores() async {
    try {
      final res = await http.get(Uri.parse(_listasUrl));
      if (res.statusCode == 200) {
        final data = json.decode(res.body);
        return {
          'espera': List<int>.from(data['lista_espera'] ?? []),
          'reserva': List<int>.from(data['lista_reserva'] ?? []),
          'no_inscriptos': List<int>.from(data['lista_no_inscriptos'] ?? []),
        };
      }
    } catch (e) {
      debugPrint('❌ Error al cargar listas de jugadores: $e');
    }
    return {'espera': [], 'reserva': [], 'no_inscriptos': []};
  }

  static Future<int?> getTemporadaIdPorNombre(
    String nombre, {
    IApiService? api,
    ICacheService? cache,
  }) async {
    final effectiveApi = api ?? ApiService();
    final effectiveCache = cache ?? CacheService();

    final cached = await effectiveCache.getCachedTemporadas();
    if (cached != null) {
      final match = cached.firstWhere(
        (t) => t['name'].toString().contains(nombre),
        orElse: () => null,
      );
      if (match != null) return match['id'];
    }

    final nuevas = await effectiveApi.getTemporadas();
    await effectiveCache.cacheTemporadas(nuevas);

    final match = nuevas.firstWhere(
      (t) => t['name'].toString().contains(nombre),
      orElse: () => null,
    );
    if (match != null) return match['id'];

    return null;
  }

  static Future<List<AdItem>> fetchZocaloAds() async {
    try {
      final res = await http.get(Uri.parse(_adsUrl));
      if (res.statusCode == 200) {
        final data = json.decode(res.body);
        final List<dynamic> items = data['zocalo_ads'] ?? [];
        return items
            .map((item) => AdItem(
                  imageUrl: item['image'] ?? '',
                  link: item['link'] ?? '',
                ))
            .where((ad) => ad.imageUrl.isNotEmpty && ad.link.isNotEmpty)
            .toList();
      }
    } catch (e) {
      debugPrint('❌ Error al cargar zócalo carrusel: $e');
    }
    return [];
  }
}

class AdItem {
  final String imageUrl;
  final String link;

  const AdItem({required this.imageUrl, required this.link});
}

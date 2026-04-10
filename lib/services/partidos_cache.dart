import 'i_cache_service.dart';
import 'i_api_service.dart';

class PartidosCache {
  final IApiService _api;
  final ICacheService _cache;

  PartidosCache({required IApiService api, required ICacheService cache})
      : _api = api,
        _cache = cache;

  List<dynamic> partidosJugados = [];

  Future<List<dynamic>> getPartidosJugados(int temporadaId) async {
    if (partidosJugados.isNotEmpty) return partidosJugados;

    // 1. Buscar en SharedPreferences
    final cached = await _cache.getCachedPartidosJugadosPorTemporada(temporadaId);
    if (cached != null) {
      partidosJugados = cached;
      return partidosJugados;
    }

    // 2. Si no está cacheado, traer de la API
    final res = await _api.getPartidos(
      temporada: temporadaId,
      page: 1,
      perPage: 500,
    );
    partidosJugados = res['items']?.where((p) => p['status'] == 'publish').toList() ?? [];

    // Guardar en cache persistente
    await _cache.cachePartidosJugadosPorTemporada(temporadaId, partidosJugados);

    return partidosJugados;
  }
}

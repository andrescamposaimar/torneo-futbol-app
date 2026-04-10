import '../services/i_api_service.dart';
import '../services/i_cache_service.dart';

class ApiRepository {
  final IApiService _api;
  final ICacheService _cache;

  ApiRepository({required IApiService api, required ICacheService cache})
      : _api = api,
        _cache = cache;

  // --- Temporadas ---

  Future<List<dynamic>> getTemporadas() async {
    final cached = await _cache.getCachedTemporadas();
    if (cached != null && cached.any((t) => t['is_current'] == true)) return cached;
    final data = await _api.getTemporadas();
    await _cache.cacheTemporadas(data);
    return data;
  }

  // --- Goleadores ---

  /// Carga completa con caché integrada (primer load o cuando caché expiró).
  Future<List<dynamic>> getScorers(int temporadaId) async {
    final cached = await _cache.getCachedScorersPorTemporada(temporadaId);
    if (cached != null) return cached;
    final result = await _api.getTablaGoleadores(
      temporada: temporadaId,
      page: 1,
      perPage: 200,
    );
    final items = List<dynamic>.from(result['items'] ?? []);
    await _cache.cacheScorersPorTemporada(temporadaId, items);
    return items;
  }

  /// Paginado sin caché, para infinite scroll.
  Future<Map<String, dynamic>> getScorersPage({
    int? temporadaId,
    int page = 1,
    int perPage = 50,
  }) async {
    return _api.getTablaGoleadores(
      temporada: temporadaId,
      page: page,
      perPage: perPage,
    );
  }

  // --- Imbatibles ---

  /// Carga completa con caché integrada (primer load o cuando caché expiró).
  Future<List<dynamic>> getImbatibles(int temporadaId) async {
    final cached = await _cache.getCachedImbatiblesPorTemporada(temporadaId);
    if (cached != null) return cached;
    final result = await _api.getTablaImbatibles(
      temporada: temporadaId,
      page: 1,
      perPage: 200,
    );
    final items = List<dynamic>.from(result['items'] ?? []);
    await _cache.cacheImbatiblesPorTemporada(temporadaId, items);
    return items;
  }

  /// Paginado sin caché, para infinite scroll.
  Future<Map<String, dynamic>> getImbatiblesPage({
    required int temporadaId,
    int page = 1,
    int perPage = 10,
  }) async {
    return _api.getTablaImbatibles(
      temporada: temporadaId,
      page: page,
      perPage: perPage,
    );
  }

  // --- Tablas de posiciones ---

  Future<Map<String, dynamic>> getTablas({
    int? temporada,
    int? zona,
    String? search,
    int page = 1,
    int perPage = 50,
  }) async {
    return _api.getTablas(
      temporada: temporada?.toString(),
      zona: zona?.toString(),
      search: search,
      page: page,
      perPage: perPage,
    );
  }

  // --- Ligas y zonas ---

  Future<List<dynamic>> getLigas({int? temporada}) =>
      _api.getLigas(temporada: temporada);

  Future<List<dynamic>> getZonas({int? liga}) =>
      _api.getZonas(liga: liga);
}

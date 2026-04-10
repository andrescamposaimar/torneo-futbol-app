import '../services/i_cache_service.dart';

class CacheRepository {
  final ICacheService _cache;

  CacheRepository({required ICacheService cache}) : _cache = cache;

  Future<void> clearAll() => _cache.clearAllCaches();

  Future<void> clearScorers() => _cache.clearScorersCache();

  Future<void> clearScorersForSeason(int temporadaId) =>
      _cache.clearScorersTemporadaCache(temporadaId);

  Future<void> clearOncePerWeekWindow() => _cache.clearCacheOncePerWeekWindow();

  Future<void> clearPlayersCurrentSeasonAll() => _cache.clearPlayersCurrentSeasonAll();

  Future<void> clearStandingsCacheAll() => _cache.clearStandingsCacheAll();

  Future<void> clearScorersCacheAll() => _cache.clearScorersCacheAll();

  Future<void> clearImbatiblesCacheAll() => _cache.clearImbatiblesCacheAll();
}

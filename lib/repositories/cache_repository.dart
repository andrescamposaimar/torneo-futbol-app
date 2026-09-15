import '../services/i_cache_service.dart';

class CacheRepository {
  final ICacheService _cache;

  CacheRepository({required ICacheService cache}) : _cache = cache;

  Future<void> clearAll() => _cache.clearAllCaches();
}

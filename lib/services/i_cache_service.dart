import '../models/app_config.dart';

abstract class ICacheService {
  // 🔹 Configuración remota: versiones de caché y TTL
  Future<String?> getRemoteCacheVersion(String entity);
  Future<void> saveRemoteCacheVersion(String entity, String version);
  Future<int> getCacheTtlDays();
  Future<void> saveCacheTtlDays(int days);
  Future<void> applyRemoteConfig(AppConfig config);

  // 🔹 Clear por entidad (todas las claves con ese prefijo)
  Future<void> clearPlayersCurrentSeasonAll();
  Future<void> clearStandingsCacheAll();
  Future<void> clearScorersCacheAll();
  Future<void> clearImbatiblesCacheAll();

  Future<void> cacheTemporadas(List<dynamic> temporadas);
  Future<List<dynamic>?> getCachedTemporadas();

  Future<void> cachePlayers(List<dynamic> players);
  Future<List<dynamic>?> getCachedPlayers();

  Future<void> cachePlayersTemporada(List<dynamic> players);
  Future<List<dynamic>?> getCachedPlayersTemporada();

  Future<void> cachePlayersHistoricos(List<dynamic> players);
  Future<List<dynamic>?> getCachedPlayersHistoricos();

  Future<void> cacheScorers(List<dynamic> scorers, [int? temporadaId]);
  Future<List<dynamic>?> getCachedScorers([int? temporadaId]);

  Future<void> cacheScorersGeneral(List<dynamic> scorers);
  Future<List<dynamic>?> getCachedScorersGeneral();

  Future<void> cacheScorersPorTemporada(int temporadaId, List<dynamic> scorers);
  Future<List<dynamic>?> getCachedScorersPorTemporada(int temporadaId);

  Future<void> cacheImbatiblesPorTemporada(int temporadaId, List<dynamic> arqueros);
  Future<List<dynamic>?> getCachedImbatiblesPorTemporada(int temporadaId);

  Future<List<dynamic>?> getCachedPlayersPorEquipo(int equipoId);

  Future<void> cachePlayersCurrentSeason(int temporadaId, List<dynamic> players);
  Future<List<dynamic>?> getCachedPlayersCurrentSeason(int temporadaId);

  Future<void> cachePartidosJugadosPorTemporada(int temporadaId, List<dynamic> partidos);
  Future<List<dynamic>?> getCachedPartidosJugadosPorTemporada(int temporadaId);

  Future<void> clearScorersCache();
  Future<void> clearScorersTemporadaCache(int temporadaId);
  Future<void> clearAllCaches();
  Future<void> clearCacheOncePerWeekWindow();
}

import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:flutter/foundation.dart';
import 'i_cache_service.dart';
import '../models/app_config.dart';

class CacheService implements ICacheService {
  static const int _defaultCacheDays = 7;
  static const String _cacheTtlKey = 'config_cache_ttl_days';

  SharedPreferences? _prefs;
  Future<SharedPreferences> get _sharedPrefs async =>
      _prefs ??= await SharedPreferences.getInstance();

  /// TTL efectivo: usa el valor guardado por config remota, o 7 días por defecto.
  Future<Duration> get _effectiveCacheDuration async {
    final days = await getCacheTtlDays();
    return Duration(days: days);
  }

  /// Reads a cached JSON blob under [key], decodes it, and hands the
  /// decoded map to [extract] to pull out the cached payload.
  ///
  /// Guards against corrupt or legacy-shaped cached data: a decode
  /// failure, a non-map payload, a missing/non-int timestamp, or a bad
  /// cast inside [extract] is treated as a cache miss (returns `null`)
  /// and removes the offending key so it does not keep failing on every
  /// read.
  ///
  /// [ttl] controls freshness: `null` means "ignore expiration and
  /// return the cached value regardless of age" (used for offline
  /// fallbacks); otherwise entries older than [ttl] are treated as
  /// expired and return `null` without being removed.
  Future<List<dynamic>?> _readCachedList(
    String key, {
    required Duration? ttl,
    required List<dynamic> Function(Map<String, dynamic> decoded) extract,
  }) async {
    final prefs = await _sharedPrefs;
    final raw = prefs.getString(key);
    if (raw == null) return null;

    try {
      final decoded = jsonDecode(raw);
      if (decoded is! Map<String, dynamic>) {
        throw const FormatException('cached entry is not a JSON object');
      }
      final timestamp = decoded['timestamp'];
      if (timestamp is! int) {
        throw const FormatException('cached entry has no valid timestamp');
      }
      if (ttl != null) {
        final now = DateTime.now().millisecondsSinceEpoch;
        if ((now - timestamp) >= ttl.inMilliseconds) return null;
      }
      return extract(decoded);
    } catch (e) {
      debugPrint('⚠️ Caché corrupta en "$key", se descarta: $e');
      await prefs.remove(key);
      return null;
    }
  }

  // 🔹 Players Cache (General / Temporada / Históricos)
  static const String _playersCacheKey = 'cached_players';
  static const String _playersTemporadaCacheKey = 'cached_players_temporada';
  static const String _playersHistoricosCacheKey = 'cached_players_historicos';
  // 🔹 Temporadas Cache
  static const String _temporadasCacheKey = 'cached_temporadas';
  // 🔹 Noticias Cache
  static const String _noticiasCacheKey = 'cached_noticias';

  // ─────────────────────────────────────────────────────────────
  // 🔹 Configuración remota: versiones de caché y TTL
  // ─────────────────────────────────────────────────────────────

  @override
  Future<String?> getRemoteCacheVersion(String entity) async {
    final prefs = await _sharedPrefs;
    return prefs.getString('config_version_$entity');
  }

  @override
  Future<void> saveRemoteCacheVersion(String entity, String version) async {
    final prefs = await _sharedPrefs;
    await prefs.setString('config_version_$entity', version);
  }

  @override
  Future<int> getCacheTtlDays() async {
    final prefs = await _sharedPrefs;
    return prefs.getInt(_cacheTtlKey) ?? _defaultCacheDays;
  }

  @override
  Future<void> saveCacheTtlDays(int days) async {
    final prefs = await _sharedPrefs;
    await prefs.setInt(_cacheTtlKey, days);
  }

  /// Aplica la configuración remota: invalida cachés cuya versión cambió
  /// y actualiza el TTL si se especificó.
  @override
  Future<void> applyRemoteConfig(AppConfig config) async {
    final checks = {
      'players': (config.playersCacheVersion, clearPlayersCurrentSeasonAll),
      'standings': (config.standingsCacheVersion, clearStandingsCacheAll),
      'scorers': (config.scorersCacheVersion, clearScorersCacheAll),
      'imbatibles': (config.imbatiblesCacheVersion, clearImbatiblesCacheAll),
    };

    for (final entry in checks.entries) {
      final entity = entry.key;
      final (remoteVersion, clearFn) = entry.value;
      final localVersion = await getRemoteCacheVersion(entity);
      if (localVersion != remoteVersion) {
        await clearFn();
        await saveRemoteCacheVersion(entity, remoteVersion);
        debugPrint('🔄 Caché de $entity invalidada (v$localVersion → v$remoteVersion)');
      }
    }

    if (config.cacheTtlDays != null) {
      await saveCacheTtlDays(config.cacheTtlDays!);
    }
  }

  // ─────────────────────────────────────────────────────────────
  // 🔹 Clear por entidad (por prefijo de clave)
  // ─────────────────────────────────────────────────────────────

  @override
  Future<void> clearPlayersCurrentSeasonAll() async {
    final prefs = await _sharedPrefs;
    final keys = prefs.getKeys().where((k) => k.startsWith('cached_players_current_')).toList();
    for (final key in keys) await prefs.remove(key);
  }

  @override
  Future<void> clearStandingsCacheAll() async {
    final prefs = await _sharedPrefs;
    final keys = prefs.getKeys().where((k) => k.startsWith('cache_tablas_')).toList();
    for (final key in keys) await prefs.remove(key);
  }

  @override
  Future<void> clearScorersCacheAll() async {
    final prefs = await _sharedPrefs;
    await prefs.remove(_scorersCacheKey);
    final keys = prefs.getKeys().where((k) => k.startsWith('cached_scorers_')).toList();
    for (final key in keys) await prefs.remove(key);
  }

  @override
  Future<void> clearImbatiblesCacheAll() async {
    final prefs = await _sharedPrefs;
    final keys = prefs.getKeys().where((k) => k.startsWith('cached_imbatibles_')).toList();
    for (final key in keys) await prefs.remove(key);
  }

  // ─────────────────────────────────────────────────────────────
  // 🔹 Temporadas
  // ─────────────────────────────────────────────────────────────

  @override
  Future<void> cacheTemporadas(List<dynamic> temporadas) async {
    final prefs = await _sharedPrefs;
    final cacheData = {
      'timestamp': DateTime.now().millisecondsSinceEpoch,
      'data': temporadas,
    };
    await prefs.setString(_temporadasCacheKey, jsonEncode(cacheData));
  }

  @override
  Future<List<dynamic>?> getCachedTemporadas() async {
    return _readCachedList(
      _temporadasCacheKey,
      ttl: await _effectiveCacheDuration,
      extract: (decoded) => List<dynamic>.from(decoded['data']),
    );
  }

  /// Same as [getCachedTemporadas] but ignores the TTL — returns the
  /// cached temporadas even if stale, as an offline fallback when the
  /// network is unreachable. Returns `null` when there is no cache at
  /// all (or it is corrupt).
  @override
  Future<List<dynamic>?> getCachedTemporadasIgnoringTtl() => _readCachedList(
        _temporadasCacheKey,
        ttl: null,
        extract: (decoded) => List<dynamic>.from(decoded['data']),
      );

  // ─────────────────────────────────────────────────────────────
  // 🔹 Players (General / Temporada / Históricos)
  // ─────────────────────────────────────────────────────────────

  @override
  Future<void> cachePlayers(List<dynamic> players) async {
    final prefs = await _sharedPrefs;
    final cacheData = {
      'timestamp': DateTime.now().millisecondsSinceEpoch,
      'players': players,
    };
    await prefs.setString(_playersCacheKey, jsonEncode(cacheData));
  }

  @override
  Future<List<dynamic>?> getCachedPlayers() async {
    return _readCachedList(
      _playersCacheKey,
      ttl: await _effectiveCacheDuration,
      extract: (decoded) => List<dynamic>.from(decoded['players']),
    );
  }

  @override
  Future<void> cachePlayersTemporada(List<dynamic> players) async {
    final prefs = await _sharedPrefs;
    final cacheData = {
      'timestamp': DateTime.now().millisecondsSinceEpoch,
      'players': players,
    };
    await prefs.setString(_playersTemporadaCacheKey, jsonEncode(cacheData));
  }

  @override
  Future<List<dynamic>?> getCachedPlayersTemporada() async {
    return _readCachedList(
      _playersTemporadaCacheKey,
      ttl: await _effectiveCacheDuration,
      extract: (decoded) => List<dynamic>.from(decoded['players']),
    );
  }

  @override
  Future<void> cachePlayersHistoricos(List<dynamic> players) async {
    final prefs = await _sharedPrefs;
    final cacheData = {
      'timestamp': DateTime.now().millisecondsSinceEpoch,
      'players': players,
    };
    await prefs.setString(_playersHistoricosCacheKey, jsonEncode(cacheData));
  }

  @override
  Future<List<dynamic>?> getCachedPlayersHistoricos() async {
    return _readCachedList(
      _playersHistoricosCacheKey,
      ttl: await _effectiveCacheDuration,
      extract: (decoded) => List<dynamic>.from(decoded['players']),
    );
  }

  // ─────────────────────────────────────────────────────────────
  // 🔹 Scorers Cache (General y por temporada)
  // ─────────────────────────────────────────────────────────────

  static const String _scorersCacheKey = 'cached_scorers';
  static String _scorersTemporadaKey(int temporadaId) => 'cached_scorers_$temporadaId';

  @override
  Future<void> cacheScorers(List<dynamic> scorers, [int? temporadaId]) {
    if (temporadaId != null) {
      return cacheScorersPorTemporada(temporadaId, scorers);
    } else {
      return cacheScorersGeneral(scorers);
    }
  }

  @override
  Future<List<dynamic>?> getCachedScorers([int? temporadaId]) {
    if (temporadaId != null) {
      return getCachedScorersPorTemporada(temporadaId);
    } else {
      return getCachedScorersGeneral();
    }
  }

  @override
  Future<void> cacheScorersGeneral(List<dynamic> scorers) async {
    final prefs = await _sharedPrefs;
    final cacheData = {
      'timestamp': DateTime.now().millisecondsSinceEpoch,
      'scorers': scorers,
    };
    await prefs.setString(_scorersCacheKey, jsonEncode(cacheData));
  }

  @override
  Future<List<dynamic>?> getCachedScorersGeneral() async {
    return _readCachedList(
      _scorersCacheKey,
      ttl: await _effectiveCacheDuration,
      extract: (decoded) => List<dynamic>.from(decoded['scorers']),
    );
  }

  @override
  Future<void> cacheScorersPorTemporada(int temporadaId, List<dynamic> scorers) async {
    final prefs = await _sharedPrefs;
    final cacheData = {
      'timestamp': DateTime.now().millisecondsSinceEpoch,
      'scorers': scorers,
    };
    await prefs.setString(_scorersTemporadaKey(temporadaId), jsonEncode(cacheData));
  }

  @override
  Future<List<dynamic>?> getCachedScorersPorTemporada(int temporadaId) async {
    return _readCachedList(
      _scorersTemporadaKey(temporadaId),
      ttl: await _effectiveCacheDuration,
      extract: (decoded) => List<dynamic>.from(decoded['scorers']),
    );
  }

  @override
  Future<void> clearScorersCache() async {
    final prefs = await _sharedPrefs;
    await prefs.remove(_scorersCacheKey);
  }

  @override
  Future<void> clearScorersTemporadaCache(int temporadaId) async {
    final prefs = await _sharedPrefs;
    await prefs.remove(_scorersTemporadaKey(temporadaId));
  }

  // ─────────────────────────────────────────────────────────────
  // 🔹 Noticias Cache (TTL corto: 1 hora)
  // ─────────────────────────────────────────────────────────────

  @override
  Future<void> cacheNoticias(List<dynamic> noticias) async {
    final prefs = await _sharedPrefs;
    final cacheData = {
      'timestamp': DateTime.now().millisecondsSinceEpoch,
      'noticias': noticias,
    };
    await prefs.setString(_noticiasCacheKey, jsonEncode(cacheData));
  }

  @override
  Future<List<dynamic>?> getCachedNoticias() async {
    // TTL de 1 hora para noticias (se actualizan más frecuentemente)
    return _readCachedList(
      _noticiasCacheKey,
      ttl: const Duration(hours: 1),
      extract: (decoded) => List<dynamic>.from(decoded['noticias']),
    );
  }

  @override
  Future<void> clearNoticiasCache() async {
    final prefs = await _sharedPrefs;
    await prefs.remove(_noticiasCacheKey);
  }

  // ─────────────────────────────────────────────────────────────
  // 🔹 Clear all caches
  // ─────────────────────────────────────────────────────────────

  @override
  Future<void> clearAllCaches() async {
    final prefs = await _sharedPrefs;

    // Eliminar claves estáticas
    await prefs.remove(_playersCacheKey);
    await prefs.remove(_playersTemporadaCacheKey);
    await prefs.remove(_playersHistoricosCacheKey);
    await prefs.remove(_scorersCacheKey);
    await prefs.remove(_temporadasCacheKey);
    await prefs.remove(_noticiasCacheKey);
    await prefs.remove('cache_partidos_jugados');
    await prefs.remove('cache_partidos_futuros');

    // Eliminar claves dinámicas por prefijo
    final keys = prefs.getKeys();
    for (final key in keys) {
      if (key.startsWith('cached_scorers_') ||
          key.startsWith('cached_imbatibles_') ||
          key.startsWith('cache_equipos_') ||
          key.startsWith('cache_partidos_') ||
          key.startsWith('cache_tablas_') ||
          key.startsWith('cached_partidos_jugados_') ||
          key.startsWith('cached_players_equipo_') ||
          key.startsWith('cached_players_current_')) {
        await prefs.remove(key);
      }
    }
  }

  // ─────────────────────────────────────────────────────────────
  // 🔹 Imbatibles Cache (por temporada)
  // ─────────────────────────────────────────────────────────────

  static String _imbatiblesTemporadaKey(int temporadaId) => 'cached_imbatibles_$temporadaId';

  @override
  Future<void> cacheImbatiblesPorTemporada(int temporadaId, List<dynamic> arqueros) async {
    final prefs = await _sharedPrefs;
    final cacheData = {
      'timestamp': DateTime.now().millisecondsSinceEpoch,
      'arqueros': arqueros,
    };
    await prefs.setString(_imbatiblesTemporadaKey(temporadaId), jsonEncode(cacheData));
  }

  @override
  Future<List<dynamic>?> getCachedImbatiblesPorTemporada(int temporadaId) async {
    return _readCachedList(
      _imbatiblesTemporadaKey(temporadaId),
      ttl: await _effectiveCacheDuration,
      extract: (decoded) => List<dynamic>.from(decoded['arqueros']),
    );
  }

  // ─────────────────────────────────────────────────────────────
  // 🔹 Players Cache (por temporada actual)
  // ─────────────────────────────────────────────────────────────

  static String _playersCurrentSeasonKey(int temporadaId) =>
      'cached_players_current_$temporadaId';

  @override
  Future<void> cachePlayersCurrentSeason(
      int temporadaId, List<dynamic> players) async {
    final prefs = await _sharedPrefs;
    final cacheData = {
      'timestamp': DateTime.now().millisecondsSinceEpoch,
      'players': players,
    };
    await prefs.setString(
        _playersCurrentSeasonKey(temporadaId), jsonEncode(cacheData));
  }

  @override
  Future<List<dynamic>?> getCachedPlayersCurrentSeason(int temporadaId) async {
    return _readCachedList(
      _playersCurrentSeasonKey(temporadaId),
      ttl: await _effectiveCacheDuration,
      extract: (decoded) => List<dynamic>.from(decoded['players']),
    );
  }

  @override
  Future<List<dynamic>?> getCachedPlayersPorEquipo(int equipoId) async {
    return _readCachedList(
      'cached_players_equipo_$equipoId',
      ttl: const Duration(days: 3),
      extract: (decoded) => List<dynamic>.from(decoded['players']),
    );
  }

  // ─────────────────────────────────────────────────────────────
  // 🔹 Limpieza semanal automática
  // ─────────────────────────────────────────────────────────────

  @override
  Future<void> clearCacheOncePerWeekWindow() async {
    final prefs = await _sharedPrefs;
    final now = DateTime.now();
    final lastClearIso = prefs.getString('ultima_limpieza_cache');
    final lastClear = lastClearIso != null ? DateTime.tryParse(lastClearIso) : null;

    // 📅 Buscar el último sábado a las 21:00 antes de ahora
    DateTime ultimoSabado = now.subtract(Duration(days: (now.weekday % 7)));
    ultimoSabado = DateTime(
      ultimoSabado.year,
      ultimoSabado.month,
      ultimoSabado.day,
      21,
    );

    if (now.isBefore(ultimoSabado)) {
      ultimoSabado = ultimoSabado.subtract(const Duration(days: 7));
    }

    if (lastClear == null || lastClear.isBefore(ultimoSabado)) {
      await prefs.remove(_playersCacheKey);
      await prefs.remove(_playersTemporadaCacheKey);
      await prefs.remove(_playersHistoricosCacheKey);
      await prefs.remove(_temporadasCacheKey);
      await prefs.remove(_noticiasCacheKey);

      final keys = prefs.getKeys();
      for (final key in keys) {
        if (key.startsWith('cached_scorers_') ||
            key.startsWith('cached_imbatibles_') ||
            key.startsWith('cache_equipos_') ||
            key.startsWith('cache_partidos_') ||
            key.startsWith('cache_tablas_') ||
            key.startsWith('cached_partidos_jugados_') ||
            key.startsWith('cached_players_equipo_') ||
            key.startsWith('cached_players_current_')) {
          await prefs.remove(key);
        }
      }

      await prefs.setString('ultima_limpieza_cache', now.toIso8601String());
      debugPrint('🧹 Caché eliminada en la ventana semanal post sábado 19h');
    }
  }

  // ─────────────────────────────────────────────────────────────
  // 🔹 Partidos jugados por temporada
  // ─────────────────────────────────────────────────────────────

  static String _partidosJugadosTemporadaKey(int temporadaId) =>
      'cached_partidos_jugados_$temporadaId';

  @override
  Future<void> cachePartidosJugadosPorTemporada(
      int temporadaId, List<dynamic> partidos) async {
    final prefs = await _sharedPrefs;
    final cacheData = {
      'timestamp': DateTime.now().millisecondsSinceEpoch,
      'partidos': partidos,
    };
    await prefs.setString(
        _partidosJugadosTemporadaKey(temporadaId), jsonEncode(cacheData));
  }

  @override
  Future<List<dynamic>?> getCachedPartidosJugadosPorTemporada(
      int temporadaId) async {
    return _readCachedList(
      _partidosJugadosTemporadaKey(temporadaId),
      ttl: await _effectiveCacheDuration,
      extract: (decoded) => List<dynamic>.from(decoded['partidos']),
    );
  }
}

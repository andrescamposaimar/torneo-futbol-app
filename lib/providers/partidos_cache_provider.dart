import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../services/partidos_cache.dart';
import 'service_providers.dart';

final partidosCacheProvider = Provider<PartidosCache>((ref) {
  return PartidosCache(
    api: ref.read(apiServiceProvider),
    cache: ref.read(cacheServiceProvider),
  );
});

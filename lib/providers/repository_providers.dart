import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../repositories/api_repository.dart';
import '../repositories/cache_repository.dart';
import 'service_providers.dart';

final apiRepositoryProvider = Provider<ApiRepository>((ref) {
  return ApiRepository(
    api: ref.read(apiServiceProvider),
    cache: ref.read(cacheServiceProvider),
  );
});

final cacheRepositoryProvider = Provider<CacheRepository>((ref) {
  return CacheRepository(cache: ref.read(cacheServiceProvider));
});

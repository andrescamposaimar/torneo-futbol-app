import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../services/i_api_service.dart';
import '../services/i_cache_service.dart';
import '../services/api_service.dart';
import '../services/cache_service.dart';

final apiServiceProvider = Provider<IApiService>((ref) => ApiService());
final cacheServiceProvider = Provider<ICacheService>((ref) => CacheService());

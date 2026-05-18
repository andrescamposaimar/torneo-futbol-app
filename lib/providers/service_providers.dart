import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../config/tenant_provider.dart';
import '../services/i_api_service.dart';
import '../services/i_cache_service.dart';
import '../services/api_service.dart';
import '../services/cache_service.dart';
import '../services/notification_service.dart';
import '../services/remote_data_service.dart';

final apiServiceProvider = Provider<IApiService>((ref) {
  final cfg = ref.watch(tenantConfigProvider);
  return ApiService(baseUrl: cfg.apiBaseUrl);
});

final cacheServiceProvider = Provider<ICacheService>((ref) => CacheService());

final notificationServiceProvider =
    Provider<NotificationService>((ref) => NotificationService());

final remoteDataServiceProvider = Provider<RemoteDataService>((ref) {
  final cfg = ref.watch(tenantConfigProvider);
  return RemoteDataService(mediaBaseUrl: cfg.mediaBaseUrl);
});

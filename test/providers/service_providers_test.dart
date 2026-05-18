import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/tenant_config.dart';
import 'package:torneo_futbol_app/config/tenant_provider.dart';
import 'package:torneo_futbol_app/providers/service_providers.dart';
import 'package:torneo_futbol_app/services/api_service.dart';
import 'package:torneo_futbol_app/services/remote_data_service.dart';

const _testTenant = TenantConfig(
  tenantId: 'test',
  appName: 'Test App',
  apiBaseUrl: 'https://test.example.com/wp-json/entre-redes/v1',
  mediaBaseUrl: 'https://test.example.com/wp-content/uploads/media',
  colors: BrandColors(
    primary: Color(0xFF123456),
    accent: Color(0xFF654321),
    splashBackground: Color(0xFF123456),
  ),
  features: TenantFeatures(),
  logoAsset: 'assets/images/app_logo.png',
  documents: TenantDocuments(),
);

void main() {
  group('service providers', () {
    late ProviderContainer container;

    setUp(() {
      container = ProviderContainer(
        overrides: [
          tenantConfigProvider.overrideWithValue(_testTenant),
        ],
      );
    });

    tearDown(() => container.dispose());

    test('apiServiceProvider creates ApiService with the tenant apiBaseUrl', () {
      final service = container.read(apiServiceProvider);

      expect(service, isA<ApiService>());
      expect((service as ApiService).baseUrl, equals(_testTenant.apiBaseUrl));
    });

    test(
        'remoteDataServiceProvider creates RemoteDataService with correct mediaBaseUrl and apiBaseUrl',
        () {
      final service = container.read(remoteDataServiceProvider);

      expect(service, isA<RemoteDataService>());
      expect(service.mediaBaseUrl, equals(_testTenant.mediaBaseUrl));
      expect(service.apiBaseUrl, equals(_testTenant.apiBaseUrl));
    });
  });
}

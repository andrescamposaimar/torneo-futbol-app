import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/tenant_config.dart';
import 'package:torneo_futbol_app/config/tenant_provider.dart';

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
  group('tenantConfigProvider', () {
    test('throws UnimplementedError when not overridden', () {
      final container = ProviderContainer();
      addTearDown(container.dispose);

      expect(
        () => container.read(tenantConfigProvider),
        throwsA(isA<UnimplementedError>()),
      );
    });

    test('returns the correct value when overridden via ProviderContainer', () {
      final container = ProviderContainer(
        overrides: [
          tenantConfigProvider.overrideWithValue(_testTenant),
        ],
      );
      addTearDown(container.dispose);

      final config = container.read(tenantConfigProvider);

      expect(config.tenantId, equals('test'));
      expect(config.appName, equals('Test App'));
      expect(config.apiBaseUrl,
          equals('https://test.example.com/wp-json/entre-redes/v1'));
    });
  });
}

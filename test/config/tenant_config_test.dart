import 'package:flutter_test/flutter_test.dart';
import 'package:flutter/material.dart';
import 'package:torneo_futbol_app/config/tenant_config.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/config/tenants/marianista.dart';
import 'package:torneo_futbol_app/config/tenants/facundo.dart';

void main() {
  group('TenantFeatures', () {
    test('prode defaults to false', () {
      const features = TenantFeatures();
      expect(features.prode, isFalse);
    });

    test('prode can be set to true', () {
      const features = TenantFeatures(prode: true);
      expect(features.prode, isTrue);
    });

    test('existing defaults are unchanged', () {
      const features = TenantFeatures();
      expect(features.waitingLists, isFalse);
      expect(features.newsTab, isTrue);
      expect(features.ads, isTrue);
    });
  });

  group('TenantIntegrations', () {
    test('prodeAuth defaults to null', () {
      const integrations = TenantIntegrations();
      expect(integrations.prodeAuth, isNull);
    });

    test('prodeAuth can be set', () {
      const config = ProdeAuthConfig(
        prodeApiBaseUrl: 'https://example.com/wp-json/entre-redes/v1/prode',
        googleWebClientId: 'web-client',
        appleTeamId: 'TEAM123',
      );
      const integrations = TenantIntegrations(prodeAuth: config);
      expect(integrations.prodeAuth, isNotNull);
      expect(integrations.prodeAuth!.googleWebClientId, equals('web-client'));
    });
  });

  group('ProdeAuthConfig', () {
    test('required fields are set', () {
      const config = ProdeAuthConfig(
        prodeApiBaseUrl: 'https://example.com/wp-json/entre-redes/v1/prode',
        googleWebClientId: 'web-client',
        appleTeamId: 'TEAM123',
      );
      expect(config.prodeApiBaseUrl,
          equals('https://example.com/wp-json/entre-redes/v1/prode'));
      expect(config.googleWebClientId, equals('web-client'));
      expect(config.appleTeamId, equals('TEAM123'));
    });

    test('prodeApiBaseUrl has no trailing slash (convention)', () {
      const config = ProdeAuthConfig(
        prodeApiBaseUrl: 'https://example.com/wp-json/entre-redes/v1/prode',
        googleWebClientId: 'web-client',
        appleTeamId: 'TEAM123',
      );
      expect(config.prodeApiBaseUrl, isNot(endsWith('/')));
    });

    test('optional fields default to null', () {
      const config = ProdeAuthConfig(
        prodeApiBaseUrl: 'https://example.com/wp-json/entre-redes/v1/prode',
        googleWebClientId: 'web-client',
        appleTeamId: 'TEAM123',
      );
      expect(config.googleIosClientId, isNull);
      expect(config.googleAndroidClientId, isNull);
      expect(config.appleServiceId, isNull);
      expect(config.appleRedirectUri, isNull);
    });
  });

  group('marianistaTenant', () {
    test('has prode: true', () {
      expect(marianistaTenant.features.prode, isTrue);
    });

    test('has non-null prodeAuth', () {
      expect(marianistaTenant.integrations.prodeAuth, isNotNull);
    });

    test('prodeAuth has required fields set (placeholder values)', () {
      final auth = marianistaTenant.integrations.prodeAuth!;
      expect(auth.googleWebClientId, isNotEmpty);
      expect(auth.appleTeamId, isNotEmpty);
    });

    test('prodeAuth has Apple redirect URI set', () {
      final auth = marianistaTenant.integrations.prodeAuth!;
      expect(auth.appleRedirectUri, isNotNull);
      expect(auth.appleRedirectUri, contains('entreredespadres.com.ar'));
    });
  });

  group('facundoTenant', () {
    test('has prode: false', () {
      expect(facundoTenant.features.prode, isFalse);
    });

    test('has prodeAuth: null', () {
      expect(facundoTenant.integrations.prodeAuth, isNull);
    });
  });

  group('TenantConfig const constructor', () {
    test('is valid with all required fields', () {
      const cfg = TenantConfig(
        tenantId: 'test',
        appName: 'Test',
        apiBaseUrl: 'https://example.com',
        mediaBaseUrl: 'https://example.com/media',
        colors: BrandColors(
          primary: Color(0xFF000000),
          accent: Color(0xFF000000),
          splashBackground: Color(0xFF000000),
        ),
        features: TenantFeatures(prode: false),
        logoAsset: 'assets/logo.png',
      );
      expect(cfg.features.prode, isFalse);
      expect(cfg.integrations.prodeAuth, isNull);
    });
  });
}

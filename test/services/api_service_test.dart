import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/services/api_service.dart';

void main() {
  group('ApiService', () {
    test('stores the injected baseUrl on the instance', () {
      const url = 'https://example.com';
      final service = ApiService(baseUrl: url);

      expect(service.baseUrl, equals(url));
    });

    test('does not use a hardcoded URL when a custom one is provided', () {
      const customUrl = 'https://custom.tenant.com/wp-json/v1';
      const defaultUrl = 'https://entreredespadres.com.ar/wp-json/entre-redes/v1';

      final service = ApiService(baseUrl: customUrl);

      expect(service.baseUrl, equals(customUrl));
      expect(service.baseUrl, isNot(equals(defaultUrl)));
    });
  });
}

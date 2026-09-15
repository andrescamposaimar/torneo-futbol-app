import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:torneo_futbol_app/services/remote_data_service.dart';

void main() {
  group('RemoteDataService.fetchZocaloAds', () {
    test('keeps an ad with an image and no link, drops an ad with no image',
        () async {
      final mockClient = MockClient((request) async {
        return http.Response(
          jsonEncode({
            'zocalo_ads': [
              // No link — the image is the entire rendered content of the
              // banner, so this must survive the filter (the defect this
              // change fixes).
              {'image': 'https://example.com/a.png', 'link': ''},
              // No image — there is nothing to render regardless of the
              // link, so this must still be dropped.
              {'image': '', 'link': 'https://example.com/b'},
              // Both present — the ordinary case.
              {
                'image': 'https://example.com/c.png',
                'link': 'https://example.com/c',
              },
            ],
          }),
          200,
        );
      });

      const service = RemoteDataService(
        mediaBaseUrl: 'https://media.test',
        apiBaseUrl: 'https://api.test',
      );

      final ads = await http.runWithClient(
        () => service.fetchZocaloAds(),
        () => mockClient,
      );

      expect(ads.length, 2);
      expect(ads[0].imageUrl, 'https://example.com/a.png');
      expect(ads[0].link, '');
      expect(ads[1].imageUrl, 'https://example.com/c.png');
      expect(ads[1].link, 'https://example.com/c');
    });

    test('returns an empty list when the request fails', () async {
      final mockClient = MockClient((request) async {
        return http.Response('Internal Server Error', 500);
      });

      const service = RemoteDataService(
        mediaBaseUrl: 'https://media.test',
        apiBaseUrl: 'https://api.test',
      );

      final ads = await http.runWithClient(
        () => service.fetchZocaloAds(),
        () => mockClient,
      );

      expect(ads, isEmpty);
    });
  });
}

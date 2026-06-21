import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';
import 'package:torneo_futbol_app/services/prode_history_controller.dart';

const _testConfig = ProdeAuthConfig(
  prodeApiBaseUrl: 'https://test.example.com/wp-json/entre-redes/v1/prode',
  googleWebClientId: 'test-google',
  appleTeamId: 'TEST_TEAM',
);

/// Seeds secure storage with a valid access token so the authenticated
/// `request()` transport attaches a Bearer header instead of failing fast.
void _setUpStorageWithToken() {
  FlutterSecureStoragePlatform.instance = TestFlutterSecureStoragePlatform({
    'prode_tokens': json.encode({
      'access_token': 'tok',
      'refresh_token': 'ref',
      'session_version': '1',
      'tenant_id': 'test',
    }),
  });
}

ProdeHistoryController _makeController(http.Client httpClient) {
  _setUpStorageWithToken();
  final service = ProdeApiService(
    config: _testConfig,
    authRepo: ProdeAuthRepository(),
    httpClient: httpClient,
  );
  return ProdeHistoryController(service);
}

Map<String, dynamic> _item(int matchId) => {
      'fecha_id': 1,
      'season_id': 359,
      'match_id': matchId,
      'kickoff': '2026-06-13 13:45:00',
      'zona': 'Apertura Zona B',
      'home_team': 'A',
      'away_team': 'B',
      'score_home': 1,
      'score_away': 0,
      'real_score_home': 1,
      'real_score_away': 0,
      'is_final': true,
      'points': 1,
      'evaluation_method': 'result_only',
    };

String _pageBody({required int page, required int total, required int count}) {
  return json.encode({
    'items': List.generate(count, (i) => _item(page * 100 + i)),
    'total': total,
    'page': page,
    'per_page': 15,
  });
}

void main() {
  group('ProdeHistoryController', () {
    test('initial state is loading phase, empty', () {
      final c = _makeController(MockClient((_) async => http.Response('{}', 200)));
      expect(c.state.phase, ProdeHistoryPhase.loading);
      expect(c.state.items, isEmpty);
    });

    test('load() first page → ready with items, hasMore from total', () async {
      final client = MockClient((req) async {
        expect(req.url.queryParameters['page'], '1');
        return http.Response(_pageBody(page: 1, total: 20, count: 15), 200);
      });
      final c = _makeController(client);

      await c.load();

      expect(c.state.phase, ProdeHistoryPhase.ready);
      expect(c.state.items.length, 15);
      expect(c.state.hasMore, true);
      expect(c.state.loadedPages, 1);
    });

    test('loadMore() appends the next page and stops at the end', () async {
      final client = MockClient((req) async {
        final page = int.parse(req.url.queryParameters['page']!);
        if (page == 1) {
          return http.Response(_pageBody(page: 1, total: 20, count: 15), 200);
        }
        return http.Response(_pageBody(page: 2, total: 20, count: 5), 200);
      });
      final c = _makeController(client);

      await c.load();
      await c.loadMore();

      expect(c.state.items.length, 20);
      expect(c.state.loadedPages, 2);
      expect(c.state.hasMore, false);
      expect(c.state.isLoadingMore, false);
    });

    test('loadMore() is a no-op when hasMore is false', () async {
      var calls = 0;
      final client = MockClient((req) async {
        calls++;
        return http.Response(_pageBody(page: 1, total: 5, count: 5), 200);
      });
      final c = _makeController(client);

      await c.load();
      expect(c.state.hasMore, false);
      await c.loadMore();

      expect(calls, 1); // loadMore did not fire a second request.
    });

    test('empty first page → isEmpty', () async {
      final client = MockClient(
        (_) async => http.Response(_pageBody(page: 1, total: 0, count: 0), 200),
      );
      final c = _makeController(client);

      await c.load();

      expect(c.state.phase, ProdeHistoryPhase.ready);
      expect(c.state.isEmpty, true);
    });

    test('server error on first load → error phase', () async {
      final client = MockClient((_) async => http.Response('{}', 500));
      final c = _makeController(client);

      await c.load();

      expect(c.state.phase, ProdeHistoryPhase.error);
    });

    test('loadMore() failure flags loadMoreFailed and keeps items', () async {
      final client = MockClient((req) async {
        final page = int.parse(req.url.queryParameters['page']!);
        if (page == 1) {
          return http.Response(_pageBody(page: 1, total: 20, count: 15), 200);
        }
        return http.Response('{}', 500);
      });
      final c = _makeController(client);

      await c.load();
      await c.loadMore();

      expect(c.state.items.length, 15); // kept
      expect(c.state.loadMoreFailed, true);
      expect(c.state.isLoadingMore, false);
    });
  });
}

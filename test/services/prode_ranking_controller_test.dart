import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';
import 'package:torneo_futbol_app/services/prode_ranking_controller.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const _testConfig = ProdeAuthConfig(
  prodeApiBaseUrl: 'https://test.example.com/wp-json/entre-redes/v1/prode',
  googleWebClientId: 'test-google',
  appleTeamId: 'TEST_TEAM',
);

void _setUpFakeStorage(Map<String, String> store) {
  FlutterSecureStoragePlatform.instance =
      TestFlutterSecureStoragePlatform(store);
}

ProdeApiService _makeService(http.Client httpClient, ProdeAuthRepository repo) {
  return ProdeApiService(
    config: _testConfig,
    authRepo: repo,
    httpClient: httpClient,
  );
}

/// Creates a [ProdeRankingController] backed by a [MockClient] and empty storage
/// (anonymous — ranking is a public endpoint).
ProdeRankingController _makeController(http.Client httpClient) {
  _setUpFakeStorage({});
  final repo = ProdeAuthRepository();
  final service = _makeService(httpClient, repo);
  return ProdeRankingController(service);
}

/// Builds a minimal valid ranking response body.
String _rankingBody({
  List<Map<String, dynamic>> items = const [],
  int total = 0,
  int page = 1,
  int perPage = 50,
}) {
  return json.encode({
    'items': items,
    'total': total,
    'page': page,
    'per_page': perPage,
  });
}

Map<String, dynamic> _entryMap({
  int userId = 1,
  String displayName = 'User',
  int totalPoints = 5,
  int rank = 1,
  int exactCount = 1,
  bool isMe = false,
}) =>
    {
      'user_id': userId,
      'display_name': displayName,
      'total_points': totalPoints,
      'rank': rank,
      'exact_count': exactCount,
      'is_me': isMe,
    };

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

void main() {
  group('ProdeRankingController', () {
    test('initial state is ProdeRankingLoading', () {
      final controller = _makeController(MockClient((_) async =>
          http.Response(_rankingBody(), 200)));
      expect(controller.state, isA<ProdeRankingLoading>());
    });

    test('load() with 3 items → ProdeRankingLoaded, items.length==3', () async {
      final client = MockClient((_) async => http.Response(
            _rankingBody(
              items: [
                _entryMap(userId: 1, rank: 1),
                _entryMap(userId: 2, rank: 2),
                _entryMap(userId: 3, rank: 3),
              ],
              total: 3,
            ),
            200,
            headers: {'content-type': 'application/json'},
          ));
      final controller = _makeController(client);
      await controller.load();
      expect(controller.state, isA<ProdeRankingLoaded>());
      final loaded = controller.state as ProdeRankingLoaded;
      expect(loaded.page.items.length, 3);
      expect(loaded.page.total, 3);
    });

    test('load() with empty items → ProdeRankingEmpty', () async {
      final client = MockClient((_) async => http.Response(
            _rankingBody(items: [], total: 0),
            200,
            headers: {'content-type': 'application/json'},
          ));
      final controller = _makeController(client);
      await controller.load();
      expect(controller.state, isA<ProdeRankingEmpty>());
    });

    test('load() transport error → ProdeRankingError with non-empty code+message',
        () async {
      final client = MockClient((_) async => http.Response(
            json.encode({'code': 'server_error'}),
            500,
            headers: {'content-type': 'application/json'},
          ));
      final controller = _makeController(client);
      await controller.load();
      expect(controller.state, isA<ProdeRankingError>());
      final err = controller.state as ProdeRankingError;
      expect(err.code, isNotEmpty);
      expect(err.message, isNotEmpty);
    });

    test('load() guard — already Loaded → no-op, no HTTP call', () async {
      var callCount = 0;
      final client = MockClient((_) async {
        callCount++;
        return http.Response(
          _rankingBody(items: [_entryMap()], total: 1),
          200,
          headers: {'content-type': 'application/json'},
        );
      });
      final controller = _makeController(client);
      await controller.load();
      expect(controller.state, isA<ProdeRankingLoaded>());
      expect(callCount, 1);
      await controller.load(); // should be a no-op
      expect(callCount, 1); // no new call
      expect(controller.state, isA<ProdeRankingLoaded>());
    });

    test('refresh() from Loaded — state never transitions to Loading, ends as Loaded',
        () async {
      final client = MockClient((_) async => http.Response(
            _rankingBody(items: [_entryMap()], total: 1),
            200,
            headers: {'content-type': 'application/json'},
          ));
      final controller = _makeController(client);
      await controller.load();
      expect(controller.state, isA<ProdeRankingLoaded>());

      final states = <ProdeRankingState>[];
      controller.addListener((s) => states.add(s));
      await controller.refresh();

      expect(states, isNot(contains(isA<ProdeRankingLoading>())));
      expect(controller.state, isA<ProdeRankingLoaded>());
    });

    test('anonymous load (all is_me:false) → ProdeRankingLoaded, no exception',
        () async {
      final client = MockClient((_) async => http.Response(
            _rankingBody(
              items: [
                _entryMap(userId: 1, isMe: false),
                _entryMap(userId: 2, isMe: false),
              ],
              total: 2,
            ),
            200,
            headers: {'content-type': 'application/json'},
          ));
      final controller = _makeController(client);
      await expectLater(controller.load(), completes);
      expect(controller.state, isA<ProdeRankingLoaded>());
      final loaded = controller.state as ProdeRankingLoaded;
      expect(loaded.page.items.every((e) => !e.isMe), true);
    });
  });
}

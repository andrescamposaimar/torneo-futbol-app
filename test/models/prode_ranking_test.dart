import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/prode_ranking.dart';

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

Map<String, dynamic> _entryJson({
  int userId = 1,
  String displayName = 'Test',
  int totalPoints = 10,
  int rank = 1,
  int exactCount = 2,
  bool isMe = false,
}) {
  return {
    'user_id': userId,
    'display_name': displayName,
    'total_points': totalPoints,
    'rank': rank,
    'exact_count': exactCount,
    'is_me': isMe,
  };
}

Map<String, dynamic> _pageJson({
  List<Map<String, dynamic>>? items,
  int total = 0,
  int page = 1,
  int perPage = 50,
}) {
  return {
    'items': items ?? [],
    'total': total,
    'page': page,
    'per_page': perPage,
  };
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

void main() {
  group('RankingEntry.fromJson', () {
    test('full valid JSON parsed correctly', () {
      final json = {
        'user_id': 42,
        'display_name': 'Juan Pérez',
        'total_points': 17,
        'rank': 1,
        'exact_count': 3,
        'is_me': true,
      };
      final entry = RankingEntry.fromJson(json);
      expect(entry.userId, 42);
      expect(entry.displayName, 'Juan Pérez');
      expect(entry.totalPoints, 17);
      expect(entry.rank, 1);
      expect(entry.exactCount, 3);
      expect(entry.isMe, true);
    });

    test('is_me key absent → isMe == false', () {
      final json = _entryJson()..remove('is_me');
      final entry = RankingEntry.fromJson(json);
      expect(entry.isMe, false);
    });

    test('is_me value is null → isMe == false', () {
      final json = Map<String, dynamic>.from(_entryJson());
      json['is_me'] = null;
      final entry = RankingEntry.fromJson(json);
      expect(entry.isMe, false);
    });

    test('malformed required int field throws TypeError', () {
      final json = Map<String, dynamic>.from(_entryJson());
      json.remove('total_points');
      expect(() => RankingEntry.fromJson(json), throwsA(isA<TypeError>()));
    });

    test('== and hashCode — same data equal, differing rank not equal', () {
      final e1 = RankingEntry.fromJson(_entryJson());
      final e2 = RankingEntry.fromJson(_entryJson());
      final e3 = RankingEntry.fromJson(_entryJson(rank: 2));
      expect(e1, e2);
      expect(e1.hashCode, e2.hashCode);
      expect(e1 == e3, false);
    });

    test('toString contains userId', () {
      final entry = RankingEntry.fromJson(_entryJson(userId: 99));
      expect(entry.toString(), contains('99'));
    });
  });

  group('RankingPage.fromJson', () {
    test('full envelope parsed correctly — items.length==1, total/page/perPage correct', () {
      final json = _pageJson(
        items: [_entryJson(userId: 1)],
        total: 1,
        page: 1,
        perPage: 50,
      );
      final page = RankingPage.fromJson(json);
      expect(page.items.length, 1);
      expect(page.total, 1);
      expect(page.page, 1);
      expect(page.perPage, 50);
    });

    test('absent items key → empty list', () {
      final json = <String, dynamic>{'total': 0, 'page': 1, 'per_page': 50};
      final page = RankingPage.fromJson(json);
      expect(page.items.isEmpty, true);
    });

    test('empty items list', () {
      final json = _pageJson(items: [], total: 0, page: 1, perPage: 50);
      final page = RankingPage.fromJson(json);
      expect(page.items.isEmpty, true);
      expect(page.total, 0);
    });

    test('absent pagination fields → total==0, page==1, perPage==50', () {
      final json = <String, dynamic>{'items': []};
      final page = RankingPage.fromJson(json);
      expect(page.total, 0);
      expect(page.page, 1);
      expect(page.perPage, 50);
    });

    test('== and hashCode — two equal pages are ==, differing rank entry makes them !=', () {
      final p1 = RankingPage.fromJson(_pageJson(items: [_entryJson(rank: 1)]));
      final p2 = RankingPage.fromJson(_pageJson(items: [_entryJson(rank: 1)]));
      final p3 = RankingPage.fromJson(_pageJson(items: [_entryJson(rank: 2)]));
      expect(p1, p2);
      expect(p1.hashCode, p2.hashCode);
      expect(p1 == p3, false);
    });
  });
}

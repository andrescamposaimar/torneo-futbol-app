import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/prediction_history.dart';

Map<String, dynamic> _entryJson({
  int fechaId = 1,
  int seasonId = 359,
  int matchId = 10,
  String kickoff = '2026-06-13 13:45:00',
  String zona = 'Apertura Zona B',
  int scoreHome = 2,
  int scoreAway = 0,
  int? realHome = 3,
  int? realAway = 0,
  int? points = 1,
  String? method = 'result_only',
}) =>
    {
      'fecha_id': fechaId,
      'season_id': seasonId,
      'match_id': matchId,
      'kickoff': kickoff,
      'zona': zona,
      'home_team': 'KOSOVO',
      'away_team': 'ITALIA',
      'home_escudo': 'https://e/h.png',
      'away_escudo': 'https://e/a.png',
      'score_home': scoreHome,
      'score_away': scoreAway,
      'real_score_home': realHome,
      'real_score_away': realAway,
      'is_final': true,
      'points': points,
      'evaluation_method': method,
    };

void main() {
  group('PredictionHistoryEntry.fromJson', () {
    test('parses all fields including space-separated kickoff', () {
      final e = PredictionHistoryEntry.fromJson(_entryJson());
      expect(e.matchId, 10);
      expect(e.seasonId, 359);
      expect(e.zona, 'Apertura Zona B');
      expect(e.homeTeam, 'KOSOVO');
      expect(e.awayTeam, 'ITALIA');
      expect(e.scoreHome, 2);
      expect(e.scoreAway, 0);
      expect(e.realScoreHome, 3);
      expect(e.realScoreAway, 0);
      expect(e.isFinal, true);
      expect(e.points, 1);
      expect(e.evaluationMethod, 'result_only');
      expect(e.kickoff, DateTime(2026, 6, 13, 13, 45));
    });

    test('null points/method tolerated (final but not evaluated)', () {
      final e = PredictionHistoryEntry.fromJson(
        _entryJson(points: null, method: null),
      );
      expect(e.points, isNull);
      expect(e.evaluationMethod, isNull);
    });
  });

  group('PredictionHistoryPage.fromJson', () {
    test('absent items → empty list, defaults applied', () {
      final page = PredictionHistoryPage.fromJson(<String, dynamic>{});
      expect(page.items, isEmpty);
      expect(page.total, 0);
      expect(page.page, 1);
      expect(page.perPage, 15);
      expect(page.hasMore, false);
    });

    test('hasMore true when page*perPage < total', () {
      final page = PredictionHistoryPage.fromJson({
        'items': [_entryJson()],
        'total': 40,
        'page': 1,
        'per_page': 15,
      });
      expect(page.items.length, 1);
      expect(page.hasMore, true); // 1*15 = 15 < 40
    });

    test('hasMore false on the last page', () {
      final page = PredictionHistoryPage.fromJson({
        'items': [_entryJson()],
        'total': 30,
        'page': 2,
        'per_page': 15,
      });
      expect(page.hasMore, false); // 2*15 = 30, not < 30
    });
  });
}

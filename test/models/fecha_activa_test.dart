import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/fecha_activa.dart';

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

Map<String, dynamic> _matchJson({
  int matchId = 1,
  String homeTeam = 'Equipo A',
  String awayTeam = 'Equipo B',
  String kickoff = '2026-06-06 13:45:00',
  bool includeUserPredictions = false,
}) {
  return {
    'match_id': matchId,
    'home_team': homeTeam,
    'away_team': awayTeam,
    'kickoff': kickoff,
    if (includeUserPredictions) 'user_predictions': [],
  };
}

Map<String, dynamic> _fechaJson({
  int fechaId = 10,
  int seasonId = 3,
  String state = 'open',
  Object? lockedAt = null,
  List<Map<String, dynamic>>? matches,
  bool includeTopLevelPredictions = false,
}) {
  return {
    'fecha_id': fechaId,
    'season_id': seasonId,
    'state': state,
    'locked_at': lockedAt,
    'matches': matches ?? [_matchJson(), _matchJson(matchId: 2)],
    if (includeTopLevelPredictions) 'user_predictions': [],
  };
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

void main() {
  // -------------------------------------------------------------------------
  // ProdeFechaState.fromWire
  // -------------------------------------------------------------------------
  group('ProdeFechaState.fromWire', () {
    test("'open' maps to ProdeFechaState.open", () {
      expect(ProdeFechaState.fromWire('open'), equals(ProdeFechaState.open));
    });

    test("'locked' maps to ProdeFechaState.locked", () {
      expect(ProdeFechaState.fromWire('locked'), equals(ProdeFechaState.locked));
    });

    test("'evaluated' maps to ProdeFechaState.evaluated", () {
      expect(
        ProdeFechaState.fromWire('evaluated'),
        equals(ProdeFechaState.evaluated),
      );
    });

    test('unknown string maps to ProdeFechaState.unknown without throwing', () {
      expect(
        ProdeFechaState.fromWire('some_future_state'),
        equals(ProdeFechaState.unknown),
      );
    });

    test('empty string maps to ProdeFechaState.unknown', () {
      expect(ProdeFechaState.fromWire(''), equals(ProdeFechaState.unknown));
    });
  });

  // -------------------------------------------------------------------------
  // FechaMatch.fromJson
  // -------------------------------------------------------------------------
  group('FechaMatch.fromJson', () {
    test('happy path — all fields parsed correctly', () {
      final match = FechaMatch.fromJson(_matchJson(
        matchId: 7,
        homeTeam: 'River',
        awayTeam: 'Boca',
        kickoff: '2026-06-06 13:45:00',
      ));

      expect(match.matchId, equals(7));
      expect(match.homeTeam, equals('River'));
      expect(match.awayTeam, equals('Boca'));
      expect(match.kickoff.year, equals(2026));
      expect(match.kickoff.month, equals(6));
      expect(match.kickoff.day, equals(6));
      expect(match.kickoff.hour, equals(13));
      expect(match.kickoff.minute, equals(45));
      expect(match.kickoff.second, equals(0));
    });

    test('space-separated kickoff format is parsed correctly (no tz shift)', () {
      // CRITICAL: backend sends "Y-m-d H:i:s" (space), NOT ISO 8601 (T)
      final match = FechaMatch.fromJson(_matchJson(
        kickoff: '2026-06-07 20:00:00',
      ));

      expect(match.kickoff.year, equals(2026));
      expect(match.kickoff.month, equals(6));
      expect(match.kickoff.day, equals(7));
      expect(match.kickoff.hour, equals(20));
      expect(match.kickoff.minute, equals(0));
    });

    test('malformed kickoff throws FormatException', () {
      expect(
        () => FechaMatch.fromJson(_matchJson(kickoff: 'not-a-date')),
        throwsA(isA<FormatException>()),
      );
    });

    test('user_predictions key present → silently ignored, no exception', () {
      expect(
        () => FechaMatch.fromJson(_matchJson(includeUserPredictions: true)),
        returnsNormally,
      );
    });

    test('== and hashCode equality — same data, different instances', () {
      final a = FechaMatch.fromJson(_matchJson(matchId: 1));
      final b = FechaMatch.fromJson(_matchJson(matchId: 1));
      expect(a, equals(b));
      expect(a.hashCode, equals(b.hashCode));
    });

    test('!= when matchId differs', () {
      final a = FechaMatch.fromJson(_matchJson(matchId: 1));
      final b = FechaMatch.fromJson(_matchJson(matchId: 2));
      expect(a, isNot(equals(b)));
    });
  });

  // -------------------------------------------------------------------------
  // FechaActiva.fromJson
  // -------------------------------------------------------------------------
  group('FechaActiva.fromJson', () {
    test('happy path — state open, lockedAt null, 2 matches', () {
      final fecha = FechaActiva.fromJson(_fechaJson(
        fechaId: 10,
        seasonId: 3,
        state: 'open',
        lockedAt: null,
      ));

      expect(fecha.fechaId, equals(10));
      expect(fecha.seasonId, equals(3));
      expect(fecha.state, equals(ProdeFechaState.open));
      expect(fecha.lockedAt, isNull);
      expect(fecha.matches.length, equals(2));
    });

    test('lockedAt populated — parsed with space→T fix', () {
      final fecha = FechaActiva.fromJson(
        _fechaJson(lockedAt: '2026-06-07 12:00:00'),
      );

      expect(fecha.lockedAt, isNotNull);
      expect(fecha.lockedAt!.year, equals(2026));
      expect(fecha.lockedAt!.month, equals(6));
      expect(fecha.lockedAt!.day, equals(7));
      expect(fecha.lockedAt!.hour, equals(12));
      expect(fecha.lockedAt!.minute, equals(0));
    });

    test('lockedAt absent (key missing) → null', () {
      final json = _fechaJson()..remove('locked_at');
      final fecha = FechaActiva.fromJson(json);
      expect(fecha.lockedAt, isNull);
    });

    test('empty matches list → isEmpty, no exception', () {
      final fecha = FechaActiva.fromJson(_fechaJson(matches: []));
      expect(fecha.matches, isEmpty);
    });

    test('state locked → ProdeFechaState.locked', () {
      final fecha = FechaActiva.fromJson(_fechaJson(state: 'locked'));
      expect(fecha.state, equals(ProdeFechaState.locked));
    });

    test('state evaluated → ProdeFechaState.evaluated', () {
      final fecha = FechaActiva.fromJson(_fechaJson(state: 'evaluated'));
      expect(fecha.state, equals(ProdeFechaState.evaluated));
    });

    test('unknown state → ProdeFechaState.unknown without throwing', () {
      final fecha = FechaActiva.fromJson(_fechaJson(state: 'pending_review'));
      expect(fecha.state, equals(ProdeFechaState.unknown));
    });

    test('top-level user_predictions key → silently ignored, no exception', () {
      expect(
        () => FechaActiva.fromJson(
          _fechaJson(includeTopLevelPredictions: true),
        ),
        returnsNormally,
      );
    });

    test('== and hashCode equality — same data, different instances', () {
      final a = FechaActiva.fromJson(_fechaJson());
      final b = FechaActiva.fromJson(_fechaJson());
      expect(a, equals(b));
      expect(a.hashCode, equals(b.hashCode));
    });

    test('!= when fechaId differs', () {
      final a = FechaActiva.fromJson(_fechaJson(fechaId: 10));
      final b = FechaActiva.fromJson(_fechaJson(fechaId: 11));
      expect(a, isNot(equals(b)));
    });
  });

  // -------------------------------------------------------------------------
  // PredictionEntry.fromJson  (B1-1, B1-2)
  // -------------------------------------------------------------------------
  group('PredictionEntry.fromJson', () {
    test('parses match_id, score_home, score_away correctly', () {
      final entry = PredictionEntry.fromJson({
        'match_id': 5,
        'score_home': 2,
        'score_away': 1,
      });
      expect(entry.matchId, equals(5));
      expect(entry.scoreHome, equals(2));
      expect(entry.scoreAway, equals(1));
    });
  });

  // -------------------------------------------------------------------------
  // FechaActiva.userPredictions  (B1-1, B1-2)
  // -------------------------------------------------------------------------
  group('FechaActiva.userPredictions', () {
    test('populated user_predictions parses to one PredictionEntry', () {
      final fecha = FechaActiva.fromJson({
        'fecha_id': 1,
        'season_id': 10,
        'state': 'open',
        'locked_at': null,
        'matches': [],
        'user_predictions': [
          {'match_id': 5, 'score_home': 2, 'score_away': 1},
        ],
      });
      expect(fecha.userPredictions, hasLength(1));
      expect(fecha.userPredictions.first.matchId, equals(5));
      expect(fecha.userPredictions.first.scoreHome, equals(2));
      expect(fecha.userPredictions.first.scoreAway, equals(1));
    });

    test('absent user_predictions key → empty list, no exception', () {
      final fecha = FechaActiva.fromJson({
        'fecha_id': 1,
        'season_id': 10,
        'state': 'open',
        'locked_at': null,
        'matches': [],
      });
      expect(fecha.userPredictions, isEmpty);
    });

    test('null user_predictions value → empty list, no exception', () {
      final fecha = FechaActiva.fromJson({
        'fecha_id': 1,
        'season_id': 10,
        'state': 'open',
        'locked_at': null,
        'matches': [],
        'user_predictions': null,
      });
      expect(fecha.userPredictions, isEmpty);
    });
  });

  // -------------------------------------------------------------------------
  // G6-d: FechaMatch new fields (zona, homeEscudo, awayEscudo, populares)
  // -------------------------------------------------------------------------
  group('FechaMatch G6-d new fields', () {
    Map<String, dynamic> _matchJsonG6d({
      int matchId = 1,
      String zona = 'Zona A',
      String? homeEscudo = 'https://example.com/home.png',
      String? awayEscudo = 'https://example.com/away.png',
      Map<String, dynamic>? populares,
      bool includePopulares = false,
    }) {
      return {
        'match_id': matchId,
        'home_team': 'Equipo A',
        'away_team': 'Equipo B',
        'kickoff': '2026-06-06 13:45:00',
        'zona': zona,
        'home_escudo': homeEscudo,
        'away_escudo': awayEscudo,
        if (includePopulares) 'populares': populares,
      };
    }

    test('zona field is parsed correctly', () {
      final match = FechaMatch.fromJson(_matchJsonG6d(zona: 'Zona Norte'));
      expect(match.zona, equals('Zona Norte'));
    });

    test('zona absent → defaults to empty string', () {
      final json = {
        'match_id': 1,
        'home_team': 'A',
        'away_team': 'B',
        'kickoff': '2026-06-06 13:45:00',
      };
      final match = FechaMatch.fromJson(json);
      expect(match.zona, equals(''));
    });

    test('homeEscudo and awayEscudo are parsed correctly', () {
      final match = FechaMatch.fromJson(
        _matchJsonG6d(
          homeEscudo: 'https://cdn.test/home.png',
          awayEscudo: 'https://cdn.test/away.png',
        ),
      );
      expect(match.homeEscudo, equals('https://cdn.test/home.png'));
      expect(match.awayEscudo, equals('https://cdn.test/away.png'));
    });

    test('null homeEscudo → homeEscudo is null', () {
      final match = FechaMatch.fromJson(_matchJsonG6d(homeEscudo: null));
      expect(match.homeEscudo, isNull);
    });

    test('null awayEscudo → awayEscudo is null', () {
      final match = FechaMatch.fromJson(_matchJsonG6d(awayEscudo: null));
      expect(match.awayEscudo, isNull);
    });

    test('absent home_escudo key → homeEscudo is null', () {
      final json = {
        'match_id': 1,
        'home_team': 'A',
        'away_team': 'B',
        'kickoff': '2026-06-06 13:45:00',
        'zona': 'Z',
        'away_escudo': null,
      };
      final match = FechaMatch.fromJson(json);
      expect(match.homeEscudo, isNull);
    });

    test('populares parsed — keys 1, X, 2 → home/draw/away doubles (percentage contract)', () {
      // Backend sends percentages: 50.0 = 50%, 30.0 = 30%, 20.0 = 20%
      final match = FechaMatch.fromJson(
        _matchJsonG6d(
          includePopulares: true,
          populares: {'1': 50.0, 'X': 30.0, '2': 20.0},
        ),
      );
      expect(match.populares, isNotNull);
      expect(match.populares!.home, closeTo(50.0, 0.001));
      expect(match.populares!.draw, closeTo(30.0, 0.001));
      expect(match.populares!.away, closeTo(20.0, 0.001));
    });

    test('populares as int values → parsed as doubles (zero-vote outcomes)', () {
      // Backend may return integers when there are zero votes for an outcome
      final match = FechaMatch.fromJson(
        _matchJsonG6d(
          includePopulares: true,
          populares: {'1': 100, 'X': 0, '2': 0},
        ),
      );
      expect(match.populares!.home, equals(100.0));
    });

    test('populares null value → populares is null', () {
      final match = FechaMatch.fromJson(
        _matchJsonG6d(includePopulares: true, populares: null),
      );
      expect(match.populares, isNull);
    });

    test('populares key absent → populares is null', () {
      final match = FechaMatch.fromJson(_matchJsonG6d(includePopulares: false));
      expect(match.populares, isNull);
    });

    test('existing fields unchanged after G6-d additions', () {
      final match = FechaMatch.fromJson(
        _matchJsonG6d(matchId: 42),
      );
      expect(match.matchId, equals(42));
      expect(match.homeTeam, equals('Equipo A'));
      expect(match.awayTeam, equals('Equipo B'));
      expect(match.kickoff.year, equals(2026));
    });
  });

  // -------------------------------------------------------------------------
  // G6-d: Populares model
  // Backend sends percentage values [0.0, 100.0] — e.g. 60.0 means 60%, not 0.6.
  // -------------------------------------------------------------------------
  group('Populares', () {
    test('fromJson parses percentage keys 1, X, 2 correctly', () {
      // Backend sends percentage values: 60.0 = 60%, 25.0 = 25%, 15.0 = 15%
      final p = Populares.fromJson({'1': 60.0, 'X': 25.0, '2': 15.0});
      expect(p.home, closeTo(60.0, 0.001));
      expect(p.draw, closeTo(25.0, 0.001));
      expect(p.away, closeTo(15.0, 0.001));
    });

    test('fromJson converts int values to double', () {
      // Backend may send integers for 0-vote outcomes
      final p = Populares.fromJson({'1': 100, 'X': 0, '2': 0});
      expect(p.home, isA<double>());
      expect(p.home, equals(100.0));
    });

    test('fromJson parses 100.0/0.0/0.0 (all votes on home) correctly', () {
      final p = Populares.fromJson({'1': 100.0, 'X': 0.0, '2': 0.0});
      expect(p.home, equals(100.0));
      expect(p.draw, equals(0.0));
      expect(p.away, equals(0.0));
    });

    test('fromJson parses realistic decimal percentages (e.g. 33.3)', () {
      final p = Populares.fromJson({'1': 33.3, 'X': 33.3, '2': 33.4});
      expect(p.home, closeTo(33.3, 0.001));
      expect(p.draw, closeTo(33.3, 0.001));
      expect(p.away, closeTo(33.4, 0.001));
    });
  });

  // -------------------------------------------------------------------------
  // T-09: FechaMatch — realScoreHome, realScoreAway, isFinal fields
  // -------------------------------------------------------------------------
  group('FechaMatch real-score fields (T-09)', () {
    Map<String, dynamic> _baseMatchJson({
      int matchId = 1,
      int? realScoreHome,
      int? realScoreAway,
      bool? isFinal,
      bool omitRealScoreHome = false,
      bool omitRealScoreAway = false,
      bool omitIsFinal = false,
    }) {
      final json = <String, dynamic>{
        'match_id': matchId,
        'home_team': 'River',
        'away_team': 'Boca',
        'kickoff': '2026-06-06 13:45:00',
      };
      if (!omitRealScoreHome) json['real_score_home'] = realScoreHome;
      if (!omitRealScoreAway) json['real_score_away'] = realScoreAway;
      if (!omitIsFinal) json['is_final'] = isFinal ?? false;
      return json;
    }

    test('isFinal defaults to false when key is absent', () {
      final match = FechaMatch.fromJson(_baseMatchJson(omitIsFinal: true));
      expect(match.isFinal, isFalse);
    });

    test('isFinal=true parsed correctly', () {
      final match = FechaMatch.fromJson(_baseMatchJson(isFinal: true));
      expect(match.isFinal, isTrue);
    });

    test('isFinal=false parsed correctly', () {
      final match = FechaMatch.fromJson(_baseMatchJson(isFinal: false));
      expect(match.isFinal, isFalse);
    });

    test('realScoreHome parsed when present and non-null', () {
      final match = FechaMatch.fromJson(_baseMatchJson(realScoreHome: 2, isFinal: true));
      expect(match.realScoreHome, equals(2));
    });

    test('realScoreAway parsed when present and non-null', () {
      final match = FechaMatch.fromJson(_baseMatchJson(realScoreAway: 1, isFinal: true));
      expect(match.realScoreAway, equals(1));
    });

    test('realScoreHome null value → null (active/locked fecha)', () {
      final match = FechaMatch.fromJson(_baseMatchJson(realScoreHome: null));
      expect(match.realScoreHome, isNull);
    });

    test('realScoreAway null value → null (active/locked fecha)', () {
      final match = FechaMatch.fromJson(_baseMatchJson(realScoreAway: null));
      expect(match.realScoreAway, isNull);
    });

    test('realScoreHome absent → null (backward compat — pre-change payload)', () {
      final match = FechaMatch.fromJson(_baseMatchJson(omitRealScoreHome: true));
      expect(match.realScoreHome, isNull);
    });

    test('realScoreAway absent → null (backward compat — pre-change payload)', () {
      final match = FechaMatch.fromJson(_baseMatchJson(omitRealScoreAway: true));
      expect(match.realScoreAway, isNull);
    });

    test('realScoreHome 0 parsed as 0 (not null)', () {
      final match = FechaMatch.fromJson(_baseMatchJson(realScoreHome: 0, isFinal: true));
      expect(match.realScoreHome, equals(0));
    });

    test('full evaluated match: all real-score fields present and populated', () {
      final match = FechaMatch.fromJson(_baseMatchJson(
        realScoreHome: 3,
        realScoreAway: 1,
        isFinal: true,
      ));
      expect(match.realScoreHome, equals(3));
      expect(match.realScoreAway, equals(1));
      expect(match.isFinal, isTrue);
    });

    test('legacy evaluated match: isFinal absent, real scores null — no crash', () {
      // Pre-change payload: was evaluated before this feature, lacks is_final and real scores
      final match = FechaMatch.fromJson({
        'match_id': 1,
        'home_team': 'A',
        'away_team': 'B',
        'kickoff': '2026-06-06 13:45:00',
      });
      expect(match.isFinal, isFalse);
      expect(match.realScoreHome, isNull);
      expect(match.realScoreAway, isNull);
    });

    test('== considers realScoreHome, realScoreAway, isFinal', () {
      final a = FechaMatch.fromJson(_baseMatchJson(realScoreHome: 2, realScoreAway: 1, isFinal: true));
      final b = FechaMatch.fromJson(_baseMatchJson(realScoreHome: 2, realScoreAway: 1, isFinal: true));
      final c = FechaMatch.fromJson(_baseMatchJson(realScoreHome: 0, realScoreAway: 0, isFinal: false));
      expect(a, equals(b));
      expect(a, isNot(equals(c)));
    });

    test('hashCode equal for same real-score data', () {
      final a = FechaMatch.fromJson(_baseMatchJson(realScoreHome: 2, realScoreAway: 1, isFinal: true));
      final b = FechaMatch.fromJson(_baseMatchJson(realScoreHome: 2, realScoreAway: 1, isFinal: true));
      expect(a.hashCode, equals(b.hashCode));
    });

    test('existing fields (zona, escudo, populares) unaffected by T-09 additions', () {
      final match = FechaMatch.fromJson({
        'match_id': 7,
        'home_team': 'River',
        'away_team': 'Boca',
        'kickoff': '2026-06-06 13:45:00',
        'zona': 'Zona A',
        'home_escudo': 'https://cdn.test/r.png',
        'away_escudo': 'https://cdn.test/b.png',
        // Backend sends percentages: 50.0 = 50%, 30.0 = 30%, 20.0 = 20%
        'populares': {'1': 50.0, 'X': 30.0, '2': 20.0},
        'real_score_home': 2,
        'real_score_away': 1,
        'is_final': true,
      });
      expect(match.matchId, equals(7));
      expect(match.zona, equals('Zona A'));
      expect(match.populares, isNotNull);
      expect(match.realScoreHome, equals(2));
      expect(match.isFinal, isTrue);
    });
  });

  // -------------------------------------------------------------------------
  // T-10: PredictionEntry — points, evaluationMethod fields
  // -------------------------------------------------------------------------
  group('PredictionEntry result fields (T-10)', () {
    test('points parsed when present and non-null', () {
      final entry = PredictionEntry.fromJson({
        'match_id': 5,
        'score_home': 2,
        'score_away': 1,
        'points': 3,
      });
      expect(entry.points, equals(3));
    });

    test('evaluationMethod parsed when present and non-null', () {
      final entry = PredictionEntry.fromJson({
        'match_id': 5,
        'score_home': 2,
        'score_away': 1,
        'evaluation_method': 'exact_score',
      });
      expect(entry.evaluationMethod, equals('exact_score'));
    });

    test('points null → null (active/locked fecha, not yet evaluated)', () {
      final entry = PredictionEntry.fromJson({
        'match_id': 5,
        'score_home': 2,
        'score_away': 1,
        'points': null,
      });
      expect(entry.points, isNull);
    });

    test('evaluationMethod null → null', () {
      final entry = PredictionEntry.fromJson({
        'match_id': 5,
        'score_home': 2,
        'score_away': 1,
        'evaluation_method': null,
      });
      expect(entry.evaluationMethod, isNull);
    });

    test('points absent → null (backward compat — pre-change payload)', () {
      final entry = PredictionEntry.fromJson({
        'match_id': 5,
        'score_home': 2,
        'score_away': 1,
      });
      expect(entry.points, isNull);
    });

    test('evaluationMethod absent → null (backward compat)', () {
      final entry = PredictionEntry.fromJson({
        'match_id': 5,
        'score_home': 2,
        'score_away': 1,
      });
      expect(entry.evaluationMethod, isNull);
    });

    test('points=0 parsed as 0 (wrong prediction, not null)', () {
      final entry = PredictionEntry.fromJson({
        'match_id': 5,
        'score_home': 2,
        'score_away': 1,
        'points': 0,
        'evaluation_method': 'result_only',
      });
      expect(entry.points, equals(0));
      expect(entry.evaluationMethod, equals('result_only'));
    });

    test('all evaluation_method values parsed correctly', () {
      for (final method in ['exact_score', 'result_only', 'no_prediction', 'no_match_score']) {
        final entry = PredictionEntry.fromJson({
          'match_id': 1,
          'score_home': 0,
          'score_away': 0,
          'points': 0,
          'evaluation_method': method,
        });
        expect(entry.evaluationMethod, equals(method));
      }
    });

    test('== considers points and evaluationMethod', () {
      final a = PredictionEntry.fromJson({
        'match_id': 5,
        'score_home': 2,
        'score_away': 1,
        'points': 3,
        'evaluation_method': 'exact_score',
      });
      final b = PredictionEntry.fromJson({
        'match_id': 5,
        'score_home': 2,
        'score_away': 1,
        'points': 3,
        'evaluation_method': 'exact_score',
      });
      final c = PredictionEntry.fromJson({
        'match_id': 5,
        'score_home': 2,
        'score_away': 1,
        'points': 1,
        'evaluation_method': 'result_only',
      });
      expect(a, equals(b));
      expect(a, isNot(equals(c)));
    });

    test('hashCode equal for identical data', () {
      final a = PredictionEntry.fromJson({
        'match_id': 5,
        'score_home': 2,
        'score_away': 1,
        'points': 3,
        'evaluation_method': 'exact_score',
      });
      final b = PredictionEntry.fromJson({
        'match_id': 5,
        'score_home': 2,
        'score_away': 1,
        'points': 3,
        'evaluation_method': 'exact_score',
      });
      expect(a.hashCode, equals(b.hashCode));
    });

    test('existing fields unaffected — matchId, scoreHome, scoreAway still parsed', () {
      final entry = PredictionEntry.fromJson({
        'match_id': 7,
        'score_home': 3,
        'score_away': 2,
        'points': 1,
        'evaluation_method': 'result_only',
      });
      expect(entry.matchId, equals(7));
      expect(entry.scoreHome, equals(3));
      expect(entry.scoreAway, equals(2));
    });
  });
}

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
}

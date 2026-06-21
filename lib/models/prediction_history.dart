import 'package:flutter/foundation.dart';

// ---------------------------------------------------------------------------
// Private date-time helper
// ---------------------------------------------------------------------------

/// Parses the Prode backend wire date-time format `"Y-m-d H:i:s"` (space
/// separated, ART/UTC-3, no timezone conversion). Mirrors the helper in
/// `fecha_activa.dart`; replicated here to keep that one private.
DateTime _parseProdeDateTime(String s) {
  return DateTime.parse(s.replaceFirst(' ', 'T'));
}

// ---------------------------------------------------------------------------
// PredictionHistoryEntry DTO
// ---------------------------------------------------------------------------

/// A single past prediction returned by `GET /prode/predicciones`.
///
/// Represents the caller's prediction for a FINISHED match (the backend only
/// returns entries where the match result is final). Carries everything the
/// finished-match card needs: teams, escudos, kickoff, zona, the user's
/// predicted score, the real score, and the awarded points / evaluation method.
///
/// Field names mirror [FechaMatch] + [PredictionEntry] so the shared match card
/// can render history entries with the same layout as fixtures.
@immutable
class PredictionHistoryEntry {
  final int fechaId;
  final int seasonId;
  final int matchId;

  /// Kickoff as a naive local DateTime (ART). Do NOT call toLocal/toUtc.
  final DateTime kickoff;

  final String zona;
  final String homeTeam;
  final String awayTeam;
  final String? homeEscudo;
  final String? awayEscudo;

  /// The caller's predicted score.
  final int scoreHome;
  final int scoreAway;

  /// The official final score. Always non-null in practice (the backend only
  /// returns final matches), but kept nullable for defensive parsing.
  final int? realScoreHome;
  final int? realScoreAway;

  /// Always true for history entries (the backend filters to final matches).
  final bool isFinal;

  /// Awarded points (0/1/3). Null when the fecha is final but not yet evaluated.
  final int? points;

  /// `exact_score` | `result_only` | ... Null until evaluated.
  final String? evaluationMethod;

  const PredictionHistoryEntry({
    required this.fechaId,
    required this.seasonId,
    required this.matchId,
    required this.kickoff,
    required this.zona,
    required this.homeTeam,
    required this.awayTeam,
    required this.scoreHome,
    required this.scoreAway,
    this.homeEscudo,
    this.awayEscudo,
    this.realScoreHome,
    this.realScoreAway,
    this.isFinal = true,
    this.points,
    this.evaluationMethod,
  });

  factory PredictionHistoryEntry.fromJson(Map<String, dynamic> json) {
    return PredictionHistoryEntry(
      fechaId: json['fecha_id'] as int,
      seasonId: (json['season_id'] as int?) ?? 0,
      matchId: json['match_id'] as int,
      kickoff: _parseProdeDateTime(json['kickoff'] as String),
      zona: (json['zona'] as String?) ?? '',
      homeTeam: json['home_team'] as String,
      awayTeam: json['away_team'] as String,
      homeEscudo: json['home_escudo'] as String?,
      awayEscudo: json['away_escudo'] as String?,
      scoreHome: json['score_home'] as int,
      scoreAway: json['score_away'] as int,
      realScoreHome: json['real_score_home'] as int?,
      realScoreAway: json['real_score_away'] as int?,
      isFinal: json['is_final'] == true,
      points: json['points'] as int?,
      evaluationMethod: json['evaluation_method'] as String?,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is PredictionHistoryEntry &&
          runtimeType == other.runtimeType &&
          fechaId == other.fechaId &&
          seasonId == other.seasonId &&
          matchId == other.matchId &&
          kickoff == other.kickoff &&
          zona == other.zona &&
          homeTeam == other.homeTeam &&
          awayTeam == other.awayTeam &&
          homeEscudo == other.homeEscudo &&
          awayEscudo == other.awayEscudo &&
          scoreHome == other.scoreHome &&
          scoreAway == other.scoreAway &&
          realScoreHome == other.realScoreHome &&
          realScoreAway == other.realScoreAway &&
          isFinal == other.isFinal &&
          points == other.points &&
          evaluationMethod == other.evaluationMethod;

  @override
  int get hashCode => Object.hashAll([
        fechaId,
        seasonId,
        matchId,
        kickoff,
        zona,
        homeTeam,
        awayTeam,
        homeEscudo,
        awayEscudo,
        scoreHome,
        scoreAway,
        realScoreHome,
        realScoreAway,
        isFinal,
        points,
        evaluationMethod,
      ]);

  @override
  String toString() =>
      'PredictionHistoryEntry(matchId: $matchId, $homeTeam $scoreHome-$scoreAway $awayTeam, '
      'real: $realScoreHome-$realScoreAway, points: $points)';
}

// ---------------------------------------------------------------------------
// PredictionHistoryPage DTO (envelope)
// ---------------------------------------------------------------------------

/// The `GET /prode/predicciones` response envelope: a page of past predictions
/// plus pagination metadata. Tolerates absent fields with sane defaults.
@immutable
class PredictionHistoryPage {
  final List<PredictionHistoryEntry> items;
  final int total;
  final int page;
  final int perPage;

  const PredictionHistoryPage({
    required this.items,
    required this.total,
    required this.page,
    required this.perPage,
  });

  /// Whether more pages exist beyond this one (used to drive infinite scroll).
  bool get hasMore => page * perPage < total;

  factory PredictionHistoryPage.fromJson(Map<String, dynamic> json) {
    final raw = json['items'];
    final items = (raw is List)
        ? raw
            .map((e) =>
                PredictionHistoryEntry.fromJson(e as Map<String, dynamic>))
            .toList(growable: false)
        : const <PredictionHistoryEntry>[];

    return PredictionHistoryPage(
      items: items,
      total: (json['total'] as int?) ?? 0,
      page: (json['page'] as int?) ?? 1,
      perPage: (json['per_page'] as int?) ?? 15,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is PredictionHistoryPage &&
          runtimeType == other.runtimeType &&
          listEquals(items, other.items) &&
          total == other.total &&
          page == other.page &&
          perPage == other.perPage;

  @override
  int get hashCode =>
      Object.hash(Object.hashAll(items), total, page, perPage);

  @override
  String toString() =>
      'PredictionHistoryPage(items: ${items.length}, total: $total, '
      'page: $page, perPage: $perPage)';
}

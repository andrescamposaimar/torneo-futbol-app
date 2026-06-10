import 'package:flutter/foundation.dart';

// ---------------------------------------------------------------------------
// Populares DTO  (G6-d)
// ---------------------------------------------------------------------------

/// Aggregate popularity percentages for a match's three outcomes.
///
/// Parsed from the `populares` key in the match wire object when non-null.
/// Keys from the backend: '1' (home), 'X' (draw), '2' (away).
/// All values are doubles in [0.0, 100.0] representing percentages of voters,
/// rounded to one decimal by the backend (e.g. 33.3, 100.0). Do NOT multiply
/// by 100 before displaying — the wire value is already a percentage.
@immutable
class Populares {
  /// Percentage of voters that predicted a home win ('1'). Range: [0.0, 100.0].
  final double home;

  /// Percentage of voters that predicted a draw ('X'). Range: [0.0, 100.0].
  final double draw;

  /// Percentage of voters that predicted an away win ('2'). Range: [0.0, 100.0].
  final double away;

  const Populares({
    required this.home,
    required this.draw,
    required this.away,
  });

  /// Parses a `{"1": num, "X": num, "2": num}` map.
  ///
  /// Accepts both int and double values from the backend — the backend may
  /// return integers (e.g. `0`) for outcomes with zero votes.
  factory Populares.fromJson(Map<String, dynamic> json) {
    return Populares(
      home: (json['1'] as num).toDouble(),
      draw: (json['X'] as num).toDouble(),
      away: (json['2'] as num).toDouble(),
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is Populares &&
          runtimeType == other.runtimeType &&
          home == other.home &&
          draw == other.draw &&
          away == other.away;

  @override
  int get hashCode => Object.hash(home, draw, away);

  @override
  String toString() =>
      'Populares(home: $home, draw: $draw, away: $away)';
}

// ---------------------------------------------------------------------------
// PredictionEntry DTO
// ---------------------------------------------------------------------------

/// A stored prediction for a single match, returned by `GET /prode/fecha-activa`
/// inside the `user_predictions` array when the caller is authenticated.
///
/// Immutable value object. Carries the wire fields that the client needs:
/// match identity, the stored score pair, and (post-evaluation) points earned
/// and the evaluation method applied.
///
/// [points] and [evaluationMethod] are null for non-evaluated fechas and for
/// pre-change payloads that pre-date this feature. Both fields are parsed
/// defensively — missing or null keys produce null values, never a crash.
@immutable
class PredictionEntry {
  final int matchId;
  final int scoreHome;
  final int scoreAway;

  /// Points earned for this prediction after evaluation (0, 1, or 3).
  /// Null when the fecha has not been evaluated yet or for pre-change payloads.
  final int? points;

  /// How the prediction was scored. One of:
  ///   `exact_score`  — predicted score matches real score (+3 pts)
  ///   `result_only`  — predicted outcome (W/D/L) matches (+1 pt if any)
  ///   `no_prediction`— user submitted no prediction for this match (0 pts)
  ///   `no_match_score` — match has no final score yet (0 pts)
  /// Null when the fecha has not been evaluated or for pre-change payloads.
  final String? evaluationMethod;

  const PredictionEntry({
    required this.matchId,
    required this.scoreHome,
    required this.scoreAway,
    this.points,
    this.evaluationMethod,
  });

  /// Parses a single entry from `{match_id, score_home, score_away, points?,
  /// evaluation_method?}`. Absent or null values for [points] and
  /// [evaluationMethod] produce null (defensive, backward-compatible).
  factory PredictionEntry.fromJson(Map<String, dynamic> json) {
    return PredictionEntry(
      matchId: json['match_id'] as int,
      scoreHome: json['score_home'] as int,
      scoreAway: json['score_away'] as int,
      points: json['points'] as int?,
      evaluationMethod: json['evaluation_method'] as String?,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is PredictionEntry &&
          runtimeType == other.runtimeType &&
          matchId == other.matchId &&
          scoreHome == other.scoreHome &&
          scoreAway == other.scoreAway &&
          points == other.points &&
          evaluationMethod == other.evaluationMethod;

  @override
  int get hashCode =>
      Object.hash(matchId, scoreHome, scoreAway, points, evaluationMethod);

  @override
  String toString() =>
      'PredictionEntry(matchId: $matchId, scoreHome: $scoreHome, '
      'scoreAway: $scoreAway, points: $points, '
      'evaluationMethod: $evaluationMethod)';
}

// ---------------------------------------------------------------------------
// ProdeFechaState enum
// ---------------------------------------------------------------------------

/// Represents the lifecycle state of an active Prode fecha (round).
///
/// Uses a non-exhaustive-friendly `.unknown` fallback so the app never
/// crashes when the backend introduces a new state value — it simply drops
/// to the neutral rendering path.
enum ProdeFechaState {
  open,
  locked,
  evaluated,
  unknown;

  /// Maps a wire string from the backend to a [ProdeFechaState].
  ///
  /// Any unrecognised value returns [ProdeFechaState.unknown] without
  /// throwing, protecting G1 from future backend state additions.
  factory ProdeFechaState.fromWire(String s) {
    switch (s) {
      case 'open':
        return ProdeFechaState.open;
      case 'locked':
        return ProdeFechaState.locked;
      case 'evaluated':
        return ProdeFechaState.evaluated;
      default:
        return ProdeFechaState.unknown;
    }
  }
}

// ---------------------------------------------------------------------------
// Private date-time helper
// ---------------------------------------------------------------------------

/// Parses a date-time string from the Prode backend wire format.
///
/// The backend sends `"Y-m-d H:i:s"` (space-separated, not ISO 8601).
/// [DateTime.parse] rejects the space separator, so we replace the first
/// space with `T` before parsing.
///
/// Throws [FormatException] on an invalid input — callers should propagate
/// this to the controller error state rather than swallowing it silently.
///
/// NOTE: No timezone conversion is applied. Values are ART (UTC-3) from the
/// backend and are displayed as-is in G1. A future slice that needs countdown
/// across DST must revisit this assumption.
DateTime _parseProdeDateTime(String s) {
  return DateTime.parse(s.replaceFirst(' ', 'T'));
}

// ---------------------------------------------------------------------------
// FechaMatch DTO
// ---------------------------------------------------------------------------

/// An individual match within an active fecha (round).
///
/// Immutable value object. Uses strict `as` casts so malformed data fails
/// loudly rather than producing silent defaults, matching the auth DTO idiom.
///
/// G6-d additions: [zona], [homeEscudo], [awayEscudo], [populares].
/// All new fields are optional/nullable for backward compatibility with
/// callers that construct [FechaMatch] directly (e.g., tests).
@immutable
class FechaMatch {
  final int matchId;
  final String homeTeam;
  final String awayTeam;

  /// Kickoff time as parsed from the backend's `"Y-m-d H:i:s"` format.
  /// Stored as a naive local DateTime (ART); do NOT call toLocal/toUtc.
  final DateTime kickoff;

  /// Competition zone/group label (e.g. "Zona A — Apertura 2026").
  /// Defaults to empty string when absent from the wire payload.
  final String zona;

  /// URL of the home team's shield/logo. Null when not provided by the backend.
  final String? homeEscudo;

  /// URL of the away team's shield/logo. Null when not provided by the backend.
  final String? awayEscudo;

  /// Prediction popularity distribution. Null when absent or null in the wire
  /// payload (i.e., no votes have been cast yet, or backend omits the field).
  final Populares? populares;

  /// Real (official) home score after the match is finalised.
  /// Null when the match has not ended or for pre-change payloads.
  /// Always null unless [isFinal] is true in the REST response (is_final gate).
  final int? realScoreHome;

  /// Real (official) away score after the match is finalised.
  /// Null when the match has not ended or for pre-change payloads.
  final int? realScoreAway;

  /// Whether the match result has been officially recorded and evaluation
  /// can be derived from it. Defaults to false for backward compatibility
  /// (absent key or pre-change payload).
  final bool isFinal;

  const FechaMatch({
    required this.matchId,
    required this.homeTeam,
    required this.awayTeam,
    required this.kickoff,
    this.zona = '',
    this.homeEscudo,
    this.awayEscudo,
    this.populares,
    this.realScoreHome,
    this.realScoreAway,
    this.isFinal = false,
  });

  /// Parses a match object from the backend wire shape.
  ///
  /// `user_predictions` is silently ignored (out of scope for G1).
  /// A malformed `kickoff` propagates a [FormatException].
  ///
  /// G6-d: parses `zona`, `home_escudo`, `away_escudo`, and `populares`.
  /// Absent or null values for the new fields produce safe defaults.
  ///
  /// T-09: parses `real_score_home` (int?), `real_score_away` (int?), and
  /// `is_final` (bool, defaults false). All three are optional for backward
  /// compatibility — pre-change payloads that omit them produce null/false.
  factory FechaMatch.fromJson(Map<String, dynamic> json) {
    // Parse populares: null JSON value or absent key both produce null.
    final rawPopulares = json['populares'];
    final Populares? populares = (rawPopulares is Map<String, dynamic>)
        ? Populares.fromJson(rawPopulares)
        : null;

    // is_final: absent key → false; explicit false → false; explicit true → true.
    final rawIsFinal = json['is_final'];
    final bool isFinal = rawIsFinal == true;

    return FechaMatch(
      matchId: json['match_id'] as int,
      homeTeam: json['home_team'] as String,
      awayTeam: json['away_team'] as String,
      kickoff: _parseProdeDateTime(json['kickoff'] as String),
      zona: (json['zona'] as String?) ?? '',
      homeEscudo: json['home_escudo'] as String?,
      awayEscudo: json['away_escudo'] as String?,
      populares: populares,
      realScoreHome: json['real_score_home'] as int?,
      realScoreAway: json['real_score_away'] as int?,
      isFinal: isFinal,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is FechaMatch &&
          runtimeType == other.runtimeType &&
          matchId == other.matchId &&
          homeTeam == other.homeTeam &&
          awayTeam == other.awayTeam &&
          kickoff == other.kickoff &&
          zona == other.zona &&
          homeEscudo == other.homeEscudo &&
          awayEscudo == other.awayEscudo &&
          populares == other.populares &&
          realScoreHome == other.realScoreHome &&
          realScoreAway == other.realScoreAway &&
          isFinal == other.isFinal;

  @override
  int get hashCode => Object.hash(
        matchId,
        homeTeam,
        awayTeam,
        kickoff,
        zona,
        homeEscudo,
        awayEscudo,
        populares,
        realScoreHome,
        realScoreAway,
        isFinal,
      );

  @override
  String toString() =>
      'FechaMatch(matchId: $matchId, homeTeam: $homeTeam, '
      'awayTeam: $awayTeam, kickoff: $kickoff, zona: $zona)';
}

// ---------------------------------------------------------------------------
// FechaActiva DTO
// ---------------------------------------------------------------------------

/// The currently active Prode fecha (round) returned by
/// `GET /prode/fecha-activa`.
///
/// Immutable value object. An empty [matches] list is valid and represents
/// a round with no fixtures yet (distinct from a 404 response).
@immutable
class FechaActiva {
  final int fechaId;
  final int seasonId;
  final ProdeFechaState state;

  /// When the round closes for predictions. `null` when the round is open
  /// or the backend omits the field.
  final DateTime? lockedAt;

  /// The fixtures in this round. May be empty.
  final List<FechaMatch> matches;

  /// Stored predictions for the authenticated user. Empty list for anonymous
  /// callers or when the user has made no predictions yet.
  final List<PredictionEntry> userPredictions;

  const FechaActiva({
    required this.fechaId,
    required this.seasonId,
    required this.state,
    required this.lockedAt,
    required this.matches,
    this.userPredictions = const [],
  });

  /// Parses the top-level backend response for `GET /prode/fecha-activa`.
  ///
  /// `user_predictions` is parsed into [userPredictions]; an absent or null
  /// key defaults to an empty list. A missing `locked_at` key or an explicit
  /// JSON `null` both produce `lockedAt == null`.
  factory FechaActiva.fromJson(Map<String, dynamic> json) {
    final rawLockedAt = json['locked_at'];
    final DateTime? lockedAt =
        (rawLockedAt is String) ? _parseProdeDateTime(rawLockedAt) : null;

    final rawMatches = json['matches'] as List<dynamic>;
    final matches = rawMatches
        .map((e) => FechaMatch.fromJson(e as Map<String, dynamic>))
        .toList(growable: false);

    final rawPredictions = json['user_predictions'];
    final userPredictions = (rawPredictions is List)
        ? rawPredictions
            .map((e) => PredictionEntry.fromJson(e as Map<String, dynamic>))
            .toList(growable: false)
        : const <PredictionEntry>[];

    return FechaActiva(
      fechaId: json['fecha_id'] as int,
      seasonId: json['season_id'] as int,
      state: ProdeFechaState.fromWire(json['state'] as String),
      lockedAt: lockedAt,
      matches: matches,
      userPredictions: userPredictions,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is FechaActiva &&
          runtimeType == other.runtimeType &&
          fechaId == other.fechaId &&
          seasonId == other.seasonId &&
          state == other.state &&
          lockedAt == other.lockedAt &&
          listEquals(matches, other.matches) &&
          listEquals(userPredictions, other.userPredictions);

  @override
  int get hashCode => Object.hash(
        fechaId,
        seasonId,
        state,
        lockedAt,
        Object.hashAll(matches),
        Object.hashAll(userPredictions),
      );

  @override
  String toString() =>
      'FechaActiva(fechaId: $fechaId, seasonId: $seasonId, '
      'state: $state, lockedAt: $lockedAt, matches: ${matches.length}, '
      'userPredictions: ${userPredictions.length})';
}

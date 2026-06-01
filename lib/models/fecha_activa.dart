import 'package:flutter/foundation.dart';

// ---------------------------------------------------------------------------
// PredictionEntry DTO
// ---------------------------------------------------------------------------

/// A stored prediction for a single match, returned by `GET /prode/fecha-activa`
/// inside the `user_predictions` array when the caller is authenticated.
///
/// Immutable value object. Carries only the wire fields that the client needs:
/// match identity and the stored score pair.
@immutable
class PredictionEntry {
  final int matchId;
  final int scoreHome;
  final int scoreAway;

  const PredictionEntry({
    required this.matchId,
    required this.scoreHome,
    required this.scoreAway,
  });

  /// Parses a single entry from `{match_id, score_home, score_away}`.
  factory PredictionEntry.fromJson(Map<String, dynamic> json) {
    return PredictionEntry(
      matchId: json['match_id'] as int,
      scoreHome: json['score_home'] as int,
      scoreAway: json['score_away'] as int,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is PredictionEntry &&
          runtimeType == other.runtimeType &&
          matchId == other.matchId &&
          scoreHome == other.scoreHome &&
          scoreAway == other.scoreAway;

  @override
  int get hashCode => Object.hash(matchId, scoreHome, scoreAway);

  @override
  String toString() =>
      'PredictionEntry(matchId: $matchId, scoreHome: $scoreHome, '
      'scoreAway: $scoreAway)';
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
@immutable
class FechaMatch {
  final int matchId;
  final String homeTeam;
  final String awayTeam;

  /// Kickoff time as parsed from the backend's `"Y-m-d H:i:s"` format.
  /// Stored as a naive local DateTime (ART); do NOT call toLocal/toUtc.
  final DateTime kickoff;

  const FechaMatch({
    required this.matchId,
    required this.homeTeam,
    required this.awayTeam,
    required this.kickoff,
  });

  /// Parses a match object from the backend wire shape.
  ///
  /// `user_predictions` is silently ignored (out of scope for G1).
  /// A malformed `kickoff` propagates a [FormatException].
  factory FechaMatch.fromJson(Map<String, dynamic> json) {
    return FechaMatch(
      matchId: json['match_id'] as int,
      homeTeam: json['home_team'] as String,
      awayTeam: json['away_team'] as String,
      kickoff: _parseProdeDateTime(json['kickoff'] as String),
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
          kickoff == other.kickoff;

  @override
  int get hashCode => Object.hash(matchId, homeTeam, awayTeam, kickoff);

  @override
  String toString() =>
      'FechaMatch(matchId: $matchId, homeTeam: $homeTeam, '
      'awayTeam: $awayTeam, kickoff: $kickoff)';
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

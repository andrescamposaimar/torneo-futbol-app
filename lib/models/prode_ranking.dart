import 'package:flutter/foundation.dart';

// ---------------------------------------------------------------------------
// RankingEntry DTO
// ---------------------------------------------------------------------------

/// A single row in the public season leaderboard returned by
/// `GET /prode/ranking`.
///
/// Immutable value object. Uses strict `as` casts so malformed data fails
/// loudly (TypeError propagates to the controller Error state) rather than
/// silently producing bogus defaults.
@immutable
class RankingEntry {
  final int userId;
  final String displayName;
  final int totalPoints;
  final int rank;
  final int exactCount;

  /// Whether this entry belongs to the authenticated caller.
  ///
  /// Defaults to `false` when the `is_me` key is absent (anonymous callers)
  /// or its value is JSON null. Matches the defensive pattern from
  /// `FechaActiva.fromJson` for optional boolean fields.
  final bool isMe;

  /// The player photo URL for this user. Null when the backend returns null
  /// or omits the `avatar_url` key.
  final String? avatarUrl;

  /// The team/club name associated with this user. Null when the backend
  /// returns null or omits the `team_name` key.
  final String? teamName;

  const RankingEntry({
    required this.userId,
    required this.displayName,
    required this.totalPoints,
    required this.rank,
    required this.exactCount,
    this.isMe = false,
    this.avatarUrl,
    this.teamName,
  });

  /// Parses a single leaderboard entry from its wire representation.
  ///
  /// All fields except `is_me` use strict `as` casts — a missing or
  /// wrongly-typed required field throws [TypeError], surfaced by the
  /// controller as an Error state.
  factory RankingEntry.fromJson(Map<String, dynamic> json) {
    return RankingEntry(
      userId: json['user_id'] as int,
      displayName: json['display_name'] as String,
      totalPoints: json['total_points'] as int,
      rank: json['rank'] as int,
      exactCount: json['exact_count'] as int,
      isMe: (json['is_me'] as bool?) ?? false,
      avatarUrl: json['avatar_url'] as String?,
      teamName: json['team_name'] as String?,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is RankingEntry &&
          runtimeType == other.runtimeType &&
          userId == other.userId &&
          displayName == other.displayName &&
          totalPoints == other.totalPoints &&
          rank == other.rank &&
          exactCount == other.exactCount &&
          isMe == other.isMe &&
          avatarUrl == other.avatarUrl &&
          teamName == other.teamName;

  @override
  int get hashCode => Object.hash(
        userId,
        displayName,
        totalPoints,
        rank,
        exactCount,
        isMe,
        avatarUrl,
        teamName,
      );

  @override
  String toString() =>
      'RankingEntry(userId: $userId, displayName: $displayName, '
      'totalPoints: $totalPoints, rank: $rank, exactCount: $exactCount, '
      'isMe: $isMe, avatarUrl: $avatarUrl, teamName: $teamName)';
}

// ---------------------------------------------------------------------------
// RankingMe DTO (caller summary)
// ---------------------------------------------------------------------------

/// The authenticated caller's own position in a ranking view, resolved by the
/// backend from the full ranked set (not just the returned page) so it is
/// correct regardless of pagination.
///
/// Present in the `GET /prode/ranking` envelope as `me`. Null when the caller
/// is anonymous or has no ranked row in the requested view (e.g. no points yet).
/// Powers the Prode summary card (Ranking de la Fecha / Ranking general).
@immutable
class RankingMe {
  final int userId;
  final int rank;
  final int totalPoints;
  final int exactCount;

  const RankingMe({
    required this.userId,
    required this.rank,
    required this.totalPoints,
    required this.exactCount,
  });

  /// Parses the `me` object. Tolerates absent `exact_count` (→ 0); the other
  /// fields use strict `as` casts so a malformed payload fails loudly.
  factory RankingMe.fromJson(Map<String, dynamic> json) {
    return RankingMe(
      userId: json['user_id'] as int,
      rank: json['rank'] as int,
      totalPoints: json['total_points'] as int,
      exactCount: (json['exact_count'] as int?) ?? 0,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is RankingMe &&
          runtimeType == other.runtimeType &&
          userId == other.userId &&
          rank == other.rank &&
          totalPoints == other.totalPoints &&
          exactCount == other.exactCount;

  @override
  int get hashCode => Object.hash(userId, rank, totalPoints, exactCount);

  @override
  String toString() =>
      'RankingMe(userId: $userId, rank: $rank, '
      'totalPoints: $totalPoints, exactCount: $exactCount)';
}

// ---------------------------------------------------------------------------
// RankingPage DTO (envelope)
// ---------------------------------------------------------------------------

/// The full leaderboard response envelope returned by `GET /prode/ranking`.
///
/// Wraps a list of [RankingEntry] items together with pagination metadata.
/// The envelope fields are designed so a future G6 pagination slice can add
/// a page-selector without a model rewrite.
///
/// Mirrors [FechaActiva] as the top-level DTO wrapper — same defensive
/// collection parse and `listEquals`-based equality.
@immutable
class RankingPage {
  /// The leaderboard rows for this page, in rank order.
  final List<RankingEntry> items;

  /// Total number of entries across all pages.
  final int total;

  /// The 1-based page index of this response.
  final int page;

  /// Number of items per page used for this response.
  final int perPage;

  /// The caller's own position summary, or null when anonymous / unranked.
  final RankingMe? me;

  const RankingPage({
    required this.items,
    required this.total,
    required this.page,
    required this.perPage,
    this.me,
  });

  /// Parses the `GET /prode/ranking` envelope.
  ///
  /// Tolerates absent `items` key (→ empty list) and absent pagination fields
  /// (→ sane defaults: `total=0`, `page=1`, `perPage=50`).
  factory RankingPage.fromJson(Map<String, dynamic> json) {
    final raw = json['items'];
    final items = (raw is List)
        ? raw
            .map((e) => RankingEntry.fromJson(e as Map<String, dynamic>))
            .toList(growable: false)
        : const <RankingEntry>[];

    final rawMe = json['me'];
    final me = rawMe is Map<String, dynamic>
        ? RankingMe.fromJson(rawMe)
        : null;

    return RankingPage(
      items: items,
      total: (json['total'] as int?) ?? 0,
      page: (json['page'] as int?) ?? 1,
      perPage: (json['per_page'] as int?) ?? 50,
      me: me,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is RankingPage &&
          runtimeType == other.runtimeType &&
          listEquals(items, other.items) &&
          total == other.total &&
          page == other.page &&
          perPage == other.perPage &&
          me == other.me;

  @override
  int get hashCode => Object.hash(
        Object.hashAll(items),
        total,
        page,
        perPage,
        me,
      );

  @override
  String toString() =>
      'RankingPage(items: ${items.length}, total: $total, '
      'page: $page, perPage: $perPage, me: $me)';
}

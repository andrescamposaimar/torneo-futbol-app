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

  const RankingEntry({
    required this.userId,
    required this.displayName,
    required this.totalPoints,
    required this.rank,
    required this.exactCount,
    this.isMe = false,
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
          isMe == other.isMe;

  @override
  int get hashCode =>
      Object.hash(userId, displayName, totalPoints, rank, exactCount, isMe);

  @override
  String toString() =>
      'RankingEntry(userId: $userId, displayName: $displayName, '
      'totalPoints: $totalPoints, rank: $rank, exactCount: $exactCount, '
      'isMe: $isMe)';
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

  const RankingPage({
    required this.items,
    required this.total,
    required this.page,
    required this.perPage,
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

    return RankingPage(
      items: items,
      total: (json['total'] as int?) ?? 0,
      page: (json['page'] as int?) ?? 1,
      perPage: (json['per_page'] as int?) ?? 50,
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
          perPage == other.perPage;

  @override
  int get hashCode => Object.hash(
        Object.hashAll(items),
        total,
        page,
        perPage,
      );

  @override
  String toString() =>
      'RankingPage(items: ${items.length}, total: $total, '
      'page: $page, perPage: $perPage)';
}

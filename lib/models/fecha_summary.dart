import 'package:flutter/foundation.dart';
import 'fecha_activa.dart';

// ---------------------------------------------------------------------------
// Private date-time helper (mirrors the private _parseProdeDateTime in
// fecha_activa.dart — kept as a local copy because that helper is private
// and we do not modify fecha_activa.dart for G6-e).
// ---------------------------------------------------------------------------

/// Parses a date-time string from the Prode backend wire format.
///
/// The backend sends `"Y-m-d H:i:s"` (space-separated, not ISO 8601).
/// [DateTime.parse] rejects the space separator, so we replace the first
/// space with `T` before parsing.
DateTime _parseProdeDateTime(String s) {
  return DateTime.parse(s.replaceFirst(' ', 'T'));
}

// ---------------------------------------------------------------------------
// FechaSummary DTO  (G6-e)
// ---------------------------------------------------------------------------

/// A lightweight summary of a single Prode fecha (round).
///
/// Returned as elements of the `fechas` array by `GET /prode/fechas`.
/// Does NOT include match-level or prediction-level data — those come from
/// `GET /prode/fecha/{id}` which returns a full [FechaActiva]-shaped body.
///
/// Ordered by `locked_at ASC` as returned by the server; the client preserves
/// that order and derives "Fecha N" labels from the list index (1-based).
@immutable
class FechaSummary {
  /// Backend identifier for this round.
  final int fechaId;

  /// Season this round belongs to.
  final int seasonId;

  /// Lifecycle state of this round.
  final ProdeFechaState state;

  /// When the round closes for predictions. Null when open or absent.
  final DateTime? lockedAt;

  /// Number of fixtures in this round (informational; not used for display yet).
  final int matchCount;

  const FechaSummary({
    required this.fechaId,
    required this.seasonId,
    required this.state,
    required this.lockedAt,
    required this.matchCount,
  });

  /// Parses a single summary entry from the `fechas` array.
  ///
  /// Wire shape: `{fecha_id, season_id, state, locked_at, match_count}`.
  /// A null or absent `locked_at` produces `lockedAt == null`.
  factory FechaSummary.fromJson(Map<String, dynamic> json) {
    final rawLockedAt = json['locked_at'];
    final DateTime? lockedAt =
        (rawLockedAt is String) ? _parseProdeDateTime(rawLockedAt) : null;

    return FechaSummary(
      fechaId: json['fecha_id'] as int,
      seasonId: json['season_id'] as int,
      state: ProdeFechaState.fromWire(json['state'] as String),
      lockedAt: lockedAt,
      matchCount: json['match_count'] as int,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is FechaSummary &&
          runtimeType == other.runtimeType &&
          fechaId == other.fechaId &&
          seasonId == other.seasonId &&
          state == other.state &&
          lockedAt == other.lockedAt &&
          matchCount == other.matchCount;

  @override
  int get hashCode =>
      Object.hash(fechaId, seasonId, state, lockedAt, matchCount);

  @override
  String toString() =>
      'FechaSummary(fechaId: $fechaId, seasonId: $seasonId, '
      'state: $state, lockedAt: $lockedAt, matchCount: $matchCount)';
}

// ---------------------------------------------------------------------------
// Top-level list parser
// ---------------------------------------------------------------------------

/// Parses the `{fechas: [...]}` response from `GET /prode/fechas`.
///
/// Returns an empty list when the `fechas` key is absent or not a list.
List<FechaSummary> fechaSummaryListFromJson(Map<String, dynamic> json) {
  final raw = json['fechas'];
  if (raw is! List) return const [];
  return raw
      .map((e) => FechaSummary.fromJson(e as Map<String, dynamic>))
      .toList(growable: false);
}

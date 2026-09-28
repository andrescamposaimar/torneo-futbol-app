import 'package:flutter/foundation.dart';

/// A single team the caller captains, as returned by `GET /cambios/mis-equipos`.
@immutable
class CambiosTeam {
  final int teamId;
  final String nombre;

  const CambiosTeam({required this.teamId, required this.nombre});

  factory CambiosTeam.fromJson(Map<String, dynamic> json) {
    final rawId = json['team_id'];
    final rawNombre = json['nombre'];
    return CambiosTeam(
      teamId: rawId is int ? rawId : 0,
      nombre: rawNombre is String && rawNombre.isNotEmpty
          ? rawNombre
          : 'Equipo #${rawId is int ? rawId : 0}',
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CambiosTeam &&
          runtimeType == other.runtimeType &&
          teamId == other.teamId &&
          nombre == other.nombre;

  @override
  int get hashCode => Object.hash(teamId, nombre);

  @override
  String toString() => 'CambiosTeam(teamId: $teamId, nombre: $nombre)';
}

/// The `GET /cambios/mis-equipos` response envelope.
///
/// `teams` empty is a legitimate, non-error outcome — "authenticated but
/// captains nothing" (see the backend's own CapitanController docblock).
@immutable
class CambiosMisEquipos {
  final int seasonId;
  final int playerId;
  final List<CambiosTeam> teams;

  const CambiosMisEquipos({
    required this.seasonId,
    required this.playerId,
    required this.teams,
  });

  factory CambiosMisEquipos.fromJson(Map<String, dynamic> json) {
    final rawTeams = json['teams'];
    final teams = (rawTeams is List)
        ? rawTeams
            .whereType<Map>()
            .map((e) => CambiosTeam.fromJson(e.cast<String, dynamic>()))
            .toList(growable: false)
        : const <CambiosTeam>[];

    return CambiosMisEquipos(
      seasonId: (json['season_id'] as int?) ?? 0,
      playerId: (json['player_id'] as int?) ?? 0,
      teams: teams,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CambiosMisEquipos &&
          runtimeType == other.runtimeType &&
          seasonId == other.seasonId &&
          playerId == other.playerId &&
          listEquals(teams, other.teams);

  @override
  int get hashCode => Object.hash(seasonId, playerId, Object.hashAll(teams));

  @override
  String toString() =>
      'CambiosMisEquipos(seasonId: $seasonId, playerId: $playerId, '
      'teams: ${teams.length})';
}

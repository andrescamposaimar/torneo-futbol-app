import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/cambios_mis_equipos.dart';
import 'cambios_api_service.dart';

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

/// State machine for the captain-context gate every Cambios screen sits
/// behind: resolves `GET /mis-equipos` into "not a captain of anything",
/// "captain of exactly one team" (auto-selected), or "captain of more than
/// one" (the app's own docblock is explicit the schema allows this — see
/// `CapitanRepository::listEquiposByCapitan()`).
sealed class CambiosContextState {
  const CambiosContextState();
}

final class CambiosContextLoading extends CambiosContextState {
  const CambiosContextLoading();
}

/// A transport/server error occurred. Distinct from [CambiosContextNotCaptain]
/// — this is NOT a legitimate "you don't captain anything" answer, it's a
/// failure to get any answer at all.
final class CambiosContextError extends CambiosContextState {
  const CambiosContextError();
}

/// Authenticated, but the caller's `player_id` currently captains nothing —
/// a legitimate 200 from the backend (see `CapitanController::listar()`'s
/// own docblock), never rendered as an error.
final class CambiosContextNotCaptain extends CambiosContextState {
  const CambiosContextNotCaptain();
}

/// Resolved: at least one team, with [selectedTeamId] pointing at the team
/// every downstream Cambios screen should act on. When [teams] has more
/// than one entry, the screen renders a picker that calls [selectTeam].
final class CambiosContextReady extends CambiosContextState {
  final int seasonId;
  final List<CambiosTeam> teams;
  final int selectedTeamId;

  const CambiosContextReady({
    required this.seasonId,
    required this.teams,
    required this.selectedTeamId,
  });

  CambiosTeam get selectedTeam =>
      teams.firstWhere((t) => t.teamId == selectedTeamId, orElse: () => teams.first);

  CambiosContextReady copyWith({int? selectedTeamId}) => CambiosContextReady(
        seasonId: seasonId,
        teams: teams,
        selectedTeamId: selectedTeamId ?? this.selectedTeamId,
      );
}

// ---------------------------------------------------------------------------
// Controller
// ---------------------------------------------------------------------------

class CambiosContextController extends StateNotifier<CambiosContextState> {
  final CambiosApiService _service;

  CambiosContextController(this._service) : super(const CambiosContextLoading());

  /// Fetches `GET /mis-equipos`. Guarded against redundant re-entry once
  /// already [CambiosContextReady] — mirrors every other Prode controller's
  /// initState guard convention. Call [refresh] to force a re-fetch.
  Future<void> load() async {
    if (state is CambiosContextReady) return;
    await _fetch();
  }

  /// Forces a re-fetch regardless of current state — used by the retry CTA
  /// on the error state.
  Future<void> refresh() => _fetch();

  Future<void> _fetch() async {
    state = const CambiosContextLoading();
    try {
      final result = await _service.fetchMisEquipos();
      if (result.teams.isEmpty) {
        state = const CambiosContextNotCaptain();
        return;
      }
      state = CambiosContextReady(
        seasonId: result.seasonId,
        teams: result.teams,
        selectedTeamId: result.teams.first.teamId,
      );
    } catch (_) {
      state = const CambiosContextError();
    }
  }

  /// Switches the active team among the ones the captain already captains.
  /// No-op when [teamId] is not one of them, or when not yet [CambiosContextReady].
  void selectTeam(int teamId) {
    final current = state;
    if (current is CambiosContextReady &&
        current.teams.any((t) => t.teamId == teamId)) {
      state = current.copyWith(selectedTeamId: teamId);
    }
  }
}

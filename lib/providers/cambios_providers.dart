import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/tenant_provider.dart';
import '../models/cambios_fecha_abierta.dart';
import '../models/jugador.dart';
import '../services/cambios_api_service.dart';
import '../services/cambios_candidatos_controller.dart';
import '../services/cambios_context_controller.dart';
import '../services/cambios_plantel_controller.dart';
import '../services/cambios_solicitudes_controller.dart';
import 'prode_providers.dart';
import 'service_providers.dart';

/// Provides a [CambiosApiService] wired to the active tenant's base API URL
/// (`cfg.apiBaseUrl` + `/cambios`) and reusing [prodeApiServiceProvider]'s
/// transport for the bearer/refresh plumbing — see [CambiosApiService]'s own
/// class docblock for why this must never grow a second implementation of
/// that.
final cambiosApiServiceProvider = Provider<CambiosApiService>((ref) {
  final cfg = ref.watch(tenantConfigProvider);
  return CambiosApiService(
    baseUrl: '${cfg.apiBaseUrl}/cambios',
    prodeApi: ref.watch(prodeApiServiceProvider),
  );
});

/// Provides the [CambiosContextController] — the captain-context gate every
/// Cambios screen sits behind. NOT autoDispose: mirrors the Prode
/// controllers' session-persistence so navigating away and back does not
/// re-fetch `mis-equipos`. [CambiosContextController.load] is triggered here,
/// once, at creation — [CambiosContextController.load]'s own guard (no-op
/// once already [CambiosContextReady]) is what actually protects against a
/// redundant re-fetch across re-entry, exactly like calling it would from a
/// screen's initState.
final cambiosContextControllerProvider =
    StateNotifierProvider<CambiosContextController, CambiosContextState>((ref) {
  final controller = CambiosContextController(ref.watch(cambiosApiServiceProvider));
  controller.load();
  return controller;
});

/// Identifies one team's roster/request list within a season — the family
/// key for [cambiosPlantelControllerProvider] and
/// [cambiosSolicitudesControllerProvider]. A captain of more than one team
/// (the schema allows it — see `CapitanRepository::listEquiposByCapitan()`)
/// must never see one team's cached roster bleed into another's; keying by
/// both ids (rather than a plain singleton controller) makes that bleed
/// impossible instead of relying on every screen remembering to refresh.
typedef CambiosTeamScope = ({int seasonId, int teamId});

/// Provides a [CambiosPlantelController] scoped to one team's roster ("Mi
/// Plantel"). autoDispose: cheap to re-fetch on every visit, and this keeps
/// a stale roster for a team the captain is no longer looking at from
/// lingering in memory — see [CambiosTeamScope]'s own docblock.
final cambiosPlantelControllerProvider = StateNotifierProvider.autoDispose
    .family<CambiosPlantelController, CambiosPlantelState, CambiosTeamScope>(
  (ref, scope) {
    final controller = CambiosPlantelController(ref.watch(cambiosApiServiceProvider));
    controller.load(seasonId: scope.seasonId, teamId: scope.teamId);
    return controller;
  },
);

/// Provides a [CambiosSolicitudesController] scoped to one team ("Mis
/// Solicitudes"). Same autoDispose-per-scope rationale as
/// [cambiosPlantelControllerProvider].
final cambiosSolicitudesControllerProvider = StateNotifierProvider.autoDispose
    .family<CambiosSolicitudesController, CambiosSolicitudesState, CambiosTeamScope>(
  (ref, scope) {
    final controller =
        CambiosSolicitudesController(ref.watch(cambiosApiServiceProvider));
    controller.load(seasonId: scope.seasonId, teamId: scope.teamId);
    return controller;
  },
);

/// Identifies one plaza's candidate search within a season/team — the
/// family key for [cambiosCandidatosControllerProvider]. A Dart record is
/// used instead of a hand-written class: records are value-equatable for
/// free, which is exactly what a family key needs.
typedef CambiosCandidatosParams = ({int seasonId, int teamId, int plazaId});

/// Provides a [CambiosCandidatosController] scoped to one plaza's candidate
/// search ("Pedir cambio"). autoDispose: this list is only relevant while
/// that specific screen is on screen — unlike the other Cambios controllers,
/// there's no value in keeping a stale candidate search alive in memory
/// after the captain navigates away.
final cambiosCandidatosControllerProvider = StateNotifierProvider.autoDispose
    .family<CambiosCandidatosController, CambiosCandidatosState, CambiosCandidatosParams>(
  (ref, params) {
    final controller = CambiosCandidatosController(
      ref.watch(cambiosApiServiceProvider),
      seasonId: params.seasonId,
      teamId: params.teamId,
      plazaId: params.plazaId,
    );
    controller.load();
    return controller;
  },
);

/// Fetches the season's currently open fecha (`GET /cambios/fecha-abierta`)
/// — the `fecha_id` "Pedir cambio" needs for `POST /cambios/solicitudes`,
/// and whether each request tipo's own deadline window is still open. `null`
/// is a legitimate resolved value (see [CambiosFechaAbierta]'s own
/// docblock), not the absence of one — [AsyncValue.hasError] is what
/// distinguishes a genuine fetch failure from "no open fecha right now".
///
/// autoDispose: this is single-screen-lifetime data, same rationale as
/// [cambiosCandidatosControllerProvider] — no value in keeping a stale
/// fetch alive once the captain navigates away from "Pedir cambio".
final cambiosFechaAbiertaProvider =
    FutureProvider.autoDispose.family<CambiosFechaAbierta?, int>((ref, seasonId) {
  return ref.watch(cambiosApiServiceProvider).fetchFechaAbierta(seasonId: seasonId);
});

/// Fetches [teamId]'s full roster (the same `/jugadores?equipo_id=` call
/// `TeamDetailScreen` makes) and indexes it by player id — the primary,
/// single-request source "Mi Plantel" cards use for photo/posicion/puntaje.
/// Every titular belongs to this team, so this one request covers all 11;
/// see [cambiosJugadorPorIdProvider] for the occupants it misses (a cambio
/// from another team, or the reserve pool).
///
/// autoDispose: cheap to re-fetch on every visit, same rationale as
/// [cambiosPlantelControllerProvider].
final cambiosEquipoRosterProvider =
    FutureProvider.autoDispose.family<Map<int, Jugador>, int>((ref, teamId) async {
  final api = ref.watch(apiServiceProvider);
  final res = await api.getJugadoresRaw(equipoId: teamId, perPage: 50);
  final items = List<dynamic>.from(res['items'] ?? const []);
  final roster = <int, Jugador>{};
  for (final raw in items) {
    try {
      final jugador = Jugador.fromJson(Map<String, dynamic>.from(raw as Map));
      roster[jugador.id] = jugador;
    } catch (_) {
      // Malformed entry: skip it — same tolerance TeamDetailScreen applies
      // to its own roster parse.
    }
  }
  return roster;
});

/// Fetches a single player by id (`GET /jugadores/{id}`) — the fallback for
/// a "Mi Plantel"/"Cambios activos" card whose player is NOT on
/// [cambiosEquipoRosterProvider]'s team. Resolves to `null` on any failure
/// instead of throwing: a card that cannot find its photo/puntaje falls
/// back to its placeholder look, never a broken screen.
///
/// An `autoDispose.family` keyed by player id: one request per missing
/// player id, deduplicated by Riverpod itself when more than one card
/// watches the same id (e.g. a titular occupying his own plaza).
final cambiosJugadorPorIdProvider =
    FutureProvider.autoDispose.family<Jugador?, int>((ref, playerId) async {
  try {
    final api = ref.watch(apiServiceProvider);
    final data = await api.getJugadorPorId(playerId);
    return Jugador.fromJson(data);
  } catch (_) {
    return null;
  }
});

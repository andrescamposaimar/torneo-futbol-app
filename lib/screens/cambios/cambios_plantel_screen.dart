import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../models/cambios_plaza.dart';
import '../../models/cambios_solicitud.dart';
import '../../models/jugador.dart';
import '../../providers/cambios_providers.dart';
import '../../services/cambios_plantel_controller.dart';
import '../../widgets/cambios_jugador_card.dart';
import '../../widgets/loading_seccion.dart';
import 'cambios_solicitar_screen.dart';
import 'cambios_solicitudes_screen.dart';

/// "Mi Plantel" — the captain's own plazas, as two sections:
///
///   1. The 11 titulares, always present and in the order the backend
///      returns them — the plazas ARE the squad's identity, so a titular
///      never disappears from this list even while someone else plays his
///      plaza (see [_TitularCard]'s docblock for that card's three states).
///   2. "Cambios activos" — one card per plaza whose occupant differs from
///      its titular, showing the OCCUPANT with "en la plaza de {titular}"
///      and the two actions that apply to a plaza in that state (see
///      [_CambioActivoCard]'s docblock). Absent (no header) when nothing is
///      active, never rendered as an empty section.
///
/// Container: watches [cambiosPlantelControllerProvider] scoped to
/// ([seasonId], [teamId]) — the provider itself triggers the initial
/// [CambiosPlantelController.load] (see `cambios_providers.dart`), so no
/// initState bootstrap is needed here. Player photo/posicion/puntaje come
/// from [cambiosEquipoRosterProvider] (one request, covers every titular)
/// plus [cambiosJugadorPorIdProvider] per id that call misses (an occupant
/// from another team or the reserve pool) — see those providers' docblocks.
///
/// *** WHY THE PER-ID FALLBACK GATES ON `rosterAsync.hasValue` ***
/// The fallback must fire only once the roster's outcome is actually known.
/// Branching on `.valueOrNull` instead — as a previous version of this
/// screen did — collapses "still loading" and "fetch failed" into the same
/// `null` as "not on this team's roster", so the fallback fires for every
/// titular/occupant the instant the screen opens, before the one roster
/// request (which would have covered all of them) even has a chance to
/// land. `.hasValue` is `true` only after [cambiosEquipoRosterProvider]
/// resolves successfully, so: while loading, nothing is requested per id;
/// if the roster fetch fails, nothing is requested per id either (see
/// below); once it resolves, only the ids it genuinely misses get their own
/// request.
///
/// A roster FETCH ERROR deliberately does NOT fall back to one request per
/// needed id — we would not know which ids the roster would have covered,
/// so that would retry the same already-failing backend call up to 22
/// times instead of once. The fallback's real job is "this specific id is
/// a genuine miss on an otherwise successful roster", not "recover from an
/// outage"; every card just keeps its placeholder look (see
/// [CambiosPlantelView.jugadoresById]'s docblock), and the existing
/// `RefreshIndicator` lets the captain retry the whole load.
class CambiosPlantelScreen extends ConsumerWidget {
  final int seasonId;
  final int teamId;

  const CambiosPlantelScreen({
    super.key,
    required this.seasonId,
    required this.teamId,
  });

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final scope = (seasonId: seasonId, teamId: teamId);
    final state = ref.watch(cambiosPlantelControllerProvider(scope));
    final notifier = ref.read(cambiosPlantelControllerProvider(scope).notifier);

    final jugadoresById = <int, Jugador>{};
    if (state is CambiosPlantelLoaded) {
      final rosterAsync = ref.watch(cambiosEquipoRosterProvider(teamId));

      // `hasValue` — not `valueOrNull` — is the gate: see this class's own
      // docblock, "WHY THE PER-ID FALLBACK GATES ON `rosterAsync.hasValue`".
      // While loading, or on a roster fetch error, this whole block is
      // skipped: no per-id request fires for ANY id, and every card falls
      // back to its placeholder look until the roster resolves (or the
      // captain retries via `RefreshIndicator`).
      if (rosterAsync.hasValue) {
        jugadoresById.addAll(rosterAsync.value!);

        final neededIds = <int>{};
        for (final plaza in state.plazas) {
          neededIds.add(plaza.titularPlayerId);
          final ocupanteId = plaza.ocupantePlayerId;
          if (ocupanteId != null) neededIds.add(ocupanteId);
        }
        for (final id in neededIds) {
          if (jugadoresById.containsKey(id)) continue;
          final fallback = ref.watch(cambiosJugadorPorIdProvider(id)).valueOrNull;
          if (fallback != null) jugadoresById[id] = fallback;
        }
      }
    }

    return CambiosPlantelView(
      state: state,
      jugadoresById: jugadoresById,
      onRetry: () => notifier.load(seasonId: seasonId, teamId: teamId),
      onRefresh: () => notifier.refresh(seasonId: seasonId, teamId: teamId),
      // CambiosSolicitarScreen fetches its own fecha (see its docblock,
      // "WHERE `fechaId` COMES FROM") — nothing to pass here any more.
      //
      // Reused by BOTH "Pedir cambio" (Section 1, titular's own plaza) and
      // "Cambiar este cambio" (Section 2, that same plaza's replacement):
      // a sustitucion always means "replace whoever occupies this plaza
      // today", regardless of whether that's the titular himself.
      onPedirCambio: (plaza) => Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => CambiosSolicitarScreen(
            seasonId: seasonId,
            teamId: teamId,
            plaza: plaza,
            tipo: CambiosSolicitudTipo.sustitucion,
            // Always the TITULAR's own puntaje, never the occupant's — see
            // `CambiosSolicitarScreen.puntaje`'s own docblock on why the
            // header's name and puntaje must stay coupled to the same
            // player.
            puntaje: jugadoresById[plaza.titularPlayerId]?.puntaje,
          ),
        ),
      ),
      // "Confirmar fin del cambio" (Section 2 only) — the regreso request.
      onPedirRegreso: (plaza) => Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => CambiosSolicitarScreen(
            seasonId: seasonId,
            teamId: teamId,
            plaza: plaza,
            tipo: CambiosSolicitudTipo.regreso,
            puntaje: jugadoresById[plaza.titularPlayerId]?.puntaje,
          ),
        ),
      ),
      onVerSolicitudes: () => Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => CambiosSolicitudesScreen(
            seasonId: seasonId,
            teamId: teamId,
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Presentational view (Riverpod-free)
// ---------------------------------------------------------------------------

class CambiosPlantelView extends StatelessWidget {
  final CambiosPlantelState state;

  /// Resolved player data, keyed by player id — titulares AND ocupantes.
  /// A missing entry means "not yet fetched or fetch failed"; cards render
  /// their placeholder look for that id rather than blocking on it.
  final Map<int, Jugador> jugadoresById;

  final VoidCallback onRetry;
  final Future<void> Function() onRefresh;
  final void Function(CambiosPlaza plaza) onPedirCambio;
  final void Function(CambiosPlaza plaza) onPedirRegreso;
  final VoidCallback onVerSolicitudes;

  const CambiosPlantelView({
    super.key,
    required this.state,
    this.jugadoresById = const {},
    required this.onRetry,
    required this.onRefresh,
    required this.onPedirCambio,
    required this.onPedirRegreso,
    required this.onVerSolicitudes,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'Mi Plantel',
                style: Theme.of(context)
                    .textTheme
                    .titleLarge
                    ?.copyWith(fontWeight: FontWeight.bold),
              ),
              TextButton.icon(
                key: const Key('ver_solicitudes_button'),
                onPressed: onVerSolicitudes,
                icon: const Icon(Icons.list_alt),
                label: const Text('Mis pedidos'),
              ),
            ],
          ),
        ),
        Expanded(
          child: switch (state) {
            CambiosPlantelLoading() =>
              const LoadingSeccion(texto: 'Cargando tu plantel...'),
            CambiosPlantelError() => _ErrorView(onRetry: onRetry),
            CambiosPlantelLoaded(:final plazas) => plazas.isEmpty
                ? const _EmptyView()
                : _PlantelList(
                    plazas: plazas,
                    jugadoresById: jugadoresById,
                    onRefresh: onRefresh,
                    onPedirCambio: onPedirCambio,
                    onPedirRegreso: onPedirRegreso,
                  ),
          },
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Per-state views
// ---------------------------------------------------------------------------

class _ErrorView extends StatelessWidget {
  final VoidCallback onRetry;
  const _ErrorView({required this.onRetry});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.error_outline, size: 64, color: theme.colorScheme.primary),
            const SizedBox(height: 16),
            Text('Algo salió mal', style: theme.textTheme.headlineSmall),
            const SizedBox(height: 8),
            const Text(
              'No pudimos cargar tu plantel. Revisá tu conexión y reintentá '
              'en unos minutos.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 24),
            ElevatedButton(onPressed: onRetry, child: const Text('Reintentar')),
          ],
        ),
      ),
    );
  }
}

/// The plazas table is empty in production until a backfill that has not
/// happened yet — this is the FIRST thing real captains will see, so it must
/// explain itself rather than look broken.
class _EmptyView extends StatelessWidget {
  const _EmptyView();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.inbox_outlined, size: 64, color: theme.colorScheme.primary),
            const SizedBox(height: 16),
            Text('Todavía no hay plazas cargadas',
                style: theme.textTheme.headlineSmall, textAlign: TextAlign.center),
            const SizedBox(height: 8),
            const Text(
              'Tu equipo todavía no tiene el plantel cargado en el sistema '
              'de cambios. Ni bien esté disponible vas a poder pedir cambios '
              'y regresos desde acá.',
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Loaded list: Section 1 (the 11 titulares) + Section 2 ("Cambios activos")
// ---------------------------------------------------------------------------

class _PlantelList extends StatelessWidget {
  final List<CambiosPlaza> plazas;
  final Map<int, Jugador> jugadoresById;
  final Future<void> Function() onRefresh;
  final void Function(CambiosPlaza plaza) onPedirCambio;
  final void Function(CambiosPlaza plaza) onPedirRegreso;

  const _PlantelList({
    required this.plazas,
    required this.jugadoresById,
    required this.onRefresh,
    required this.onPedirCambio,
    required this.onPedirRegreso,
  });

  @override
  Widget build(BuildContext context) {
    // A plaza belongs in "Cambios activos" exactly when someone other than
    // the titular occupies it — this is the SAME condition each
    // _TitularCard uses to grey itself out and show "Baja por cambio", so
    // the two sections always agree on who's "active" right now.
    final cambiosActivos =
        plazas.where((p) => p.ocupantePlayerId != null && !p.esTitularElOcupante).toList();

    return RefreshIndicator(
      onRefresh: onRefresh,
      child: ListView(
        key: const Key('plantel_list'),
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.symmetric(vertical: 8),
        children: [
          for (final plaza in plazas)
            _TitularCard(
              plaza: plaza,
              jugador: jugadoresById[plaza.titularPlayerId],
              onPedirCambio: onPedirCambio,
            ),
          if (cambiosActivos.isNotEmpty) ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 20, 16, 4),
              child: Text(
                'Cambios activos',
                style: Theme.of(context)
                    .textTheme
                    .titleMedium
                    ?.copyWith(fontWeight: FontWeight.bold),
              ),
            ),
            for (final plaza in cambiosActivos)
              _CambioActivoCard(
                plaza: plaza,
                jugador: jugadoresById[plaza.ocupantePlayerId],
                onPedirCambio: onPedirCambio,
                onPedirRegreso: onPedirRegreso,
              ),
          ],
        ],
      ),
    );
  }
}

/// Section 1 card — always the titular, in his own plaza's identity slot.
/// Three states:
///   - `cerrada`: "Cerrada" badge, visibly inert, no actions (unchanged
///     from before this change).
///   - Occupied by someone else (`!esTitularElOcupante`): greyed out, "Baja
///     por cambio" marker, NO actions — the titular is not the one leaving,
///     so offering HIM an action here would be wrong; see [_CambioActivoCard]
///     for the occupant's own actions.
///   - The titular himself occupies the plaza: renders normally, with
///     "Pedir cambio" (a sustitucion on this plaza).
class _TitularCard extends StatelessWidget {
  final CambiosPlaza plaza;
  final Jugador? jugador;
  final void Function(CambiosPlaza plaza) onPedirCambio;

  const _TitularCard({
    required this.plaza,
    required this.jugador,
    required this.onPedirCambio,
  });

  @override
  Widget build(BuildContext context) {
    final bajaPorCambio = !plaza.esTitularElOcupante && plaza.ocupantePlayerId != null;

    return CambiosJugadorCard(
      key: Key('plaza_card_${plaza.plazaId}'),
      playerId: plaza.titularPlayerId,
      nombre: plaza.titularNombre,
      jugador: jugador,
      greyedOut: bajaPorCambio,
      badges: [
        if (plaza.cerrada)
          CambiosBadge(key: Key('plaza_cerrada_badge_${plaza.plazaId}'), text: 'Cerrada'),
        if (bajaPorCambio)
          CambiosBadge(
            key: Key('baja_por_cambio_badge_${plaza.plazaId}'),
            text: 'Baja por cambio',
          ),
      ],
      actions: [
        if (!plaza.cerrada && !bajaPorCambio)
          OutlinedButton(
            key: Key('pedir_cambio_${plaza.plazaId}'),
            onPressed: () => onPedirCambio(plaza),
            child: const Text('Pedir cambio'),
          ),
      ],
    );
  }
}

/// Section 2 card — the plaza's CURRENT OCCUPANT (not the titular), tied
/// back to his plaza via "en la plaza de {titular}". Two actions, both
/// absent when the plaza is `cerrada` (same "visibly inert" rule as
/// Section 1):
///   - "Confirmar fin del cambio" (a regreso): enabled only when
///     [CambiosPlaza.regresoElegible] — otherwise disabled and labelled with
///     WHY (how many fechas remain, or that it could not be worked out), so
///     the captain learns the reason instead of pressing and collecting a
///     rejection.
///   - "Cambiar este cambio" (a sustitucion on the SAME plaza): legal and
///     common, always enabled while the plaza is open.
class _CambioActivoCard extends StatelessWidget {
  final CambiosPlaza plaza;
  final Jugador? jugador;
  final void Function(CambiosPlaza plaza) onPedirCambio;
  final void Function(CambiosPlaza plaza) onPedirRegreso;

  const _CambioActivoCard({
    required this.plaza,
    required this.jugador,
    required this.onPedirCambio,
    required this.onPedirRegreso,
  });

  @override
  Widget build(BuildContext context) {
    final ocupanteId = plaza.ocupantePlayerId;
    final ocupanteNombre = plaza.ocupanteNombre;
    if (ocupanteId == null || ocupanteNombre == null) {
      // Guarded by the caller's filter (ocupantePlayerId != null for every
      // plaza routed here) — this branch exists only so the type system
      // doesn't force a `!` at every use below.
      return const SizedBox.shrink();
    }

    final regresoListo = plaza.regresoElegible;
    final motivoNoListo = plaza.cerrada
        ? null
        : plaza.fechasFaltantesLiberacionIndeterminado
            ? 'No pudimos calcular cuántas fechas faltan para que el '
                'titular pueda volver.'
            : regresoListo
                ? null
                : 'Faltan ${plaza.fechasFaltantesLiberacion} fecha(s) para '
                    'que el titular pueda volver.';

    return CambiosJugadorCard(
      key: Key('activo_card_${plaza.plazaId}'),
      playerId: ocupanteId,
      nombre: ocupanteNombre,
      jugador: jugador,
      subtitleExtra: 'en la plaza de ${plaza.titularNombre}',
      badges: [
        if (plaza.cerrada)
          CambiosBadge(key: Key('activo_cerrada_badge_${plaza.plazaId}'), text: 'Cerrada'),
      ],
      actions: [
        if (!plaza.cerrada) ...[
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              OutlinedButton(
                key: Key('confirmar_fin_cambio_${plaza.plazaId}'),
                onPressed: regresoListo ? () => onPedirRegreso(plaza) : null,
                child: const Text('Confirmar fin del cambio'),
              ),
              if (motivoNoListo != null)
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: SizedBox(
                    width: 220,
                    child: Text(
                      motivoNoListo,
                      style: TextStyle(
                        fontSize: 11,
                        color: Colors.grey.shade600,
                        fontStyle: FontStyle.italic,
                      ),
                    ),
                  ),
                ),
            ],
          ),
          TextButton(
            key: Key('cambiar_este_cambio_${plaza.plazaId}'),
            onPressed: () => onPedirCambio(plaza),
            child: const Text('Cambiar este cambio'),
          ),
        ],
      ],
    );
  }
}

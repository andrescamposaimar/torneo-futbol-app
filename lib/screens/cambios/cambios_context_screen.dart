import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../models/cambios_mis_equipos.dart';
import '../../providers/cambios_providers.dart';
import '../../services/cambios_context_controller.dart';
import '../../widgets/loading_seccion.dart';
import 'cambios_plantel_screen.dart';

/// Resolves the captain-context gate ([CambiosContextController]) and
/// renders the right thing for each outcome: not-a-captain, a team picker
/// (more than one team), or straight into [CambiosPlantelScreen].
///
/// Rendered by [CambiosAuthGate] in the Authenticated arm — so, like
/// [ProdeChamiScreen], this returns a plain [Column], not a [Scaffold]; the
/// gate already owns the [Scaffold] + app bar.
class CambiosContextScreen extends ConsumerWidget {
  /// Forwarded from the Prode auth state's degraded-placeholder flag (cold
  /// start, no network). `GET /mis-equipos` still works while stale — the
  /// real bearer token on disk is valid even though the client's cached
  /// [ProdeUser] fields are a placeholder — so this only shows a banner, it
  /// never blocks the fetch (mirrors [ProdeChamiScreen]'s own treatment).
  final bool stale;

  const CambiosContextScreen({super.key, required this.stale});

  // No initState bootstrap needed: cambiosContextControllerProvider triggers
  // CambiosContextController.load() once at creation (see
  // cambios_providers.dart) — that method's own re-entry guard (no-op once
  // already CambiosContextReady) is what protects against a redundant
  // re-fetch, exactly as an initState guard would.

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(cambiosContextControllerProvider);
    final notifier = ref.read(cambiosContextControllerProvider.notifier);

    return Column(
      children: [
        if (stale) const _StaleBanner(),
        Expanded(
          child: switch (state) {
            CambiosContextLoading() =>
              const LoadingSeccion(texto: 'Buscando tus equipos...'),
            CambiosContextError() => _ErrorView(onRetry: notifier.refresh),
            CambiosContextNotCaptain() => const _NotCaptainView(),
            CambiosContextReady(
              :final seasonId,
              :final teams,
              :final selectedTeamId,
            ) =>
              _ReadyView(
                seasonId: seasonId,
                teams: teams,
                selectedTeamId: selectedTeamId,
                onSelectTeam: notifier.selectTeam,
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

class _StaleBanner extends StatelessWidget {
  const _StaleBanner();

  @override
  Widget build(BuildContext context) {
    return MaterialBanner(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      leading: const Icon(Icons.sync, color: Colors.orange),
      backgroundColor: Colors.amber.shade100,
      content: Text(
        'Sincronizando tus datos…',
        style: TextStyle(color: Theme.of(context).colorScheme.onSurface),
      ),
      actions: const [SizedBox.shrink()],
    );
  }
}

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
              'No pudimos cargar tus equipos. Revisá tu conexión y reintentá '
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

/// "Authenticated but captains nothing" — a legitimate 200, never an error.
class _NotCaptainView extends StatelessWidget {
  const _NotCaptainView();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.shield_outlined, size: 64, color: theme.colorScheme.primary),
            const SizedBox(height: 16),
            Text('No sos capitán', style: theme.textTheme.headlineSmall),
            const SizedBox(height: 8),
            const Text(
              'No sos capitán de ningún equipo, así que esta sección no '
              'tiene nada para mostrarte.',
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }
}

/// At least one team resolved. Shows a team-switcher row only when the
/// captain leads more than one team (the schema allows it — see
/// `CapitanRepository::listEquiposByCapitan()`'s own docblock), then the
/// selected team's roster.
///
/// [CambiosPlantelScreen] is keyed on the team id so switching teams tears
/// down and recreates that subtree — a fresh `load()` for the new team
/// instead of showing the previous team's stale roster under the new label.
class _ReadyView extends StatelessWidget {
  final int seasonId;
  final List<CambiosTeam> teams;
  final int selectedTeamId;
  final void Function(int teamId) onSelectTeam;

  const _ReadyView({
    required this.seasonId,
    required this.teams,
    required this.selectedTeamId,
    required this.onSelectTeam,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        if (teams.length > 1)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
            child: DropdownButtonFormField<int>(
              key: const Key('cambios_team_picker'),
              initialValue: selectedTeamId,
              decoration: const InputDecoration(
                labelText: 'Equipo',
                border: OutlineInputBorder(),
              ),
              items: [
                for (final t in teams)
                  DropdownMenuItem<int>(
                    value: t.teamId,
                    child: Text(t.nombre),
                  ),
              ],
              onChanged: (value) {
                if (value != null) onSelectTeam(value);
              },
            ),
          ),
        Expanded(
          child: CambiosPlantelScreen(
            key: ValueKey('plantel_$selectedTeamId'),
            seasonId: seasonId,
            teamId: selectedTeamId,
          ),
        ),
      ],
    );
  }
}

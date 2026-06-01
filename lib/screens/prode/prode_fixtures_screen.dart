import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../models/fecha_activa.dart';
import '../../providers/prode_providers.dart';
import '../../services/prode_fixtures_controller.dart';

/// Container for the Prode Fixtures screen.
///
/// Owns the provider wiring and lifecycle. Delegates rendering to the pure
/// [ProdeFixturesView] that is Riverpod-free and trivially widget-testable.
///
/// Mirrors [ProdeAuthGate]'s ConsumerStatefulWidget + initState microtask
/// pattern: [load] is triggered in initState via Future.microtask, guarded
/// on [ProdeFixturesLoading] so re-entry while already Loaded/Empty/Error
/// does NOT clobber the existing state.
///
/// [stale] and [onLogout] are forwarded from the [ProdeAuthAuthenticated]
/// arm in [ProdeAuthView] so the stale banner and logout affordance remain
/// accessible from within the fixtures screen.
class ProdeFixturesScreen extends ConsumerStatefulWidget {
  final bool stale;
  final VoidCallback onLogout;

  const ProdeFixturesScreen({
    super.key,
    required this.stale,
    required this.onLogout,
  });

  @override
  ConsumerState<ProdeFixturesScreen> createState() =>
      _ProdeFixturesScreenState();
}

class _ProdeFixturesScreenState extends ConsumerState<ProdeFixturesScreen> {
  @override
  void initState() {
    super.initState();
    // Only trigger load from the initial Loading state. Re-entry while
    // Loaded/Empty/Error must NOT clobber that state with a fresh fetch.
    if (ref.read(prodeFixturesControllerProvider) is ProdeFixturesLoading) {
      Future.microtask(() {
        if (mounted) {
          ref.read(prodeFixturesControllerProvider.notifier).load();
        }
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(prodeFixturesControllerProvider);
    final notifier = ref.read(prodeFixturesControllerProvider.notifier);

    return ProdeFixturesView(
      state: state,
      stale: widget.stale,
      onLogout: widget.onLogout,
      onRetry: notifier.load,
      onRefresh: notifier.refresh,
    );
  }
}

// ---------------------------------------------------------------------------
// Presentational view (Riverpod-free)
// ---------------------------------------------------------------------------

/// Pure presentational widget for the Prode Fixtures screen.
///
/// Receives all data and callbacks as constructor params — no Riverpod reads
/// inside this widget. This makes it trivially unit-testable by pumping it
/// with a concrete [ProdeFixturesState] and callbacks.
class ProdeFixturesView extends StatelessWidget {
  final ProdeFixturesState state;
  final bool stale;
  final VoidCallback onLogout;
  final VoidCallback onRetry;
  final Future<void> Function() onRefresh;

  const ProdeFixturesView({
    super.key,
    required this.state,
    required this.stale,
    required this.onLogout,
    required this.onRetry,
    required this.onRefresh,
  });

  @override
  Widget build(BuildContext context) {
    return switch (state) {
      ProdeFixturesLoading() => const _Centered(
          child: CircularProgressIndicator(),
        ),
      ProdeFixturesEmpty() => _EmptyView(onLogout: onLogout),
      ProdeFixturesError() => _ErrorView(
          onRetry: onRetry,
          onLogout: onLogout,
        ),
      ProdeFixturesLoaded(:final fecha, :final drafts) => _LoadedView(
          fecha: fecha,
          drafts: drafts,
          stale: stale,
          onLogout: onLogout,
          onRefresh: onRefresh,
        ),
    };
  }
}

// ---------------------------------------------------------------------------
// Per-state views (private — presentational details)
// ---------------------------------------------------------------------------

class _Centered extends StatelessWidget {
  final Widget child;
  const _Centered({required this.child});

  @override
  Widget build(BuildContext context) => Center(child: child);
}

/// Shown when there is no currently active fecha (404 from backend).
class _EmptyView extends StatelessWidget {
  final VoidCallback onLogout;

  const _EmptyView({required this.onLogout});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.sports_soccer_outlined,
                size: 64, color: theme.colorScheme.primary),
            const SizedBox(height: 16),
            Text(
              'No hay una fecha activa en este momento.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyMedium,
            ),
            const SizedBox(height: 24),
            TextButton.icon(
              onPressed: onLogout,
              icon: const Icon(Icons.logout),
              label: const Text('Cerrar sesión'),
            ),
          ],
        ),
      ),
    );
  }
}

/// Shown when a transport/server error occurred.
///
/// Shows a generic friendly message — NEVER the raw [ProdeFixturesError.message].
class _ErrorView extends StatelessWidget {
  final VoidCallback onRetry;
  final VoidCallback onLogout;

  const _ErrorView({required this.onRetry, required this.onLogout});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.error_outline,
                size: 64, color: theme.colorScheme.primary),
            const SizedBox(height: 16),
            Text('Algo salió mal', style: theme.textTheme.headlineSmall),
            const SizedBox(height: 8),
            const Text(
              'No pudimos cargar el Prode. Revisá tu conexión y reintentá en '
              'unos minutos.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 24),
            ElevatedButton(onPressed: onRetry, child: const Text('Reintentar')),
            const SizedBox(height: 12),
            TextButton.icon(
              onPressed: onLogout,
              icon: const Icon(Icons.logout),
              label: const Text('Cerrar sesión'),
            ),
          ],
        ),
      ),
    );
  }
}

/// Shown when a fecha is loaded successfully.
///
/// Wraps a scrollable list in a [RefreshIndicator]. The stale banner is
/// rendered above the list when [stale] is true. Each match is rendered by
/// a [_PredictionMatchTile] (which owns score input fields and a per-match
/// submit button). When the match list is empty a note replaces the list items.
class _LoadedView extends ConsumerWidget {
  final FechaActiva fecha;
  final Map<int, PredictionDraft> drafts;
  final bool stale;
  final VoidCallback onLogout;
  final Future<void> Function() onRefresh;

  const _LoadedView({
    required this.fecha,
    required this.drafts,
    required this.stale,
    required this.onLogout,
    required this.onRefresh,
  });

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final controller = ref.read(prodeFixturesControllerProvider.notifier);
    // UX-only client-side lock check. Server lock (423) is the security boundary.
    final isLocked =
        fecha.lockedAt != null && !DateTime.now().isBefore(fecha.lockedAt!);

    return Column(
      children: [
        if (stale) const _StaleBanner(),
        _FechaBadge(state: fecha.state),
        Expanded(
          child: RefreshIndicator(
            onRefresh: onRefresh,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: [
                if (fecha.matches.isEmpty)
                  const Padding(
                    padding: EdgeInsets.all(24),
                    child: Center(child: Text('Sin partidos en esta fecha.')),
                  )
                else
                  ...fecha.matches.map((m) => _PredictionMatchTile(
                        match: m,
                        draft: drafts[m.matchId] ?? const PredictionDraft(),
                        isLocked: isLocked,
                        onUpdateDraft: (home, away) =>
                            controller.updateDraft(m.matchId,
                                scoreHome: home, scoreAway: away),
                        onSubmit: () => controller.submitPrediction(m.matchId),
                      )),
                const SizedBox(height: 8),
                Center(
                  child: TextButton.icon(
                    onPressed: onLogout,
                    icon: const Icon(Icons.logout),
                    label: const Text('Cerrar sesión'),
                  ),
                ),
                const SizedBox(height: 16),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

/// Stale-session banner — mirrors the banner in the deleted [_ProdeHome].
///
/// Shown at the top of the fixtures list when the app bootstrapped with
/// stale tokens and the real user data has not yet been confirmed by the server.
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

/// Optional badge shown near the top of the list when the fecha is not open.
///
/// - [ProdeFechaState.locked]    → amber "Cerrado" chip
/// - [ProdeFechaState.evaluated] → secondary "Finalizada" chip
/// - [ProdeFechaState.open] / [ProdeFechaState.unknown] → nothing
class _FechaBadge extends StatelessWidget {
  final ProdeFechaState state;

  const _FechaBadge({required this.state});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    String? label;
    Color? background;
    Color? foreground;

    switch (state) {
      case ProdeFechaState.locked:
        label = 'Cerrado';
        background = Colors.amber.shade100;
        foreground = Colors.orange.shade800;
      case ProdeFechaState.evaluated:
        label = 'Finalizada';
        background = theme.colorScheme.secondaryContainer;
        foreground = theme.colorScheme.onSecondaryContainer;
      case ProdeFechaState.open:
      case ProdeFechaState.unknown:
        return const SizedBox.shrink();
    }

    return Container(
      width: double.infinity,
      color: background,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      child: Text(
        label,
        style: theme.textTheme.labelMedium?.copyWith(color: foreground),
      ),
    );
  }
}

/// A single match row with score prediction inputs and a submit button.
///
/// Score inputs are seeded from [draft] on [initState] and [didUpdateWidget].
/// The Riverpod [draft] is the source of truth; [TextEditingController]s are
/// derived state, re-seeded on rebuild so scores survive ListView recycling.
///
/// Inputs are locked ([enabled: false]) and submit disabled when [isLocked]
/// is true (client-side UX check only — the server 423 is the security boundary).
///
/// Score values are validated client-side: numeric-only input, max 3 chars,
/// clamped to [0, 255] before calling [onUpdateDraft].
class _PredictionMatchTile extends StatefulWidget {
  final FechaMatch match;
  final PredictionDraft draft;
  final bool isLocked;
  final void Function(int? home, int? away) onUpdateDraft;
  final VoidCallback onSubmit;

  const _PredictionMatchTile({
    required this.match,
    required this.draft,
    required this.isLocked,
    required this.onUpdateDraft,
    required this.onSubmit,
  });

  @override
  State<_PredictionMatchTile> createState() => _PredictionMatchTileState();
}

class _PredictionMatchTileState extends State<_PredictionMatchTile> {
  late TextEditingController _homeCtrl;
  late TextEditingController _awayCtrl;

  @override
  void initState() {
    super.initState();
    _homeCtrl = TextEditingController(
      text: widget.draft.scoreHome?.toString() ?? '',
    );
    _awayCtrl = TextEditingController(
      text: widget.draft.scoreAway?.toString() ?? '',
    );
  }

  @override
  void didUpdateWidget(_PredictionMatchTile oldWidget) {
    super.didUpdateWidget(oldWidget);
    // Re-seed only when the draft value itself changed (not just status),
    // to avoid clobbering an in-progress keystroke.
    if (oldWidget.draft.scoreHome != widget.draft.scoreHome) {
      final newText = widget.draft.scoreHome?.toString() ?? '';
      if (_homeCtrl.text != newText) _homeCtrl.text = newText;
    }
    if (oldWidget.draft.scoreAway != widget.draft.scoreAway) {
      final newText = widget.draft.scoreAway?.toString() ?? '';
      if (_awayCtrl.text != newText) _awayCtrl.text = newText;
    }
  }

  @override
  void dispose() {
    _homeCtrl.dispose();
    _awayCtrl.dispose();
    super.dispose();
  }

  int? _parseAndClamp(String text) {
    final v = int.tryParse(text);
    if (v == null) return null;
    return v.clamp(0, 255);
  }

  void _onHomeChanged(String value) {
    final home = _parseAndClamp(value);
    final away = _parseAndClamp(_awayCtrl.text);
    widget.onUpdateDraft(home, away);
  }

  void _onAwayChanged(String value) {
    final home = _parseAndClamp(_homeCtrl.text);
    final away = _parseAndClamp(value);
    widget.onUpdateDraft(home, away);
  }

  bool get _canSubmit =>
      !widget.isLocked &&
      widget.draft.status != SubmitStatus.submitting;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final matchId = widget.match.matchId;
    final kickoff = DateFormat('dd/MM HH:mm').format(widget.match.kickoff);

    final inputDecoration = InputDecoration(
      isDense: true,
      contentPadding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
      border: const OutlineInputBorder(),
    );

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Team names row
              Row(
                children: [
                  Expanded(
                    child: Text(
                      widget.match.homeTeam,
                      style: theme.textTheme.titleMedium,
                      textAlign: TextAlign.right,
                    ),
                  ),
                  const Padding(
                    padding: EdgeInsets.symmetric(horizontal: 8),
                    child: Text('vs'),
                  ),
                  Expanded(
                    child: Text(
                      widget.match.awayTeam,
                      style: theme.textTheme.titleMedium,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              Text(kickoff, style: theme.textTheme.bodyMedium),
              const SizedBox(height: 8),
              // Score input row
              Row(
                children: [
                  SizedBox(
                    width: 60,
                    child: TextField(
                      key: Key('score_home_$matchId'),
                      controller: _homeCtrl,
                      enabled: !widget.isLocked,
                      decoration: inputDecoration,
                      keyboardType: TextInputType.number,
                      inputFormatters: [
                        FilteringTextInputFormatter.digitsOnly,
                        LengthLimitingTextInputFormatter(3),
                      ],
                      onChanged: _onHomeChanged,
                    ),
                  ),
                  const Padding(
                    padding: EdgeInsets.symmetric(horizontal: 8),
                    child: Text('-'),
                  ),
                  SizedBox(
                    width: 60,
                    child: TextField(
                      key: Key('score_away_$matchId'),
                      controller: _awayCtrl,
                      enabled: !widget.isLocked,
                      decoration: inputDecoration,
                      keyboardType: TextInputType.number,
                      inputFormatters: [
                        FilteringTextInputFormatter.digitsOnly,
                        LengthLimitingTextInputFormatter(3),
                      ],
                      onChanged: _onAwayChanged,
                    ),
                  ),
                  const SizedBox(width: 12),
                  ElevatedButton(
                    key: Key('submit_$matchId'),
                    onPressed: _canSubmit ? widget.onSubmit : null,
                    child: widget.draft.status == SubmitStatus.submitting
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Text('Submit'),
                  ),
                ],
              ),
              // Status feedback
              if (widget.draft.status == SubmitStatus.submitted)
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text(
                    'Prediction saved!',
                    style: TextStyle(
                      color: theme.colorScheme.primary,
                      fontSize: 12,
                    ),
                  ),
                ),
              if (widget.draft.status == SubmitStatus.error)
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text(
                    'Could not save. Try again.',
                    style: TextStyle(
                      color: theme.colorScheme.error,
                      fontSize: 12,
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

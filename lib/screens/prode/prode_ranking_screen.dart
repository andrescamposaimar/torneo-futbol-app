import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../models/prode_ranking.dart';
import '../../providers/prode_providers.dart';
import '../../services/prode_ranking_controller.dart';

// ---------------------------------------------------------------------------
// Container
// ---------------------------------------------------------------------------

/// Container for the Prode Ranking (leaderboard) screen.
///
/// Owns the provider wiring and lifecycle. Delegates rendering to the pure
/// [ProdeRankingView] that is Riverpod-free and trivially widget-testable.
///
/// Anonymous access note: this screen is reachable without authentication.
/// The controller's [fetchRanking] is an optionalAuth endpoint — no stale
/// banner, no onLogout callback (unlike [ProdeFixturesScreen]).
///
/// Mirrors [ProdeFixturesScreen]'s ConsumerStatefulWidget + initState
/// microtask pattern: [load] is triggered in initState via Future.microtask,
/// guarded on [ProdeRankingLoading] so re-entry while already Loaded/Empty/Error
/// does NOT clobber the existing state.
///
/// Owns its own Scaffold + AppBar (unlike ProdeFixturesScreen which nests
/// inside ProdeAuthGate's Scaffold) because this screen is pushed standalone
/// from MoreScreen via Navigator.push.
class ProdeRankingScreen extends ConsumerStatefulWidget {
  const ProdeRankingScreen({super.key});

  @override
  ConsumerState<ProdeRankingScreen> createState() =>
      _ProdeRankingScreenState();
}

class _ProdeRankingScreenState extends ConsumerState<ProdeRankingScreen> {
  @override
  void initState() {
    super.initState();
    // Only trigger load from the initial Loading state. Re-entry while
    // Loaded/Empty/Error must NOT clobber that state with a fresh fetch.
    if (ref.read(prodeRankingControllerProvider) is ProdeRankingLoading) {
      Future.microtask(() {
        if (mounted) {
          ref.read(prodeRankingControllerProvider.notifier).load();
        }
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(prodeRankingControllerProvider);
    final notifier = ref.read(prodeRankingControllerProvider.notifier);

    return Scaffold(
      appBar: AppBar(title: const Text('Tabla de posiciones')),
      body: ProdeRankingView(
        state: state,
        onRetry: notifier.load,
        onRefresh: notifier.refresh,
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Presentational view (Riverpod-free)
// ---------------------------------------------------------------------------

/// Pure presentational widget for the Prode Ranking screen.
///
/// Receives all data and callbacks as constructor params — no Riverpod reads
/// inside this widget. This makes it trivially unit-testable by pumping it
/// with a concrete [ProdeRankingState] and callbacks.
class ProdeRankingView extends StatelessWidget {
  final ProdeRankingState state;
  final VoidCallback onRetry;
  final Future<void> Function() onRefresh;

  const ProdeRankingView({
    super.key,
    required this.state,
    required this.onRetry,
    required this.onRefresh,
  });

  @override
  Widget build(BuildContext context) {
    return switch (state) {
      ProdeRankingLoading() =>
        const Center(child: CircularProgressIndicator()),
      ProdeRankingEmpty() => const _EmptyView(),
      ProdeRankingError() => _ErrorView(onRetry: onRetry),
      ProdeRankingLoaded(:final page) => RefreshIndicator(
          onRefresh: onRefresh,
          child: ListView.builder(
            physics: const AlwaysScrollableScrollPhysics(),
            itemCount: page.items.length,
            itemBuilder: (_, i) => _RankingRow(entry: page.items[i]),
          ),
        ),
    };
  }
}

// ---------------------------------------------------------------------------
// Per-state views (private — presentational details)
// ---------------------------------------------------------------------------

/// Shown when the ranking is empty (HTTP 200 but no entries yet).
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
            Icon(Icons.emoji_events_outlined,
                size: 64, color: theme.colorScheme.primary),
            const SizedBox(height: 16),
            Text(
              'Todavía no hay posiciones',
              textAlign: TextAlign.center,
              style: theme.textTheme.headlineSmall,
            ),
            const SizedBox(height: 8),
            const Text(
              'Cuando se jueguen los partidos vas a ver la tabla acá.',
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }
}

/// Shown when a transport/server error occurred.
///
/// Shows a generic friendly message — NEVER the raw [ProdeRankingError.message].
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
            Icon(Icons.error_outline,
                size: 64, color: theme.colorScheme.primary),
            const SizedBox(height: 16),
            Text('Algo salió mal', style: theme.textTheme.headlineSmall),
            const SizedBox(height: 8),
            const Text(
              'No pudimos cargar la tabla. Revisá tu conexión y reintentá en unos minutos.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 24),
            ElevatedButton(
              onPressed: onRetry,
              child: const Text('Reintentar'),
            ),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Row widget
// ---------------------------------------------------------------------------

/// A single leaderboard row showing rank badge, player name, exact count,
/// and total points.
///
/// The `is_me` row is visually distinguished via a tinted Container background
/// and bold display name (ADR-G5-8 — no auto-scroll).
///
/// The outermost widget carries `Key('ranking_row_${entry.userId}')` so
/// widget tests can locate rows by user id.
class _RankingRow extends StatelessWidget {
  final RankingEntry entry;

  const _RankingRow({required this.entry});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isMeColor = entry.isMe
        ? theme.colorScheme.primary.withValues(alpha: 0.12)
        : null;

    return Container(
      key: Key('ranking_row_${entry.userId}'),
      color: isMeColor,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      child: Row(
        children: [
          // Rank badge
          SizedBox(
            width: 36,
            height: 36,
            child: CircleAvatar(
              backgroundColor: theme.colorScheme.primaryContainer,
              child: Text(
                '${entry.rank}',
                style: TextStyle(
                  color: theme.colorScheme.onPrimaryContainer,
                  fontWeight: FontWeight.bold,
                  fontSize: 13,
                ),
              ),
            ),
          ),
          const SizedBox(width: 12),
          // Name + exact count
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  entry.displayName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontWeight:
                        entry.isMe ? FontWeight.bold : FontWeight.normal,
                  ),
                ),
                Text(
                  '${entry.exactCount} exactos',
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
              ],
            ),
          ),
          // Points
          Text(
            '${entry.totalPoints} pts',
            style: const TextStyle(fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }
}

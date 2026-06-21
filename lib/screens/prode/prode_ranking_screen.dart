import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../models/prode_ranking.dart';
import '../../providers/prode_providers.dart';
import '../../services/prode_ranking_controller.dart';
import '../../widgets/prode_segmented_toggle.dart';

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
/// inside ProdeAuthGate's Scaffold) because this screen is pushed standalone.
///
/// Two tabs via [ProdeSegmentedToggle]:
///   - "Fecha Actual" — ranking counting only the last evaluated fecha's points
///     ([prodeFechaRankingControllerProvider]).
///   - "General"      — season-cumulative ranking
///     ([prodeRankingControllerProvider]).
///
/// [initialTab] lets the caller deep-link to a specific tab (e.g. the Prode
/// summary card opens "Fecha Actual" or "General" depending on which half the
/// user tapped). 0 = Fecha Actual, 1 = General.
class ProdeRankingScreen extends ConsumerStatefulWidget {
  final int initialTab;

  const ProdeRankingScreen({super.key, this.initialTab = 0});

  @override
  ConsumerState<ProdeRankingScreen> createState() =>
      _ProdeRankingScreenState();
}

class _ProdeRankingScreenState extends ConsumerState<ProdeRankingScreen> {
  late int _tab = widget.initialTab;

  @override
  void initState() {
    super.initState();
    // Trigger each controller's load only from its initial Loading state so
    // re-entry does not clobber an already-loaded tab.
    Future.microtask(() {
      if (!mounted) return;
      if (ref.read(prodeFechaRankingControllerProvider)
          is ProdeRankingLoading) {
        ref.read(prodeFechaRankingControllerProvider.notifier).load();
      }
      if (ref.read(prodeRankingControllerProvider) is ProdeRankingLoading) {
        ref.read(prodeRankingControllerProvider.notifier).load();
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final fechaState = ref.watch(prodeFechaRankingControllerProvider);
    final generalState = ref.watch(prodeRankingControllerProvider);
    final fechaNotifier = ref.read(prodeFechaRankingControllerProvider.notifier);
    final generalNotifier = ref.read(prodeRankingControllerProvider.notifier);

    final showingFecha = _tab == 0;
    final state = showingFecha ? fechaState : generalState;
    // Both tear-offs share the Future<void> Function() signature, so the
    // ternary resolves cleanly even though the notifiers are different types.
    final Future<void> Function() onRefresh =
        showingFecha ? fechaNotifier.refresh : generalNotifier.refresh;
    final VoidCallback onRetry =
        showingFecha ? fechaNotifier.load : generalNotifier.load;

    return Scaffold(
      appBar: AppBar(title: const Text('Ranking')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: ProdeSegmentedToggle(
              labels: const ['Fecha Actual', 'General'],
              selectedIndex: _tab,
              onChanged: (i) => setState(() => _tab = i),
            ),
          ),
          Expanded(
            child: ProdeRankingView(
              // Key forces a subtree swap so the RefreshIndicator/list state of
              // one tab never bleeds into the other.
              key: ValueKey(showingFecha ? 'ranking_fecha' : 'ranking_general'),
              state: state,
              onRetry: onRetry,
              onRefresh: onRefresh,
            ),
          ),
        ],
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

/// A single leaderboard row showing rank badge, user photo, player name,
/// team/club, and total points.
///
/// Layout (left → right):
///   [rank badge] [user photo] [name / team column] ... [pts]
///
/// The user photo is a [CircleAvatar] using [NetworkImage] when [entry.avatarUrl]
/// is non-empty, falling back to the first letter of [entry.displayName] as
/// initials on missing URL or image-load error — mirroring the pattern in
/// `ProdeIdentityCard._buildAuthenticatedTile`.
///
/// The subtitle line shows [entry.teamName] when non-empty, or the literal
/// `'Sin Equipo'` otherwise. The old "N exactos" subtitle has been removed.
///
/// The `is_me` row is visually distinguished via a tinted Container background
/// and bold display name (ADR-G5-8 — no auto-scroll).
///
/// The outermost widget carries `Key('ranking_row_${entry.userId}')` so
/// widget tests can locate rows by user id. The avatar carries
/// `ValueKey('ranking_avatar_photo_${entry.userId}')` or
/// `ValueKey('ranking_avatar_initials_${entry.userId}')` so tests can assert
/// which variant is rendered.
class _RankingRow extends StatelessWidget {
  final RankingEntry entry;

  const _RankingRow({required this.entry});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isMeColor = entry.isMe
        ? theme.colorScheme.primary.withValues(alpha: 0.12)
        : null;

    final hasPhoto =
        entry.avatarUrl != null && entry.avatarUrl!.isNotEmpty;

    // Initials child — always present; shown when photo is absent or fails.
    final initialsChild = Text(
      entry.displayName.isNotEmpty
          ? entry.displayName[0].toUpperCase()
          : '?',
      style: TextStyle(
        color: theme.colorScheme.primary,
        fontWeight: FontWeight.bold,
        fontSize: 13,
      ),
    );

    final Widget userAvatar = CircleAvatar(
      key: hasPhoto
          ? ValueKey('ranking_avatar_photo_${entry.userId}')
          : ValueKey('ranking_avatar_initials_${entry.userId}'),
      radius: 18,
      foregroundImage: hasPhoto ? NetworkImage(entry.avatarUrl!) : null,
      // Swallow load errors — initials remain visible underneath.
      onForegroundImageError: hasPhoto ? (_, __) {} : null,
      backgroundColor: theme.colorScheme.primary.withValues(alpha: 0.15),
      child: initialsChild,
    );

    final teamLabel =
        (entry.teamName != null && entry.teamName!.isNotEmpty)
            ? entry.teamName!
            : 'Sin Equipo';

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
          const SizedBox(width: 8),
          // User photo
          userAvatar,
          const SizedBox(width: 10),
          // Name + team
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
                  teamLabel,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
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

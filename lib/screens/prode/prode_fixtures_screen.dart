import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../models/fecha_activa.dart';
import '../../models/fecha_summary.dart';
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
      ProdeFixturesLoaded(
        :final fecha,
        :final drafts,
        :final savedMatchIds,
        :final fechas,
        :final selectedFechaId,
        :final isFechaLoading,
        :final fechaLoadError,
      ) =>
        _LoadedView(
          fecha: fecha,
          drafts: drafts,
          savedMatchIds: savedMatchIds,
          fechas: fechas,
          selectedFechaId: selectedFechaId,
          isFechaLoading: isFechaLoading,
          fechaLoadError: fechaLoadError,
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
/// G6-e: renders [_FechaSelectorRow] above the progress header when the
/// fechas list is non-empty. A scoped loader ([fecha_load_spinner]) or inline
/// error ([fecha_load_retry]) replaces the card list while the selection is
/// in flight or has failed. The prediction modal lock gate is now driven by
/// the selected [FechaSummary.state] rather than only the client-side lockedAt.
class _LoadedView extends ConsumerWidget {
  final FechaActiva fecha;
  final Map<int, PredictionDraft> drafts;
  final Set<int> savedMatchIds;
  final List<FechaSummary> fechas;
  final int selectedFechaId;
  final bool isFechaLoading;
  final ProdeFixturesFechaError? fechaLoadError;
  final bool stale;
  final VoidCallback onLogout;
  final Future<void> Function() onRefresh;

  const _LoadedView({
    required this.fecha,
    required this.drafts,
    required this.savedMatchIds,
    required this.fechas,
    required this.selectedFechaId,
    required this.isFechaLoading,
    required this.fechaLoadError,
    required this.stale,
    required this.onLogout,
    required this.onRefresh,
  });

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final controller = ref.read(prodeFixturesControllerProvider.notifier);

    // G6-e: lock gate derives from the selected FechaSummary.state when
    // available, falling back to the legacy client-side lockedAt check.
    final selectedSummary = fechas.isEmpty
        ? null
        : fechas.cast<FechaSummary?>().firstWhere(
              (f) => f!.fechaId == selectedFechaId,
              orElse: () => null,
            );

    final bool isLockedByState = selectedSummary != null &&
        (selectedSummary.state == ProdeFechaState.locked ||
            selectedSummary.state == ProdeFechaState.evaluated);

    // Legacy UX-only lock check (pre-G6-e). The summary-state check above
    // takes precedence; this is kept as belt-and-suspenders for the
    // "open but lockedAt in past" edge case.
    final isLockedByTime =
        fecha.lockedAt != null && !DateTime.now().isBefore(fecha.lockedAt!);

    final isLocked = isLockedByState || isLockedByTime;

    final totalCount = fecha.matches.length;
    final predictedCount =
        fecha.matches.where((m) => savedMatchIds.contains(m.matchId)).length;

    // Current 0-based index of the selected fecha in the list.
    final selectedIndex = fechas.indexWhere((f) => f.fechaId == selectedFechaId);

    return Column(
      children: [
        if (stale) const _StaleBanner(),
        _FechaBadge(state: fecha.state),
        // G6-e: selector row above progress header, only when list is non-empty.
        if (fechas.isNotEmpty)
          _FechaSelectorRow(
            fechas: fechas,
            selectedIndex: selectedIndex,
            onPrev: selectedIndex > 0
                ? () => controller.selectFecha(fechas[selectedIndex - 1].fechaId)
                : null,
            onNext: selectedIndex < fechas.length - 1
                ? () => controller.selectFecha(fechas[selectedIndex + 1].fechaId)
                : null,
            onSelect: (id) => controller.selectFecha(id),
          ),
        // Progress header
        if (totalCount > 0 && !isFechaLoading && fechaLoadError == null)
          _ProgressHeader(
            predictedCount: predictedCount,
            totalCount: totalCount,
          ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: onRefresh,
            child: _buildCardArea(context, controller, isLocked),
          ),
        ),
      ],
    );
  }

  Widget _buildCardArea(
    BuildContext context,
    ProdeFixturesController controller,
    bool isLocked,
  ) {
    // G6-e: scoped loading indicator.
    if (isFechaLoading) {
      return const Center(
        child: CircularProgressIndicator(key: Key('fecha_load_spinner')),
      );
    }

    // G6-e: inline error with retry.
    if (fechaLoadError != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text('No pudimos cargar esta fecha.'),
              const SizedBox(height: 12),
              TextButton(
                key: const Key('fecha_load_retry'),
                onPressed: () => controller.selectFecha(fechaLoadError!.fechaId),
                child: const Text('Reintentar'),
              ),
            ],
          ),
        ),
      );
    }

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        const Padding(
          padding: EdgeInsets.fromLTRB(16, 12, 16, 4),
          child: Text(
            'PRÓXIMOS PARTIDOS',
            style: TextStyle(
              fontWeight: FontWeight.bold,
              fontSize: 13,
              letterSpacing: 0.5,
            ),
          ),
        ),
        if (fecha.matches.isEmpty)
          const Padding(
            padding: EdgeInsets.all(24),
            child: Center(child: Text('Sin partidos en esta fecha.')),
          )
        else
          ...fecha.matches.map((m) => _MatchCard(
                match: m,
                draft: drafts[m.matchId] ?? const PredictionDraft(),
                isSaved: savedMatchIds.contains(m.matchId),
                isLocked: isLocked,
                onTap: () => _openPredictionSheet(
                  context,
                  match: m,
                  draft: drafts[m.matchId] ?? const PredictionDraft(),
                  isLocked: isLocked,
                  controller: controller,
                ),
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
    );
  }

  void _openPredictionSheet(
    BuildContext context, {
    required FechaMatch match,
    required PredictionDraft draft,
    required bool isLocked,
    required ProdeFixturesController controller,
  }) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => _PredictionSheet(
        match: match,
        initialDraft: draft,
        isLocked: isLocked,
        controller: controller,
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Progress header widget
// ---------------------------------------------------------------------------

/// "Pronósticos X/Y" header with a [LinearProgressIndicator].
class _ProgressHeader extends StatelessWidget {
  final int predictedCount;
  final int totalCount;

  const _ProgressHeader({
    required this.predictedCount,
    required this.totalCount,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final progress = totalCount > 0 ? predictedCount / totalCount : 0.0;

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'Pronósticos',
                style: theme.textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w600,
                ),
              ),
              Text(
                '$predictedCount/$totalCount',
                style: theme.textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.bold,
                  color: theme.colorScheme.primary,
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          LinearProgressIndicator(
            value: progress,
            backgroundColor: theme.colorScheme.primary.withAlpha(30),
            color: theme.colorScheme.primary,
            minHeight: 6,
            borderRadius: BorderRadius.circular(4),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Fecha selector row  (G6-e)
// ---------------------------------------------------------------------------

/// "< Fecha N ⌄ >" row rendered above the progress header when the season
/// has multiple fechas. Arrows navigate to adjacent fechas; the label opens
/// a bottom sheet with all fechas listed.
class _FechaSelectorRow extends StatelessWidget {
  final List<FechaSummary> fechas;

  /// 0-based index of the currently selected fecha.
  final int selectedIndex;

  /// Called when the user taps `<` to go to the previous fecha.
  /// Null when [selectedIndex] is 0 (arrow is disabled).
  final VoidCallback? onPrev;

  /// Called when the user taps `>` to go to the next fecha.
  /// Null when [selectedIndex] is the last index (arrow is disabled).
  final VoidCallback? onNext;

  /// Called when the user picks a fecha from the bottom sheet.
  final void Function(int fechaId) onSelect;

  const _FechaSelectorRow({
    required this.fechas,
    required this.selectedIndex,
    required this.onPrev,
    required this.onNext,
    required this.onSelect,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final primary = theme.colorScheme.primary;
    // N is 1-based.
    final n = selectedIndex >= 0 ? selectedIndex + 1 : 1;
    final disabledColor = Colors.grey.shade400;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          // Previous arrow
          InkWell(
            key: const Key('fecha_selector_prev'),
            onTap: onPrev,
            borderRadius: BorderRadius.circular(20),
            child: Padding(
              padding: const EdgeInsets.all(8),
              child: Icon(
                Icons.chevron_left,
                color: onPrev != null ? primary : disabledColor,
              ),
            ),
          ),
          // Label — tapping opens the picker bottom sheet
          InkWell(
            key: const Key('fecha_selector_label'),
            onTap: () => _openPicker(context),
            borderRadius: BorderRadius.circular(8),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              child: Text(
                'Fecha $n  ⌄',
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w600,
                  color: primary,
                ),
              ),
            ),
          ),
          // Next arrow
          InkWell(
            key: const Key('fecha_selector_next'),
            onTap: onNext,
            borderRadius: BorderRadius.circular(20),
            child: Padding(
              padding: const EdgeInsets.all(8),
              child: Icon(
                Icons.chevron_right,
                color: onNext != null ? primary : disabledColor,
              ),
            ),
          ),
        ],
      ),
    );
  }

  void _openPicker(BuildContext context) {
    showModalBottomSheet<void>(
      context: context,
      builder: (_) => _FechaPickerSheet(
        fechas: fechas,
        selectedIndex: selectedIndex,
        onSelect: (id) {
          Navigator.of(context).pop();
          onSelect(id);
        },
      ),
    );
  }
}

/// Bottom sheet showing all fechas for the picker.
class _FechaPickerSheet extends StatelessWidget {
  final List<FechaSummary> fechas;
  final int selectedIndex;
  final void Function(int fechaId) onSelect;

  const _FechaPickerSheet({
    required this.fechas,
    required this.selectedIndex,
    required this.onSelect,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const SizedBox(height: 12),
          Text(
            'Seleccionar fecha',
            style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold),
          ),
          const Divider(),
          ...fechas.asMap().entries.map((entry) {
            final i = entry.key;
            final f = entry.value;
            final isSelected = i == selectedIndex;
            return ListTile(
              key: Key('fecha_picker_entry_${f.fechaId}'),
              title: Text('Fecha ${i + 1}'),
              selected: isSelected,
              trailing: isSelected
                  ? Icon(Icons.check, color: theme.colorScheme.primary)
                  : null,
              onTap: () => onSelect(f.fechaId),
            );
          }),
          const SizedBox(height: 8),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Match card (tappable, shows score boxes + status icon)
// ---------------------------------------------------------------------------

/// Tappable match card. Tapping anywhere opens [_PredictionSheet].
///
/// Layout:
///   Header row: kickoff + zona | status icon
///   Divider
///   Body row: [home escudo+name] | [home score box] - [away score box] | [away escudo+name]
class _MatchCard extends StatelessWidget {
  final FechaMatch match;
  final PredictionDraft draft;
  final bool isSaved;
  final bool isLocked;
  final VoidCallback onTap;

  const _MatchCard({
    required this.match,
    required this.draft,
    required this.isSaved,
    required this.isLocked,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final primary = theme.colorScheme.primary;

    // Format: "Dom. 07/06 - 14:00" (abbreviated weekday, capitalized)
    final kickoffFormatted = _formatKickoff(match.kickoff);

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      child: Card(
        color: Colors.white,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: BorderSide(color: Colors.grey.shade200),
        ),
        elevation: 1,
        child: InkWell(
          key: Key('match_card_${match.matchId}'),
          borderRadius: BorderRadius.circular(12),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Header row: kickoff/zona + status icon
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            kickoffFormatted,
                            style: theme.textTheme.bodySmall?.copyWith(
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          if (match.zona.isNotEmpty)
                            Text(
                              match.zona,
                              style: theme.textTheme.bodySmall?.copyWith(
                                color: Colors.grey.shade500,
                                fontSize: 11,
                              ),
                              overflow: TextOverflow.ellipsis,
                              maxLines: 1,
                            ),
                        ],
                      ),
                    ),
                    // Status icon
                    if (isSaved)
                      Icon(
                        key: Key('status_icon_saved_${match.matchId}'),
                        Icons.check_box_outlined,
                        color: primary,
                        size: 20,
                      )
                    else if (isLocked)
                      Icon(
                        key: Key('status_icon_locked_${match.matchId}'),
                        Icons.lock_outline,
                        color: Colors.grey.shade400,
                        size: 20,
                      )
                    else
                      Icon(
                        key: Key('status_icon_pending_${match.matchId}'),
                        Icons.indeterminate_check_box_outlined,
                        color: Colors.grey.shade400,
                        size: 20,
                      ),
                  ],
                ),
                const SizedBox(height: 8),
                const Divider(height: 1, thickness: 1),
                const SizedBox(height: 8),
                // Body row: home | score boxes | away
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    // Home side
                    Expanded(
                      child: Column(
                        children: [
                          _EscudoImage(url: match.homeEscudo, size: 40),
                          const SizedBox(height: 4),
                          Text(
                            match.homeTeam,
                            textAlign: TextAlign.center,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodySmall?.copyWith(
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                    ),
                    // Score display boxes
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 8),
                      child: Row(
                        children: [
                          _ScoreDisplayBox(
                            value: draft.scoreHome,
                            primaryColor: primary,
                          ),
                          const Padding(
                            padding: EdgeInsets.symmetric(horizontal: 4),
                            child: Text(
                              '-',
                              style: TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 16,
                              ),
                            ),
                          ),
                          _ScoreDisplayBox(
                            value: draft.scoreAway,
                            primaryColor: primary,
                          ),
                        ],
                      ),
                    ),
                    // Away side
                    Expanded(
                      child: Column(
                        children: [
                          _EscudoImage(url: match.awayEscudo, size: 40),
                          const SizedBox(height: 4),
                          Text(
                            match.awayTeam,
                            textAlign: TextAlign.center,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodySmall?.copyWith(
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// Formats kickoff as "Dom. 07/06 - 14:00".
  ///
  /// Uses a manual Spanish weekday abbreviation to avoid requiring
  /// [initializeDateFormatting] in widget tests (bootstrap.dart handles it
  /// in production via `await initializeDateFormatting('es')`).
  String _formatKickoff(DateTime kickoff) {
    const weekdays = ['Lun.', 'Mar.', 'Mié.', 'Jue.', 'Vie.', 'Sáb.', 'Dom.'];
    final dayLabel = weekdays[kickoff.weekday - 1]; // weekday: 1=Mon, 7=Sun
    final rest = DateFormat('dd/MM - HH:mm').format(kickoff);
    return '$dayLabel $rest';
  }
}

// ---------------------------------------------------------------------------
// Score display box (read-only, shown on the card)
// ---------------------------------------------------------------------------

/// Read-only score display for the match card.
/// Shows the draft score value or an em dash when null.
class _ScoreDisplayBox extends StatelessWidget {
  final int? value;
  final Color primaryColor;

  const _ScoreDisplayBox({required this.value, required this.primaryColor});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 36,
      height: 36,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(6),
        border: Border.all(
          color: value != null ? primaryColor : Colors.grey.shade300,
          width: value != null ? 1.5 : 1.0,
        ),
      ),
      child: Center(
        child: Text(
          value != null ? value.toString() : '—',
          style: TextStyle(
            fontWeight: FontWeight.bold,
            fontSize: 15,
            color: value != null ? primaryColor : Colors.grey.shade400,
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Escudo image with error fallback
// ---------------------------------------------------------------------------

/// Renders a team escudo (shield/logo) from a URL.
/// Falls back to a grey shield icon when URL is null or load fails.
class _EscudoImage extends StatelessWidget {
  final String? url;
  final double size;

  const _EscudoImage({required this.url, this.size = 40});

  @override
  Widget build(BuildContext context) {
    if (url == null || url!.isEmpty) {
      return _placeholder();
    }
    return Image.network(
      url!,
      width: size,
      height: size,
      fit: BoxFit.contain,
      errorBuilder: (_, __, ___) => _placeholder(),
    );
  }

  Widget _placeholder() => SizedBox(
        width: size,
        height: size,
        child: Icon(Icons.shield, size: size * 0.7, color: Colors.grey.shade300),
      );
}

// ---------------------------------------------------------------------------
// Stale-session banner
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Fecha badge
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Prediction sheet (modal bottom sheet)
// ---------------------------------------------------------------------------

/// Modal bottom sheet for entering or editing a match prediction.
///
/// Opened via [showModalBottomSheet]. Holds local score state initialized
/// from the existing [initialDraft] (or 0/0 when no prior prediction).
///
/// When [isLocked] is true, steppers and the GUARDAR button are disabled.
class _PredictionSheet extends StatefulWidget {
  final FechaMatch match;
  final PredictionDraft initialDraft;
  final bool isLocked;
  final ProdeFixturesController controller;

  const _PredictionSheet({
    required this.match,
    required this.initialDraft,
    required this.isLocked,
    required this.controller,
  });

  @override
  State<_PredictionSheet> createState() => _PredictionSheetState();
}

class _PredictionSheetState extends State<_PredictionSheet> {
  late int _homeScore;
  late int _awayScore;
  bool _submitting = false;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _homeScore = widget.initialDraft.scoreHome ?? 0;
    _awayScore = widget.initialDraft.scoreAway ?? 0;
  }

  int _clamp(int value) => value.clamp(0, 255);

  void _incrementHome() => setState(() => _homeScore = _clamp(_homeScore + 1));
  void _decrementHome() => setState(() => _homeScore = _clamp(_homeScore - 1));
  void _incrementAway() => setState(() => _awayScore = _clamp(_awayScore + 1));
  void _decrementAway() => setState(() => _awayScore = _clamp(_awayScore - 1));

  Future<void> _onGuardar() async {
    if (widget.isLocked || _submitting) return;

    setState(() {
      _submitting = true;
      _errorMessage = null;
    });

    widget.controller.updateDraft(
      widget.match.matchId,
      scoreHome: _homeScore,
      scoreAway: _awayScore,
    );

    // submitPrediction returns true on success, false on error/no-op.
    // This avoids reading the protected StateNotifier.state from outside the notifier.
    final success =
        await widget.controller.submitPrediction(widget.match.matchId);

    if (!mounted) return;

    if (success) {
      Navigator.of(context).pop();
    } else {
      setState(() {
        _submitting = false;
        _errorMessage = 'No se pudo guardar. Intentá de nuevo.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final primary = theme.colorScheme.primary;
    final matchId = widget.match.matchId;
    final canInteract = !widget.isLocked && !_submitting;

    return Padding(
      padding: EdgeInsets.only(
        bottom: MediaQuery.of(context).viewInsets.bottom,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          // Drag handle
          const SizedBox(height: 12),
          Center(
            child: Container(
              width: 40,
              height: 4,
              decoration: BoxDecoration(
                color: Colors.grey.shade300,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
          ),
          const SizedBox(height: 16),

          _PopularesSection(
            populares: widget.match.populares,
            isLocked: widget.isLocked,
            matchId: matchId,
            primaryColor: primary,
          ),

          // Score stepper row
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 24),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                // Home team
                Expanded(
                  child: Column(
                    children: [
                      _EscudoImage(url: widget.match.homeEscudo, size: 48),
                      const SizedBox(height: 4),
                      Text(
                        widget.match.homeTeam,
                        textAlign: TextAlign.center,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodySmall
                            ?.copyWith(fontWeight: FontWeight.w600),
                      ),
                    ],
                  ),
                ),
                // Home stepper
                _ScoreStepper(
                  matchId: matchId,
                  side: 'home',
                  value: _homeScore,
                  enabled: canInteract,
                  onIncrement: canInteract ? _incrementHome : null,
                  onDecrement: canInteract ? _decrementHome : null,
                  primaryColor: primary,
                ),
                const SizedBox(width: 8),
                // Away stepper
                _ScoreStepper(
                  matchId: matchId,
                  side: 'away',
                  value: _awayScore,
                  enabled: canInteract,
                  onIncrement: canInteract ? _incrementAway : null,
                  onDecrement: canInteract ? _decrementAway : null,
                  primaryColor: primary,
                ),
                // Away team
                Expanded(
                  child: Column(
                    children: [
                      _EscudoImage(url: widget.match.awayEscudo, size: 48),
                      const SizedBox(height: 4),
                      Text(
                        widget.match.awayTeam,
                        textAlign: TextAlign.center,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodySmall
                            ?.copyWith(fontWeight: FontWeight.w600),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),

          // Error message
          if (_errorMessage != null)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 24),
              child: Text(
                _errorMessage!,
                style: TextStyle(color: theme.colorScheme.error, fontSize: 13),
                textAlign: TextAlign.center,
              ),
            ),
          if (_errorMessage != null) const SizedBox(height: 8),

          // GUARDAR button
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
            child: SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                key: Key('guardar_$matchId'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: primary,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                  ),
                ),
                onPressed: canInteract ? _onGuardar : null,
                child: _submitting
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: Colors.white,
                        ),
                      )
                    : const Text(
                        'GUARDAR',
                        style: TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 15,
                          letterSpacing: 0.5,
                        ),
                      ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Populares section widget
// ---------------------------------------------------------------------------

/// Displays the "Pronósticos populares" block inside [_PredictionSheet].
///
/// Reveal condition: [isLocked] == true AND [populares] != null.
/// In all other states (open fecha OR no populares data) the locked
/// presentation is shown — [isLocked] always takes precedence (POP-3-c).
class _PopularesSection extends StatelessWidget {
  final Populares? populares;
  final bool isLocked;
  final int matchId;
  final Color primaryColor;

  const _PopularesSection({
    required this.populares,
    required this.isLocked,
    required this.matchId,
    required this.primaryColor,
  });

  @override
  Widget build(BuildContext context) {
    final reveal = isLocked && populares != null;

    int? homePercent;
    int? drawPercent;
    int? awayPercent;
    if (reveal) {
      homePercent = (populares!.home * 100).round();
      drawPercent = (populares!.draw * 100).round();
      awayPercent = (populares!.away * 100).round();
    }

    return Container(
      key: Key('populares_section_$matchId'),
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Header row
          Row(
            children: [
              Icon(Icons.workspace_premium, size: 18, color: primaryColor),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  'Pronósticos populares',
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    color: primaryColor,
                  ),
                ),
              ),
              Semantics(
                label: 'Información sobre pronósticos populares',
                child: Tooltip(
                  message: 'Se revelan cuando cierra la fecha',
                  child: IconButton(
                    icon: const Icon(Icons.info_outline, size: 18),
                    color: Colors.grey.shade500,
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(),
                    onPressed: () {},
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          // Chip row
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
            children: [
              _PopularesChip(
                key: Key('populares_chip_1_$matchId'),
                label: '1',
                percent: homePercent,
                primaryColor: primaryColor,
              ),
              _PopularesChip(
                key: Key('populares_chip_X_$matchId'),
                label: 'X',
                percent: drawPercent,
                primaryColor: primaryColor,
              ),
              _PopularesChip(
                key: Key('populares_chip_2_$matchId'),
                label: '2',
                percent: awayPercent,
                primaryColor: primaryColor,
              ),
            ],
          ),
          // Locked hint — visible when not revealing percentages.
          if (!reveal) ...[
            const SizedBox(height: 6),
            Center(
              child: Text(
                key: const Key('populares_locked_hint'),
                'Se revelan cuando cierra la fecha',
                style: TextStyle(
                  fontSize: 11,
                  color: Colors.grey.shade500,
                  fontStyle: FontStyle.italic,
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// A single outcome chip in the populares section.
///
/// When [percent] is non-null (reveal mode), shows the label and percentage.
/// When [percent] is null (locked mode), shows a lock icon in grey.
class _PopularesChip extends StatelessWidget {
  final String label; // '1', 'X', or '2'
  final int? percent; // null → locked presentation
  final Color primaryColor;

  const _PopularesChip({
    super.key,
    required this.label,
    required this.percent,
    required this.primaryColor,
  });

  @override
  Widget build(BuildContext context) {
    final isRevealed = percent != null;
    final color = isRevealed ? primaryColor : Colors.grey.shade400;

    return Container(
      width: 72,
      padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 4),
      decoration: BoxDecoration(
        border: Border.all(color: color, width: 1.5),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            label,
            style: TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.bold,
              color: color,
            ),
          ),
          const SizedBox(height: 2),
          if (isRevealed)
            Text(
              '${percent!}%',
              style: TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w600,
                color: color,
              ),
            )
          else
            Icon(Icons.lock_outline, size: 14, color: color),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Score stepper widget
// ---------------------------------------------------------------------------

/// +/value/- stepper for the prediction modal.
///
/// Uses semantic keys so tests can drive interactions:
///   `stepper_{side}_plus_{matchId}`
///   `stepper_{side}_minus_{matchId}`
///   `stepper_{side}_value_{matchId}`
class _ScoreStepper extends StatelessWidget {
  final int matchId;
  final String side; // 'home' or 'away'
  final int value;
  final bool enabled;
  final VoidCallback? onIncrement;
  final VoidCallback? onDecrement;
  final Color primaryColor;

  const _ScoreStepper({
    required this.matchId,
    required this.side,
    required this.value,
    required this.enabled,
    required this.onIncrement,
    required this.onDecrement,
    required this.primaryColor,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        IconButton(
          key: Key('stepper_${side}_plus_$matchId'),
          icon: const Icon(Icons.add_circle_outline),
          color: enabled ? primaryColor : Colors.grey.shade300,
          iconSize: 28,
          onPressed: onIncrement,
          padding: EdgeInsets.zero,
          constraints: const BoxConstraints(),
        ),
        const SizedBox(height: 2),
        Container(
          width: 44,
          height: 44,
          decoration: BoxDecoration(
            border: Border.all(
              color: enabled ? primaryColor : Colors.grey.shade300,
              width: 1.5,
            ),
            borderRadius: BorderRadius.circular(8),
          ),
          child: Center(
            child: Text(
              key: Key('stepper_${side}_value_$matchId'),
              value.toString(),
              style: TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 18,
                color: enabled ? primaryColor : Colors.grey.shade400,
              ),
            ),
          ),
        ),
        const SizedBox(height: 2),
        IconButton(
          key: Key('stepper_${side}_minus_$matchId'),
          icon: const Icon(Icons.remove_circle_outline),
          color: enabled ? primaryColor : Colors.grey.shade300,
          iconSize: 28,
          onPressed: onDecrement,
          padding: EdgeInsets.zero,
          constraints: const BoxConstraints(),
        ),
      ],
    );
  }
}

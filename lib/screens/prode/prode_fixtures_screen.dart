import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../models/fecha_activa.dart';
import '../../models/fecha_summary.dart';
import '../../models/prediction_history.dart';
import '../../models/prode_ranking.dart';
import '../../providers/prode_providers.dart';
import '../../services/prode_fixtures_controller.dart';
import '../../services/prode_history_controller.dart';
import '../../services/prode_ranking_controller.dart';
import '../../widgets/prode_segmented_toggle.dart';
import 'prediction_result_style.dart';
import 'prode_ranking_screen.dart';

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

  /// When true, renders ONLY the open-fecha ("A Jugarse") content with no
  /// internal "A Jugarse / Finalizados" TabBar. Used when embedded inside
  /// [ProdeChamiScreen], whose own segmented control owns the
  /// Anteriores/A-Jugarse switch. Defaults to false → the standalone two-tab
  /// layout (unchanged behavior for existing callers/tests).
  final bool openOnly;

  const ProdeFixturesScreen({
    super.key,
    required this.stale,
    required this.onLogout,
    this.openOnly = false,
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
      openOnly: widget.openOnly,
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

  /// See [ProdeFixturesScreen.openOnly].
  final bool openOnly;

  const ProdeFixturesView({
    super.key,
    required this.state,
    required this.stale,
    required this.onLogout,
    required this.onRetry,
    required this.onRefresh,
    this.openOnly = false,
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
          openOnly: openOnly,
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
/// T-13: renders a two-tab layout ("A Jugarse" / "Finalizados") with
/// per-tab fecha filtering. "A Jugarse" shows only open fechas; "Finalizados"
/// shows locked + evaluated fechas.
///
/// G6-e: each tab's [_FechaSelectorRow] operates on its own filtered list so
/// the picker never shows cross-tab entries.
class _LoadedView extends ConsumerStatefulWidget {
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
  final bool openOnly;

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
    this.openOnly = false,
  });

  @override
  ConsumerState<_LoadedView> createState() => _LoadedViewState();
}

class _LoadedViewState extends ConsumerState<_LoadedView>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;

  @override
  void initState() {
    super.initState();
    // Default tab: "A Jugarse" (index 0) when open fechas exist, else
    // "Finalizados" (index 1). Computed once on first build.
    final hasOpen = widget.fechas.any((f) => f.state == ProdeFechaState.open);
    _tabController = TabController(
      length: 2,
      vsync: this,
      initialIndex: hasOpen ? 0 : 1,
    );
    _tabController.addListener(_onTabChanged);
  }

  @override
  void dispose() {
    _tabController.removeListener(_onTabChanged);
    _tabController.dispose();
    super.dispose();
  }

  /// Called on every animation tick and on settled tab changes.
  ///
  /// Guards with [TabController.indexIsChanging]: fires once when the tab
  /// animation has settled so we don't trigger multiple selectFecha calls
  /// during the swipe animation.
  ///
  /// When the newly-active tab's filtered fecha list does NOT contain the
  /// controller's current [selectedFechaId], auto-selects the tab's default
  /// fecha:
  ///   - "A Jugarse" (index 0): first open fecha.
  ///   - "Finalizados" (index 1): last locked|evaluated fecha (most recent,
  ///     because the list is ordered locked_at ASC from the backend).
  void _onTabChanged() {
    if (_tabController.indexIsChanging) return;
    if (!mounted) return;

    // Read current widget props inside the callback so we always see the
    // latest values (widget is rebuilt with new props on state changes).
    final fechas = widget.fechas;
    final selectedFechaId = widget.selectedFechaId;

    final aJugarse =
        fechas.where((f) => f.state == ProdeFechaState.open).toList();
    final finalizados = fechas
        .where((f) =>
            f.state == ProdeFechaState.locked ||
            f.state == ProdeFechaState.evaluated)
        .toList();

    final tabFechas = _tabController.index == 0 ? aJugarse : finalizados;

    // If the tab's list is empty, nothing to select.
    if (tabFechas.isEmpty) return;

    // If the current selection already belongs to this tab, no work needed.
    final alreadyInTab = tabFechas.any((f) => f.fechaId == selectedFechaId);
    if (alreadyInTab) return;

    // Auto-select the tab's default:
    //   "A Jugarse"  → first open fecha (earliest upcoming).
    //   "Finalizados"→ last finished fecha (most recent, ordered locked_at ASC).
    final defaultId = _tabController.index == 0
        ? tabFechas.first.fechaId
        : tabFechas.last.fechaId;

    ref.read(prodeFixturesControllerProvider.notifier).selectFecha(defaultId);
  }

  @override
  Widget build(BuildContext context) {
    final controller = ref.read(prodeFixturesControllerProvider.notifier);

    // G6-e: lock gate derives from the selected FechaSummary.state when
    // available, falling back to the legacy client-side lockedAt check.
    final selectedSummary = widget.fechas.isEmpty
        ? null
        : widget.fechas.cast<FechaSummary?>().firstWhere(
              (f) => f!.fechaId == widget.selectedFechaId,
              orElse: () => null,
            );

    final bool isLockedByState = selectedSummary != null &&
        (selectedSummary.state == ProdeFechaState.locked ||
            selectedSummary.state == ProdeFechaState.evaluated);

    // Legacy UX-only lock check (pre-G6-e). The summary-state check above
    // takes precedence; this is kept as belt-and-suspenders for the
    // "open but lockedAt in past" edge case.
    final isLockedByTime = widget.fecha.lockedAt != null &&
        !DateTime.now().isBefore(widget.fecha.lockedAt!);

    final isLocked = isLockedByState || isLockedByTime;

    final totalCount = widget.fecha.matches.length;
    final predictedCount = widget.fecha.matches
        .where((m) => widget.savedMatchIds.contains(m.matchId))
        .length;

    // T-13: split fechas into two buckets — client-side, no backend calls.
    // Only applies when fechas list is non-empty; otherwise fall back to
    // the single-view layout (no tabs, no selector) for compatibility with
    // states that don't carry a full summary list.
    final hasFechaList = widget.fechas.isNotEmpty;
    final aJugarse = widget.fechas
        .where((f) => f.state == ProdeFechaState.open)
        .toList();
    final finalizados = widget.fechas
        .where((f) =>
            f.state == ProdeFechaState.locked ||
            f.state == ProdeFechaState.evaluated)
        .toList();
    // openOnly ("A Jugarse" inside Prode Chami) surfaces both predictable and
    // in-play fechas: open (still bettable) and locked (window closed, not yet
    // evaluated). The locked ones reveal the populares percentages and tag each
    // match with an "En Juego" label. Evaluated fechas stay out (they belong to
    // the history list). This is intentionally separate from [aJugarse], which
    // still drives the legacy two-tab layout.
    final playableFechas = widget.fechas
        .where((f) =>
            f.state == ProdeFechaState.open ||
            f.state == ProdeFechaState.locked)
        .toList();

    // Progress header data — passed into each tab content and single-view so it
    // renders below the selector row (W-1: selector must appear above progress).
    final showProgress =
        totalCount > 0 && !widget.isFechaLoading && widget.fechaLoadError == null;

    // --- openOnly: embedded "A Jugarse" content for ProdeChamiScreen ---
    // Renders only the open-fecha editable list; no internal TabBar, stale
    // banner, or fecha badge (the Chami screen owns those).
    if (widget.openOnly) {
      if (!hasFechaList) {
        return RefreshIndicator(
          onRefresh: widget.onRefresh,
          child: _buildLegacyCardArea(context, controller, isLocked),
        );
      }

      // Safety net: the controller normally selects the active fecha, but if it
      // landed on an evaluated one (which is excluded from "A Jugarse"), switch
      // to a playable fecha. Prefer an open (still-bettable) one; fall back to
      // the first in-play locked fecha so the tab never lands on evaluated.
      if (playableFechas.isNotEmpty &&
          !playableFechas.any((f) => f.fechaId == widget.selectedFechaId) &&
          !widget.isFechaLoading &&
          widget.fechaLoadError == null) {
        final defaultPlayable = playableFechas.firstWhere(
          (f) => f.state == ProdeFechaState.open,
          orElse: () => playableFechas.first,
        );
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) {
            ref
                .read(prodeFixturesControllerProvider.notifier)
                .selectFecha(defaultPlayable.fechaId);
          }
        });
      }

      return _TabContent(
        tabFechas: playableFechas,
        fecha: widget.fecha,
        drafts: widget.drafts,
        savedMatchIds: widget.savedMatchIds,
        selectedFechaId: widget.selectedFechaId,
        isFechaLoading: widget.isFechaLoading,
        fechaLoadError: widget.fechaLoadError,
        isLocked: isLocked,
        onLogout: widget.onLogout,
        onRefresh: widget.onRefresh,
        controller: controller,
        emptyMessage: 'No hay fechas para jugar por ahora.',
        predictedCount: predictedCount,
        totalCount: totalCount,
        showProgress: showProgress,
        showSelector: playableFechas.length > 1,
      );
    }

    if (!hasFechaList) {
      // --- Single-view (no fecha summary list): pre-tabs behavior ---
      return Column(
        children: [
          if (widget.stale) const _StaleBanner(),
          _FechaBadge(state: widget.fecha.state),
          if (showProgress)
            _ProgressHeader(
              predictedCount: predictedCount,
              totalCount: totalCount,
            ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: widget.onRefresh,
              child: _buildLegacyCardArea(context, controller, isLocked),
            ),
          ),
        ],
      );
    }

    // --- Two-tab layout (fechas summary list present) ---
    return Column(
      children: [
        if (widget.stale) const _StaleBanner(),
        _FechaBadge(state: widget.fecha.state),
        // T-13: TabBar with the two tabs.
        TabBar(
          controller: _tabController,
          tabs: const [
            Tab(text: 'A Jugarse'),
            Tab(text: 'Finalizados'),
          ],
        ),
        Expanded(
          child: TabBarView(
            controller: _tabController,
            children: [
              // Tab 0: A Jugarse — open fechas only
              _TabContent(
                tabFechas: aJugarse,
                fecha: widget.fecha,
                drafts: widget.drafts,
                savedMatchIds: widget.savedMatchIds,
                selectedFechaId: widget.selectedFechaId,
                isFechaLoading: widget.isFechaLoading,
                fechaLoadError: widget.fechaLoadError,
                isLocked: isLocked,
                onLogout: widget.onLogout,
                onRefresh: widget.onRefresh,
                controller: controller,
                emptyMessage: 'No hay fechas para jugar por ahora.',
                predictedCount: predictedCount,
                totalCount: totalCount,
                showProgress: showProgress,
              ),
              // Tab 1: Finalizados — locked + evaluated fechas only
              _TabContent(
                tabFechas: finalizados,
                fecha: widget.fecha,
                drafts: widget.drafts,
                savedMatchIds: widget.savedMatchIds,
                selectedFechaId: widget.selectedFechaId,
                isFechaLoading: widget.isFechaLoading,
                fechaLoadError: widget.fechaLoadError,
                isLocked: isLocked,
                onLogout: widget.onLogout,
                onRefresh: widget.onRefresh,
                controller: controller,
                emptyMessage: 'Todavía no hay fechas finalizadas.',
                predictedCount: predictedCount,
                totalCount: totalCount,
                showProgress: showProgress,
              ),
            ],
          ),
        ),
      ],
    );
  }

  /// Legacy single-view card area — used when no fechas summary list is
  /// available. Equivalent to the pre-T-13 [_buildCardArea] logic.
  Widget _buildLegacyCardArea(
    BuildContext context,
    ProdeFixturesController controller,
    bool isLocked,
  ) {
    if (widget.isFechaLoading) {
      return const Center(
        child: CircularProgressIndicator(key: Key('fecha_load_spinner')),
      );
    }

    if (widget.fechaLoadError != null) {
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
                onPressed: () =>
                    controller.selectFecha(widget.fechaLoadError!.fechaId),
                child: const Text('Reintentar'),
              ),
            ],
          ),
        ),
      );
    }

    final sectionTitle = isLocked ? 'PARTIDOS JUGADOS' : 'PRÓXIMOS PARTIDOS';

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
          child: Text(
            sectionTitle,
            style: const TextStyle(
              fontWeight: FontWeight.bold,
              fontSize: 13,
              letterSpacing: 0.5,
            ),
          ),
        ),
        if (widget.fecha.matches.isEmpty)
          const Padding(
            padding: EdgeInsets.all(24),
            child: Center(child: Text('Sin partidos en esta fecha.')),
          )
        else
          ...widget.fecha.matches.map((m) {
            final PredictionEntry? predEntry =
                widget.fecha.userPredictions.cast<PredictionEntry?>().firstWhere(
                      (p) => p!.matchId == m.matchId,
                      orElse: () => null,
                    );
            return _MatchCard(
              match: m,
              draft: widget.drafts[m.matchId] ?? const PredictionDraft(),
              isSaved: widget.savedMatchIds.contains(m.matchId),
              isLocked: isLocked,
              isEvaluated: widget.fecha.state == ProdeFechaState.evaluated,
              predictionEntry: predEntry,
              onTap: () => _openLegacySheet(
                context,
                match: m,
                draft: widget.drafts[m.matchId] ?? const PredictionDraft(),
                isLocked: isLocked,
                controller: controller,
              ),
            );
          }),
        const SizedBox(height: 8),
        Center(
          child: TextButton.icon(
            onPressed: widget.onLogout,
            icon: const Icon(Icons.logout),
            label: const Text('Cerrar sesión'),
          ),
        ),
        const SizedBox(height: 16),
      ],
    );
  }

  void _openLegacySheet(
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
// Per-tab content widget
// ---------------------------------------------------------------------------

/// Renders the content for one tab of the two-tab split.
///
/// [tabFechas] is the tab-filtered subset of all fechas (open only, or
/// locked+evaluated only). When empty, shows [emptyMessage] instead of
/// the card list.
///
/// Layout order (W-1): selector row → progress header → card area.
class _TabContent extends StatelessWidget {
  /// Fechas visible in this tab (filtered by state).
  final List<FechaSummary> tabFechas;

  final FechaActiva fecha;
  final Map<int, PredictionDraft> drafts;
  final Set<int> savedMatchIds;
  final int selectedFechaId;
  final bool isFechaLoading;
  final ProdeFixturesFechaError? fechaLoadError;
  final bool isLocked;
  final VoidCallback onLogout;
  final Future<void> Function() onRefresh;
  final ProdeFixturesController controller;
  final String emptyMessage;

  /// Progress header data — rendered between selector and card area (W-1).
  final int predictedCount;
  final int totalCount;
  final bool showProgress;

  /// Whether to render the "< Fecha N >" selector row. Defaults to true.
  /// Set false to hide it (e.g. a single open fecha in [ProdeChamiScreen]).
  final bool showSelector;

  const _TabContent({
    required this.tabFechas,
    required this.fecha,
    required this.drafts,
    required this.savedMatchIds,
    required this.selectedFechaId,
    required this.isFechaLoading,
    required this.fechaLoadError,
    required this.isLocked,
    required this.onLogout,
    required this.onRefresh,
    required this.controller,
    required this.emptyMessage,
    this.predictedCount = 0,
    this.totalCount = 0,
    this.showProgress = false,
    this.showSelector = true,
  });

  @override
  Widget build(BuildContext context) {
    // If this tab has no fechas at all, show the empty state.
    if (tabFechas.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            emptyMessage,
            textAlign: TextAlign.center,
          ),
        ),
      );
    }

    // Compute selectedIndex within the tab-filtered list.
    final selectedIndex =
        tabFechas.indexWhere((f) => f.fechaId == selectedFechaId);

    return Column(
      children: [
        // G6-e: selector row above the progress header (W-1).
        if (showSelector)
          _FechaSelectorRow(
            fechas: tabFechas,
            selectedIndex: selectedIndex,
            onPrev: selectedIndex > 0
                ? () =>
                    controller.selectFecha(tabFechas[selectedIndex - 1].fechaId)
                : null,
            onNext: selectedIndex < tabFechas.length - 1
                ? () =>
                    controller.selectFecha(tabFechas[selectedIndex + 1].fechaId)
                : null,
            onSelect: (id) => controller.selectFecha(id),
          ),
        // Progress header below the selector (W-1).
        if (showProgress)
          _ProgressHeader(
            predictedCount: predictedCount,
            totalCount: totalCount,
          ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: onRefresh,
            child: _buildCardArea(context),
          ),
        ),
      ],
    );
  }

  Widget _buildCardArea(BuildContext context) {
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

    // Section header reflects the fecha state: an open fecha lists upcoming
    // matches, a locked/evaluated one lists matches that are already in play.
    final sectionTitle = isLocked ? 'PARTIDOS JUGADOS' : 'PRÓXIMOS PARTIDOS';

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
          child: Text(
            sectionTitle,
            style: const TextStyle(
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
          ...fecha.matches.map((m) {
            // Find the user's saved prediction for this match (if any).
            final PredictionEntry? predEntry =
                fecha.userPredictions.cast<PredictionEntry?>().firstWhere(
                      (p) => p!.matchId == m.matchId,
                      orElse: () => null,
                    );
            return _MatchCard(
              match: m,
              draft: drafts[m.matchId] ?? const PredictionDraft(),
              isSaved: savedMatchIds.contains(m.matchId),
              isLocked: isLocked,
              isEvaluated: fecha.state == ProdeFechaState.evaluated,
              predictionEntry: predEntry,
              onTap: () => _openPredictionSheet(
                context,
                match: m,
                draft: drafts[m.matchId] ?? const PredictionDraft(),
                isLocked: isLocked,
                controller: controller,
              ),
            );
          }),
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
///   (evaluated + isFinal) Result badge row: color badge + real-score line
class _MatchCard extends StatelessWidget {
  final FechaMatch match;
  final PredictionDraft draft;
  final bool isSaved;
  final bool isLocked;

  /// True when the parent fecha is in the [ProdeFechaState.evaluated] state.
  final bool isEvaluated;

  /// The user's saved prediction for this match. Null when the user has not
  /// predicted this match or the fecha is not yet evaluated.
  final PredictionEntry? predictionEntry;

  /// Tap handler. Null makes the card non-interactive (read-only), used by the
  /// "Anteriores" history list.
  final VoidCallback? onTap;

  /// Whether to render the top-right status icon (saved / locked / pending).
  /// False for read-only history cards. Defaults to true.
  final bool showStatusIcon;

  const _MatchCard({
    required this.match,
    required this.draft,
    required this.isSaved,
    required this.isLocked,
    this.onTap,
    this.isEvaluated = false,
    this.predictionEntry,
    this.showStatusIcon = true,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final primary = theme.colorScheme.primary;

    // Format: "Dom. 07/06 - 14:00" (abbreviated weekday, capitalized)
    final kickoffFormatted = _formatKickoff(match.kickoff);

    // Resolve evaluation style when the fecha is evaluated and the user has
    // a prediction for this match. Null otherwise (open/locked fecha, or no
    // prediction — no badge shown).
    final PredictionResultStyle? evalStyle =
        (isEvaluated && predictionEntry != null)
            ? resolvePredictionStyle(
                method: predictionEntry!.evaluationMethod,
                points: predictionEntry!.points,
              )
            : null;

    // Border color: use evalStyle color when final+evaluated, else default grey.
    final borderColor = (evalStyle != null && match.isFinal)
        ? evalStyle.color.withAlpha(180)
        : Colors.grey.shade200;
    final borderWidth = (evalStyle != null && match.isFinal) ? 1.5 : 1.0;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      child: Card(
        color: Colors.white,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: BorderSide(color: borderColor, width: borderWidth),
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
                    // Status indicator (hidden for read-only history cards).
                    // A locked, not-yet-evaluated match shows an "En Juego"
                    // label: its fecha is closed, betting is over, and it is
                    // about to be (or being) played.
                    if (showStatusIcon)
                      if (isLocked && !isEvaluated)
                        Container(
                          key: Key('en_juego_label_${match.matchId}'),
                          padding: const EdgeInsets.symmetric(
                            horizontal: 8,
                            vertical: 3,
                          ),
                          decoration: BoxDecoration(
                            color: Colors.amber.shade100,
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(
                                Icons.sports_soccer,
                                size: 12,
                                color: Colors.orange.shade800,
                              ),
                              const SizedBox(width: 4),
                              Text(
                                'En Juego',
                                style: TextStyle(
                                  color: Colors.orange.shade800,
                                  fontWeight: FontWeight.w600,
                                  fontSize: 11,
                                ),
                              ),
                            ],
                          ),
                        )
                      else if (isSaved)
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
                    // Score display boxes (user's predicted score)
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
                // Evaluation badge — shown when fecha is evaluated and user
                // has a prediction (regardless of isFinal).
                if (evalStyle != null) ...[
                  const SizedBox(height: 8),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Container(
                        key: Key('result_badge_${match.matchId}'),
                        padding: const EdgeInsets.symmetric(
                          horizontal: 10,
                          vertical: 4,
                        ),
                        decoration: BoxDecoration(
                          color: evalStyle.color.withAlpha(30),
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(
                            color: evalStyle.color.withAlpha(180),
                            width: 1,
                          ),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(
                              evalStyle.icon,
                              size: 14,
                              color: evalStyle.color,
                            ),
                            const SizedBox(width: 4),
                            Text(
                              evalStyle.label,
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.w600,
                                color: evalStyle.color,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ],
                // Real-score line — shown only when match.isFinal is true.
                if (match.isFinal &&
                    match.realScoreHome != null &&
                    match.realScoreAway != null) ...[
                  const SizedBox(height: 4),
                  Center(
                    child: Text(
                      key: Key('real_score_line_${match.matchId}'),
                      'Resultado: ${match.realScoreHome} - ${match.realScoreAway}',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: Colors.grey.shade600,
                        fontStyle: FontStyle.italic,
                      ),
                    ),
                  ),
                ],
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
/// - [ProdeFechaState.locked]    → amber "Fecha Cerrada" chip
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
        label = 'Fecha Cerrada';
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

    final baseStyle = theme.textTheme.labelMedium;

    return Container(
      width: double.infinity,
      color: background,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      child: Text(
        label,
        textAlign: TextAlign.center,
        style: baseStyle?.copyWith(
          color: foreground,
          fontSize: (baseStyle.fontSize ?? 12) + 1,
        ),
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
      homePercent = populares!.home.round();
      drawPercent = populares!.draw.round();
      awayPercent = populares!.away.round();
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

// ---------------------------------------------------------------------------
// Prode Chami screen (summary card + Anteriores/A Jugarse)
// ---------------------------------------------------------------------------

/// The authenticated Prode landing ("Prode Chami").
///
/// Composition (top → bottom):
///   1. Stale banner (when bootstrapped with stale tokens).
///   2. Ranking summary card — the caller's position in the last fecha and the
///      general ranking; tapping a half opens [ProdeRankingScreen] on that tab.
///   3. "Pronósticos" heading + a [ProdeSegmentedToggle] (Anteriores / A Jugarse).
///   4. The selected segment's body:
///        - Anteriores → [ProdeHistoryList] (paginated past predictions).
///        - A Jugarse  → [ProdeFixturesScreen] in openOnly mode (the existing
///          editable open-fecha flow, reused verbatim).
///
/// Rendered by [ProdeAuthView] in the Authenticated state, inside
/// [ProdeAuthGate]'s Scaffold — so this widget returns a [Column], not a
/// Scaffold. [stale]/[onLogout] are forwarded from that arm.
class ProdeChamiScreen extends ConsumerStatefulWidget {
  final bool stale;
  final VoidCallback onLogout;

  const ProdeChamiScreen({
    super.key,
    required this.stale,
    required this.onLogout,
  });

  @override
  ConsumerState<ProdeChamiScreen> createState() => _ProdeChamiScreenState();
}

class _ProdeChamiScreenState extends ConsumerState<ProdeChamiScreen> {
  // 0 = Anteriores, 1 = A Jugarse. Defaults to Anteriores (past predictions).
  int _segment = 0;

  @override
  void initState() {
    super.initState();
    // Kick off the ranking loads that feed the summary card. Each controller
    // guards re-entry, so this is a no-op when already loaded. The history list
    // and the embedded fixtures screen self-bootstrap in their own initState.
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
    final theme = Theme.of(context);

    return Column(
      children: [
        if (widget.stale) const _StaleBanner(),
        const _ProdeRankingSummaryCard(),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: Align(
            alignment: Alignment.centerLeft,
            child: Text(
              'Pronósticos',
              style: theme.textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.bold,
              ),
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: ProdeSegmentedToggle(
            labels: const ['Anteriores', 'A Jugarse'],
            selectedIndex: _segment,
            onChanged: (i) => setState(() => _segment = i),
          ),
        ),
        Expanded(
          child: _segment == 0
              ? ProdeHistoryList(onLogout: widget.onLogout)
              : ProdeFixturesScreen(
                  // Stale banner is owned by this screen; logout reused as-is.
                  stale: false,
                  onLogout: widget.onLogout,
                  openOnly: true,
                ),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Ranking summary card
// ---------------------------------------------------------------------------

/// The clickable two-up ranking summary at the top of [ProdeChamiScreen].
///
/// Left half = "Ranking de la Fecha" (last evaluated fecha), right half =
/// "Ranking general". Each shows the caller's puesto (rank) and puntos from the
/// `me` object of the respective ranking controller. Tapping a half opens
/// [ProdeRankingScreen] on the matching tab. Shows "—" until data loads (or
/// when the caller is unranked / anonymous).
class _ProdeRankingSummaryCard extends ConsumerWidget {
  const _ProdeRankingSummaryCard();

  RankingMe? _meOf(ProdeRankingState state) =>
      state is ProdeRankingLoaded ? state.page.me : null;

  void _openRanking(BuildContext context, int tab) {
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => ProdeRankingScreen(initialTab: tab),
      ),
    );
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final primary = theme.colorScheme.primary;

    final fechaMe = _meOf(ref.watch(prodeFechaRankingControllerProvider));
    final generalMe = _meOf(ref.watch(prodeRankingControllerProvider));

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
      child: Container(
        decoration: BoxDecoration(
          color: primary.withValues(alpha: 0.08),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: primary.withValues(alpha: 0.20)),
        ),
        child: IntrinsicHeight(
          child: Row(
            children: [
              Expanded(
                child: InkWell(
                  key: const Key('summary_half_fecha'),
                  onTap: () => _openRanking(context, 0),
                  borderRadius: const BorderRadius.horizontal(
                    left: Radius.circular(12),
                  ),
                  child: _SummaryHalf(
                    title: 'Ranking de la Fecha',
                    me: fechaMe,
                  ),
                ),
              ),
              VerticalDivider(
                width: 1,
                thickness: 1,
                color: primary.withValues(alpha: 0.20),
              ),
              Expanded(
                child: InkWell(
                  key: const Key('summary_half_general'),
                  onTap: () => _openRanking(context, 1),
                  child: _SummaryHalf(
                    title: 'Ranking general',
                    me: generalMe,
                  ),
                ),
              ),
              Padding(
                padding: const EdgeInsets.only(right: 4),
                child: Icon(Icons.chevron_right, color: primary),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// One half of the [_ProdeRankingSummaryCard]: a title plus puesto/puntos.
class _SummaryHalf extends StatelessWidget {
  final String title;
  final RankingMe? me;

  const _SummaryHalf({required this.title, required this.me});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final primary = theme.colorScheme.primary;

    final rankLabel = me != null ? '#${me!.rank}' : '—';
    final pointsLabel = me != null ? '${me!.totalPoints}' : '—';
    // Black by default; green only when the user is first (rank == 1) in THIS
    // ranking; grey for the "—" placeholder (loading / unranked).
    final Color statColor;
    if (me == null) {
      statColor = Colors.grey.shade400;
    } else if (me!.rank == 1) {
      statColor = Colors.green.shade700;
    } else {
      statColor = Colors.black87;
    }

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 14),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            title,
            textAlign: TextAlign.center,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.titleSmall?.copyWith(
              color: primary,
              fontWeight: FontWeight.bold,
            ),
          ),
          const SizedBox(height: 8),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _SummaryStat(
                  value: rankLabel, label: 'Puesto', valueColor: statColor),
              _SummaryStat(
                  value: pointsLabel, label: 'Puntos', valueColor: statColor),
            ],
          ),
        ],
      ),
    );
  }
}

/// A big value over a small grey caption (e.g. "#1" / "Puesto").
class _SummaryStat extends StatelessWidget {
  final String value;
  final String label;
  final Color valueColor;

  const _SummaryStat({
    required this.value,
    required this.label,
    required this.valueColor,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          value,
          style: theme.textTheme.titleLarge?.copyWith(
            fontWeight: FontWeight.bold,
            color: valueColor,
          ),
        ),
        Text(
          label,
          style: theme.textTheme.bodySmall?.copyWith(
            color: Colors.grey.shade600,
          ),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Anteriores — paginated history list
// ---------------------------------------------------------------------------

/// The "Anteriores" tab: an infinite-scroll list of the caller's past
/// predictions (15 per page), driven by [prodeHistoryControllerProvider].
///
/// Reuses [_MatchCard] (read-only: no status icon, no tap) via [_HistoryCard]
/// so finished-prediction cards look identical to the fixtures cards.
class ProdeHistoryList extends ConsumerStatefulWidget {
  final VoidCallback onLogout;

  const ProdeHistoryList({super.key, required this.onLogout});

  @override
  ConsumerState<ProdeHistoryList> createState() => _ProdeHistoryListState();
}

class _ProdeHistoryListState extends ConsumerState<ProdeHistoryList> {
  final ScrollController _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    _scrollController.addListener(_onScroll);
    Future.microtask(() {
      if (mounted) {
        ref.read(prodeHistoryControllerProvider.notifier).load();
      }
    });
  }

  @override
  void dispose() {
    _scrollController.removeListener(_onScroll);
    _scrollController.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (!_scrollController.hasClients) return;
    final pos = _scrollController.position;
    // Trigger the next page when within 400px of the bottom.
    if (pos.pixels >= pos.maxScrollExtent - 400) {
      ref.read(prodeHistoryControllerProvider.notifier).loadMore();
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(prodeHistoryControllerProvider);
    final notifier = ref.read(prodeHistoryControllerProvider.notifier);

    if (state.phase == ProdeHistoryPhase.loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (state.phase == ProdeHistoryPhase.error) {
      return _HistoryMessage(
        icon: Icons.error_outline,
        title: 'Algo salió mal',
        message:
            'No pudimos cargar tus pronósticos. Revisá tu conexión y reintentá.',
        actionLabel: 'Reintentar',
        onAction: notifier.load,
      );
    }

    if (state.isEmpty) {
      return RefreshIndicator(
        onRefresh: notifier.refresh,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          children: const [
            SizedBox(height: 80),
            _HistoryEmpty(),
          ],
        ),
      );
    }

    // Loaded with items: list + a trailing footer slot (spinner / retry).
    return RefreshIndicator(
      onRefresh: notifier.refresh,
      child: ListView.builder(
        controller: _scrollController,
        physics: const AlwaysScrollableScrollPhysics(),
        itemCount: state.items.length + 1,
        itemBuilder: (context, i) {
          if (i < state.items.length) {
            return _HistoryCard(entry: state.items[i]);
          }
          return _HistoryFooter(
            state: state,
            onRetry: notifier.loadMore,
          );
        },
      ),
    );
  }
}

/// Footer below the history list: a spinner while paging, an inline retry when
/// the last page failed, or empty space when there is nothing more to load.
class _HistoryFooter extends StatelessWidget {
  final ProdeHistoryState state;
  final VoidCallback onRetry;

  const _HistoryFooter({required this.state, required this.onRetry});

  @override
  Widget build(BuildContext context) {
    if (state.isLoadingMore) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 20),
        child: Center(
          child: SizedBox(
            width: 24,
            height: 24,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
        ),
      );
    }
    if (state.loadMoreFailed) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 16),
        child: Center(
          child: TextButton(
            onPressed: onRetry,
            child: const Text('Cargar más'),
          ),
        ),
      );
    }
    return const SizedBox(height: 24);
  }
}

/// Read-only finished-prediction card. Adapts a [PredictionHistoryEntry] to the
/// shared [_MatchCard] so it renders identically to fixtures cards (score boxes,
/// evaluation badge, "Resultado: X - Y" line) without a status icon or tap.
class _HistoryCard extends StatelessWidget {
  final PredictionHistoryEntry entry;

  const _HistoryCard({required this.entry});

  @override
  Widget build(BuildContext context) {
    final match = FechaMatch(
      matchId: entry.matchId,
      homeTeam: entry.homeTeam,
      awayTeam: entry.awayTeam,
      kickoff: entry.kickoff,
      zona: entry.zona,
      homeEscudo: entry.homeEscudo,
      awayEscudo: entry.awayEscudo,
      realScoreHome: entry.realScoreHome,
      realScoreAway: entry.realScoreAway,
      isFinal: entry.isFinal,
    );

    return _MatchCard(
      match: match,
      draft: PredictionDraft(
        scoreHome: entry.scoreHome,
        scoreAway: entry.scoreAway,
      ),
      isSaved: false,
      isLocked: false,
      showStatusIcon: false,
      // Show the evaluation badge once points have been awarded.
      isEvaluated: entry.points != null,
      predictionEntry: PredictionEntry(
        matchId: entry.matchId,
        scoreHome: entry.scoreHome,
        scoreAway: entry.scoreAway,
        points: entry.points,
        evaluationMethod: entry.evaluationMethod,
      ),
    );
  }
}

/// Empty state for the "Anteriores" list (no past predictions yet).
class _HistoryEmpty extends StatelessWidget {
  const _HistoryEmpty();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.history, size: 64, color: theme.colorScheme.primary),
            const SizedBox(height: 16),
            Text(
              'Todavía no tenés pronósticos anteriores',
              textAlign: TextAlign.center,
              style: theme.textTheme.titleMedium,
            ),
            const SizedBox(height: 8),
            const Text(
              'Cuando se jueguen las fechas que pronosticaste vas a verlas acá.',
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }
}

/// Icon + title + message + retry button for the history error state.
class _HistoryMessage extends StatelessWidget {
  final IconData icon;
  final String title;
  final String message;
  final String actionLabel;
  final VoidCallback onAction;

  const _HistoryMessage({
    required this.icon,
    required this.title,
    required this.message,
    required this.actionLabel,
    required this.onAction,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 64, color: theme.colorScheme.primary),
            const SizedBox(height: 16),
            Text(title, style: theme.textTheme.headlineSmall),
            const SizedBox(height: 8),
            Text(message, textAlign: TextAlign.center),
            const SizedBox(height: 24),
            ElevatedButton(onPressed: onAction, child: Text(actionLabel)),
          ],
        ),
      ),
    );
  }
}

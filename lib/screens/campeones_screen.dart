import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/campeon_titulo.dart';
import '../providers/service_providers.dart';
import '../services/i_api_service.dart';
import '../services/i_cache_service.dart';
import '../utils/error_reporting.dart';
import '../widgets/entre_redes_app_bar.dart';
import '../widgets/year_pill.dart';
import 'player_detail_screen.dart';

/// Copa Chaminade championship history: every year the tournament was
/// played, its champion team, and the squad that won it (slice 9a+9b).
///
/// Pushed with `MaterialPageRoute<void>` — this app has no named routes.
class CampeonesScreen extends ConsumerStatefulWidget {
  /// When supplied together with [initialZona], the matching year card
  /// starts expanded instead of the most recent one — used when this
  /// screen is opened by tapping a título's team name on the player detail
  /// screen.
  final int? initialAnio;
  final String? initialZona;

  const CampeonesScreen({super.key, this.initialAnio, this.initialZona});

  @override
  ConsumerState<CampeonesScreen> createState() => _CampeonesScreenState();
}

class _CampeonesScreenState extends ConsumerState<CampeonesScreen> {
  bool _isLoading = true;
  Object? _error;
  List<CampeonTitulo> _titulos = [];

  /// One controller per year card, created once the data loads. This — not
  /// a `PageStorageKey`, and not each `ExpansionTile`'s own internal state —
  /// is the single source of truth for which year is open: the accordion
  /// invariant (opening one year collapses every other) is enforced in
  /// [_onExpansionChanged] by calling `.collapse()` on every other
  /// controller. Because these controller objects live in this State object
  /// rather than in the widget tree, they survive a card being scrolled out
  /// of the `ListView.builder`'s build range and back in — a
  /// `PageStorageKey` would exist only to solve that same problem, so it
  /// would be redundant here, not merely unnecessary.
  List<ExpansibleController> _controllers = [];

  /// Index of the year currently open, or null if every card is collapsed.
  int? _openIndex;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _disposeControllers();
    super.dispose();
  }

  void _disposeControllers() {
    for (final c in _controllers) {
      c.dispose();
    }
  }

  /// Loads the full championship history: cache-first, with a network
  /// fallback and a stale-cache fallback if the network call (or parsing
  /// its response) fails.
  ///
  /// Unlike the optional títulos panel on the player detail screen, this
  /// screen is reached deliberately, so a genuine failure with nothing to
  /// fall back on surfaces as an error state instead of being swallowed —
  /// but the failure is always reported via [reportNonFatal] first, exactly
  /// once, never through a bare `catch (_) {}`.
  Future<void> _load() async {
    setState(() {
      _isLoading = true;
      _error = null;
    });

    final cache = ref.read(cacheServiceProvider);
    final api = ref.read(apiServiceProvider);

    try {
      final titulos = await _fetchTitulos(api, cache);
      if (!mounted) return;
      _applyLoaded(titulos);
    } catch (e, st) {
      await reportNonFatal(
        e,
        st,
        'CampeonesScreen: getCampeonesHistoria failed',
      );

      // A down/absent endpoint (or a corrupt fresh cache) still degrades
      // gracefully if a stale cached copy exists.
      try {
        final stale = await cache.getCachedCampeonesHistoriaIgnoringTtl();
        if (stale != null) {
          final titulos = _parseTitulos(stale);
          if (!mounted) return;
          _applyLoaded(titulos);
          return;
        }
      } catch (_) {
        // Falls through to the error state below — the stale copy is
        // itself unusable.
      }

      if (!mounted) return;
      setState(() {
        _error = e;
        _isLoading = false;
      });
    }
  }

  Future<List<CampeonTitulo>> _fetchTitulos(
    IApiService api,
    ICacheService cache,
  ) async {
    final cached = await cache.getCachedCampeonesHistoria();
    if (cached != null) {
      return _parseTitulos(cached);
    }
    final raw = await api.getCampeonesHistoria();
    await cache.cacheCampeonesHistoria(raw);
    return _parseTitulos(raw);
  }

  List<CampeonTitulo> _parseTitulos(List<dynamic> raw) => raw
      .map((t) => CampeonTitulo.fromJson(Map<String, dynamic>.from(t as Map)))
      .toList();

  void _applyLoaded(List<CampeonTitulo> titulos) {
    _disposeControllers();
    final controllers =
        List.generate(titulos.length, (_) => ExpansibleController());
    final openIndex = _initialOpenIndex(titulos);
    if (openIndex != null) {
      controllers[openIndex].expand();
    }
    setState(() {
      _titulos = titulos;
      _controllers = controllers;
      _openIndex = openIndex;
      _isLoading = false;
    });
  }

  /// The card that should start expanded: the deep-linked year+zone if one
  /// was supplied and found, otherwise the most recent year (index 0 — the
  /// server already returns `anio DESC`). Returns null only when there are
  /// no years to show at all.
  int? _initialOpenIndex(List<CampeonTitulo> titulos) {
    if (titulos.isEmpty) return null;
    final anio = widget.initialAnio;
    if (anio != null) {
      final idx = titulos.indexWhere(
        (t) => t.anio == anio && (widget.initialZona == null || t.zona == widget.initialZona),
      );
      if (idx != -1) return idx;
    }
    return 0;
  }

  /// Enforces the accordion invariant: expanding one year collapses every
  /// other. [index]'s own controller has already flipped its state by the
  /// time this fires (it is the `ExpansionTile`'s `onExpansionChanged`
  /// callback) — this only needs to react to that change.
  void _onExpansionChanged(int index, bool expanded) {
    final previous = _openIndex;
    setState(() {
      _openIndex = expanded ? index : (previous == index ? null : previous);
    });
    if (expanded && previous != null && previous != index) {
      _controllers[previous].collapse();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: const EntreRedesAppBar(title: 'Copa Chaminade'),
      body: _buildBody(context),
    );
  }

  Widget _buildBody(BuildContext context) {
    if (_isLoading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return _buildMessage(
        context,
        icon: Icons.error_outline,
        title: 'No pudimos cargar la historia de la Copa Chaminade.',
        subtitle: 'Revisá tu conexión e intentá de nuevo.',
        action: ElevatedButton(
          onPressed: _load,
          child: const Text('Reintentar'),
        ),
      );
    }

    if (_titulos.isEmpty) {
      return _buildMessage(
        context,
        icon: Icons.emoji_events_outlined,
        title: 'Todavía no hay campeones cargados',
        subtitle:
            'Cuando se cargue la historia de la Copa Chaminade vas a poder ver acá todos los campeones, año por año.',
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.symmetric(vertical: 8),
      itemCount: _titulos.length,
      itemBuilder: (context, index) => _yearCard(context, index),
    );
  }

  Widget _buildMessage(
    BuildContext context, {
    required IconData icon,
    required String title,
    required String subtitle,
    Widget? action,
  }) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 48, color: Colors.grey.shade400),
            const SizedBox(height: 12),
            Text(
              title,
              textAlign: TextAlign.center,
              style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16),
            ),
            const SizedBox(height: 8),
            Text(
              subtitle,
              textAlign: TextAlign.center,
              style: TextStyle(color: Colors.grey.shade600),
            ),
            if (action != null) ...[
              const SizedBox(height: 16),
              action,
            ],
          ],
        ),
      ),
    );
  }

  /// One year's card: header matches the shipped títulos panel's row shape
  /// exactly (year pill, team name beside it, "Campeón Zona {X}" beneath in
  /// grey) — see [YearPill] and `player_detail_screen.dart`'s `_tituloRow`,
  /// which this deliberately mirrors rather than the original design's
  /// zone-less `2016 · CHELSEA` header (product decision, since
  /// `(anio, zona, posicion)` is the record's real uniqueness key).
  Widget _yearCard(BuildContext context, int index) {
    final t = _titulos[index];

    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      clipBehavior: Clip.antiAlias,
      child: ExpansionTile(
        controller: _controllers[index],
        onExpansionChanged: (expanded) => _onExpansionChanged(index, expanded),
        title: Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            YearPill('${t.anio}'),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                t.equipoNombre,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
              ),
            ),
          ],
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 6, left: 0),
          child: Text(
            'Campeón Zona ${t.zona}',
            style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
          ),
        ),
        children: [
          for (final entry in t.plantel) _plantelRow(context, entry),
          const SizedBox(height: 4),
        ],
      ),
    );
  }

  /// One squad member row. No avatar in this slice (9a+9b) — avatars are
  /// slice 9c's scope; [CampeonPlantelEntry.fotoUrl] is parsed and carried
  /// on the model already so that slice does not need to touch it again.
  ///
  /// APP-7 / APP-5: a linked entry (`jugadorId != null`) is an [InkWell]
  /// with a trailing chevron; an unlinked entry is plain, non-tappable text
  /// with no ripple and no chevron — but the chevron's width is still
  /// reserved so both kinds of row have identical height and the names stay
  /// aligned down the card. The captain marker (`(C)`) stays here even
  /// though it was removed from the player-detail titles panel: that panel
  /// shows one row per título won by the single player being viewed, so a
  /// captain badge there could only ever mark that same player and adds no
  /// information. This screen instead lists every member of the squad for
  /// the year, where the captain marker is the only way to tell who among
  /// many names wore the armband.
  Widget _plantelRow(BuildContext context, CampeonPlantelEntry entry) {
    final primary = Theme.of(context).colorScheme.primary;
    final esVinculado = entry.jugadorId != null;
    final nombreMostrado = entry.esCapitan ? '${entry.nombre} (C)' : entry.nombre;

    final row = Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      child: Row(
        children: [
          Expanded(
            child: Text(
              nombreMostrado,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontSize: 14,
                fontWeight: esVinculado ? FontWeight.w600 : FontWeight.normal,
                color: esVinculado ? primary : Colors.black87,
              ),
            ),
          ),
          SizedBox(
            width: 24,
            child: esVinculado
                ? Icon(Icons.chevron_right, size: 18, color: primary.withValues(alpha: 0.8))
                : null,
          ),
        ],
      ),
    );

    if (!esVinculado) return row;

    return InkWell(
      onTap: () => Navigator.push(
        context,
        MaterialPageRoute<void>(
          builder: (_) => PlayerDetailScreen(
            player: {
              'id': entry.jugadorId,
              'title': {'rendered': entry.nombre},
            },
          ),
        ),
      ),
      child: row,
    );
  }
}

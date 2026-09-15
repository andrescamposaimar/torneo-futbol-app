import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'team_detail_screen.dart';
import '../providers/service_providers.dart';
import '../models/jugador.dart';
import '../models/campeon_titulo.dart';
import '../utils/date_utils.dart';
import '../utils/puntaje_utils.dart';
import '../utils/campeones_copy.dart';
import '../utils/error_reporting.dart';
import '../services/i_api_service.dart';
import '../services/i_cache_service.dart';
import 'match_detail_screen.dart';
import 'campeones_screen.dart';
import '../widgets/match_card.dart';
import '../widgets/year_pill.dart';

class PlayerDetailScreen extends ConsumerStatefulWidget {
  final Map<String, dynamic> player;

  const PlayerDetailScreen({super.key, required this.player});

  /// Identifies the títulos panel's subtree so tests can scope finders to
  /// it — e.g. asserting there is no [Icons.star_rounded] *inside the
  /// panel* without also matching the unrelated hero star above it.
  static const titulosPanelKey = Key('titulos-panel');

  /// The hero card's own subtree. The rating glyph and the rating number now
  /// also appear in the OTROS DATOS row below, so a test that means "the hero's
  /// rating" has to say so — an unscoped find.byIcon/find.text would match both.
  static const heroKey = Key('player-detail-hero');

  @override
  ConsumerState<PlayerDetailScreen> createState() => _PlayerDetailScreenState();
}

class _PlayerDetailScreenState extends ConsumerState<PlayerDetailScreen> with SingleTickerProviderStateMixin {
  late TabController _tabController;
  late ScrollController _partidosScrollController;
  late Jugador jugador;

  List<dynamic> temporadas = [];
  List<dynamic> partidos = [];
  List<JugadorTitulo> titulos = [];
  bool isLoading = true;
  bool isLoadingMore = false;
  bool hasMore = true;
  int currentPage = 1;
  final int perPage = 16;
  String? error;
  
  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _partidosScrollController = ScrollController();
    _partidosScrollController.addListener(_onScroll);
    jugador = Jugador.fromJson(widget.player);
    _fetchInitialData();
  }

  @override
  void dispose() {
    _partidosScrollController.removeListener(_onScroll);
    _partidosScrollController.dispose();
    _tabController.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (!_partidosScrollController.hasClients) return;

    final threshold = 300.0;
    final position = _partidosScrollController.position;

    if (position.pixels >= position.maxScrollExtent - threshold &&
        !isLoadingMore &&
        hasMore) {
      _fetchMorePartidos();
    }
  }


  Future<void> _fetchInitialData() async {
    if (!mounted) return;
    setState(() => isLoading = true);
    try {
      // The three fetches below all start in parallel.
      final api = ref.read(apiServiceProvider);
      final cache = ref.read(cacheServiceProvider);
      final jugadorFuture = api.getJugadorPorId(jugador.id);
      final partidosFuture = api.getPartidosPorJugador(jugador.id, page: currentPage, perPage: perPage);

      // Titles are an optional panel (APP-1's isNotEmpty guard already
      // hides it on an empty result): a down endpoint, a tenant without the
      // campeones plugin, or an un-updated test fake must never break or
      // slow down the rest of the profile. _loadTitulos is declared `async`,
      // so calling it here can never throw synchronously (Dart wraps every
      // exception raised inside an async function body — even one raised
      // before the first `await`, like a stale fake's synchronous
      // noSuchMethod throw — into the returned Future's error channel
      // instead of propagating it to the caller). It also never lets that
      // Future settle with an error: every failure path inside it is
      // already caught and reported.
      final titulosFuture = _loadTitulos(api, cache);

      try {
        final data = await jugadorFuture;
        jugador = Jugador.fromJson(data);
      } catch (e, st) {
        // Keep rendering from the constructor-supplied stub (widget.player)
        // on failure — but never silently: a squad row from the history
        // screen can carry a jugador_id up to 17 years old, far more likely
        // to point at a deleted or merged player record than any existing
        // caller, so a 404 here needs a trace instead of vanishing.
        await reportNonFatal(
          e,
          st,
          'PlayerDetailScreen: getJugadorPorId failed for player ${jugador.id}',
        );
      }
      temporadas = jugador.temporadas;

      final res = await partidosFuture;
      if (!mounted) return;
      final nuevos = res['items'] ?? [];
      final currentPageFromApi = res['current_page'] ?? currentPage;
      final totalPages = res['total_pages'] ?? 1;
      setState(() {
        partidos = nuevos;
        currentPage = currentPageFromApi + 1;
        hasMore = currentPageFromApi < totalPages;
      });

      // Deliberately NOT awaited here: titulosFuture is attached to
      // (created alongside) jugadorFuture/partidosFuture above, so it runs
      // concurrently with them instead of serializing in front of them, and
      // this `then` continuation — not an `await` — means a down or hanging
      // campeones endpoint can never add to isLoading's gate below. Since
      // _loadTitulos never lets its Future settle with an error, this chain
      // needs no `.catchError`: it either resolves (usually well before
      // this method returns, sometimes after) or simply never does, and
      // either way the rest of the profile is unaffected.
      unawaited(titulosFuture.then((value) {
        if (!mounted) return;
        setState(() => titulos = value);
      }));
    } catch (e) {
      if (!mounted) return;
      setState(() => error = e.toString());
    } finally {
      if (mounted) setState(() => isLoading = false);
    }
  }

  /// Loads this player's Copa Chaminade titles: cache-first, with a network
  /// fallback and a stale-cache fallback if the network call fails.
  ///
  /// Never throws and never lets the returned [Future] settle with an
  /// error — every failure is reported via [reportNonFatal] and resolved to
  /// an empty list instead, so a down or missing campeones endpoint can
  /// never break, block, or add error noise to the rest of the profile.
  Future<List<JugadorTitulo>> _loadTitulos(
    IApiService api,
    ICacheService cache,
  ) async {
    try {
      final cachedRaw = await cache.getCachedTitulosDeJugador(jugador.id);
      List<dynamic> rawTitulos;
      if (cachedRaw != null) {
        rawTitulos = cachedRaw;
      } else {
        try {
          final data = await api.getTitulosDeJugador(jugador.id);
          rawTitulos = List<dynamic>.from(data['titulos'] ?? []);
          await cache.cacheTitulosDeJugador(jugador.id, rawTitulos);
        } catch (e, st) {
          await reportNonFatal(
            e,
            st,
            'PlayerDetailScreen: getTitulosDeJugador failed for player ${jugador.id}',
          );
          final stale = await cache.getCachedTitulosDeJugadorIgnoringTtl(jugador.id);
          rawTitulos = stale ?? [];
        }
      }
      return rawTitulos
          .map((t) => JugadorTitulo.fromJson(Map<String, dynamic>.from(t as Map)))
          .toList();
    } catch (e, st) {
      await reportNonFatal(
        e,
        st,
        'PlayerDetailScreen: failed to load titulos for player ${jugador.id}',
      );
      return [];
    }
  }

  Future<void> _fetchMorePartidos() async {
    if (!mounted) return;
    setState(() => isLoadingMore = true);
    try {
      final res = await ref.read(apiServiceProvider).getPartidosPorJugador(jugador.id, page: currentPage, perPage: perPage);
      if (!mounted) return;
      final nuevos = res['items'] ?? [];
      final currentPageFromApi = res['current_page'] ?? currentPage;
      final totalPages = res['total_pages'] ?? 1;
      setState(() {
        partidos.addAll(nuevos);
        currentPage = currentPageFromApi + 1;
        hasMore = currentPageFromApi < totalPages;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => error = e.toString());
    } finally {
      if (mounted) setState(() => isLoadingMore = false);
    }
  }

  /// The player's identity card: portrait on one side, the facts that identify
  /// them — name, rating and current team — on the other.
  Widget _buildHero(BuildContext context) {
    final primary = Theme.of(context).colorScheme.primary;
    final avatar = jugador.imagen;
    final equipo = jugador.equipo;
    final escudo = jugador.escudo;
    final tieneEquipo = jugador.equipoId != null && equipo != 'Sin equipo';
    final puntaje = formatearPuntaje(jugador.puntaje);
    final sinPuntaje = puntaje == '-';

    return Container(
      key: PlayerDetailScreen.heroKey,
      width: double.infinity,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        color: primary.withValues(alpha: 0.06),
        border: Border.all(color: primary.withValues(alpha: 0.12)),
      ),
      padding: const EdgeInsets.all(16),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          GestureDetector(
            onTap: avatar != null ? () => _mostrarFoto(context, avatar) : null,
            child: Stack(
              clipBehavior: Clip.none,
              children: [
                Container(
                  width: 96,
                  height: 96,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: Colors.grey.shade200,
                    border: Border.all(color: Colors.white, width: 3),
                    image: avatar != null
                        ? DecorationImage(
                            image: NetworkImage(avatar), fit: BoxFit.cover)
                        : null,
                  ),
                  child: avatar == null
                      ? Icon(Icons.person, size: 46, color: Colors.grey.shade500)
                      : null,
                ),
                if (jugador.capitan)
                  Positioned(
                    top: 0,
                    right: 0,
                    child: Container(
                      width: 30,
                      height: 30,
                      decoration: BoxDecoration(
                        color: Colors.amber.shade800,
                        shape: BoxShape.circle,
                        border: Border.all(color: Colors.white, width: 2),
                      ),
                      child: const Center(
                        child: Text(
                          'C',
                          style: TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.bold,
                            fontSize: 15,
                          ),
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  jugador.nombre,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                    color: Colors.black87,
                    height: 1.2,
                  ),
                ),
                const SizedBox(height: 10),
                // The rating pill and the title stars share one row (product
                // decision), wrapped so the stars drop to their own line on a
                // narrow phone instead of squeezing or overflowing.
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    // The rating is the one number that summarises a player,
                    // so it sits with the name rather than buried among the
                    // other facts.
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 10, vertical: 5),
                      decoration: BoxDecoration(
                        color: sinPuntaje
                            ? Colors.grey.withValues(alpha: 0.12)
                            : primary.withValues(alpha: 0.09),
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(
                          color: sinPuntaje
                              ? Colors.grey.withValues(alpha: 0.25)
                              : primary.withValues(alpha: 0.18),
                        ),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            // Gold stars now mean championships (see
                            // _tituloEstrella below) — a gold star for the
                            // rating pill would put the same icon and colour
                            // on two different meanings on one card. Gold is
                            // reserved for titles; everything else uses the
                            // brand colour.
                            Icons.speed,
                            size: 17,
                            color: sinPuntaje
                                ? Colors.grey.shade500
                                : primary,
                          ),
                          const SizedBox(width: 5),
                          // Flexible + ellipsis: 'Sin puntaje' is a much
                          // longer string than a bare rating number, and this
                          // branch was unreachable until formatearPuntaje
                          // started treating a zero rating as unrated — so
                          // its width was never actually laid out before.
                          // On a narrow phone the pill's available width
                          // (bounded by the Wrap it sits in) can be less than
                          // the label needs; without this the Row overflows
                          // instead of the label truncating gracefully.
                          Flexible(
                            child: Text(
                              sinPuntaje ? 'Sin puntaje' : puntaje,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                fontSize: sinPuntaje ? 12 : 15,
                                fontWeight: FontWeight.w800,
                                color: sinPuntaje
                                    ? Colors.grey.shade600
                                    : Colors.black87,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                    for (final t in _titulosVisibles) _tituloEstrella(t.zona),
                  ],
                ),
                if (tieneEquipo) ...[
                  const SizedBox(height: 10),
                  // The team is a destination, so it reads as a tappable chip
                  // rather than coloured text.
                  Material(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(24),
                    clipBehavior: Clip.antiAlias,
                    child: InkWell(
                      onTap: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) => TeamDetailScreen(
                            team: {
                              'id': jugador.equipoId,
                              'nombre': equipo,
                              'imagen': escudo,
                              'leagues': temporadas,
                              'seasons': temporadas,
                            },
                          ),
                        ),
                      ),
                      child: Padding(
                        padding: const EdgeInsets.fromLTRB(9, 6, 6, 6),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            if (escudo.isNotEmpty) ...[
                              Image.network(escudo,
                                  width: 20, height: 20, fit: BoxFit.contain),
                              const SizedBox(width: 7),
                            ],
                            Flexible(
                              child: Text(
                                equipo,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(
                                  fontSize: 13,
                                  fontWeight: FontWeight.w700,
                                  color: Colors.black87,
                                ),
                              ),
                            ),
                            Icon(Icons.chevron_right,
                                size: 18, color: primary.withValues(alpha: 0.8)),
                          ],
                        ),
                      ),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// One gold star per title won, shown beside the puntaje pill in the hero
  /// (product decision). The usable interior of a star glyph is roughly 40%
  /// of its box, so at the puntaje icon's 17px a letter would be
  /// unreadable — 28px keeps the zone letter legible.
  ///
  /// A blank [zona] (an older plugin deploy predating `zona` on this
  /// endpoint) still renders the star — it means "this person won a
  /// championship", which is true regardless of whether the zone is known —
  /// just without the overlaid letter, and with a Semantics label that
  /// stands alone instead of reading as "Campeón Zona " to a screen reader.
  Widget _tituloEstrella(String zona) {
    return Semantics(
      label: zona.isNotEmpty ? 'Campeón Zona $zona' : 'Campeón',
      child: SizedBox(
        width: 28,
        height: 28,
        child: Stack(
          alignment: Alignment.center,
          children: [
            Icon(Icons.star_rounded, size: 28, color: Colors.amber.shade600),
            if (zona.isNotEmpty)
              Text(
                zona,
                style: const TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.bold,
                  color: Colors.black87,
                ),
              ),
          ],
        ),
      ),
    );
  }

  void _mostrarFoto(BuildContext context, String url) {
    showDialog(
      context: context,
      barrierColor: Colors.black87,
      builder: (_) => Dialog(
        backgroundColor: Colors.transparent,
        insetPadding: const EdgeInsets.all(16),
        child: Stack(
          alignment: Alignment.topRight,
          children: [
            Center(
              child: ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: Image.network(
                  url,
                  fit: BoxFit.contain,
                  width: MediaQuery.of(context).size.width - 32,
                ),
              ),
            ),
            Positioned(
              top: 0,
              right: 0,
              child: GestureDetector(
                onTap: () => Navigator.of(context).pop(),
                child: Container(
                  decoration: const BoxDecoration(
                    color: Colors.white,
                    shape: BoxShape.circle,
                  ),
                  padding: const EdgeInsets.all(6),
                  child: const Icon(Icons.close, size: 20, color: Colors.black),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// Section header shared by the panels below: icon, label, optional count.
  Widget _seccionHeader(IconData icono, String titulo, {String? trailing}) {
    final primary = Theme.of(context).colorScheme.primary;

    return Container(
      width: double.infinity,
      color: Colors.grey.shade50,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 11),
      child: Row(
        children: [
          Icon(icono, size: 16, color: primary.withValues(alpha: 0.8)),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              titulo,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w700,
                letterSpacing: 0.8,
                color: Colors.grey.shade700,
              ),
            ),
          ),
          if (trailing != null)
            Text(
              trailing,
              style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
            ),
        ],
      ),
    );
  }

  Widget _panel({required Widget header, required Widget child}) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: Colors.grey.shade200),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          header,
          Divider(height: 1, color: Colors.grey.shade200),
          child,
        ],
      ),
    );
  }

  /// Size every glyph in the OTROS DATOS rows shares.
  static const double _glifoSize = 20;

  /// A Material glyph styled for an [_infoRow].
  Widget _glifo(IconData icono) => Icon(
        icono,
        size: _glifoSize,
        color: Theme.of(context).colorScheme.primary,
      );

  Widget _infoRow(Widget glifo, String label, String value) {
    final primary = Theme.of(context).colorScheme.primary;
    final sinDato = value.isEmpty || value == '-';

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(
              color: primary.withValues(alpha: 0.08),
              shape: BoxShape.circle,
            ),
            child: Center(child: glifo),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label,
                    style: TextStyle(fontSize: 12, color: Colors.grey.shade600)),
                const SizedBox(height: 2),
                Text(
                  sinDato ? 'No informado' : value,
                  style: TextStyle(
                    fontSize: 16,
                    fontWeight: sinDato ? FontWeight.normal : FontWeight.w500,
                    color: sinDato ? Colors.grey.shade500 : Colors.black87,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// Seasons the player took part in, as chips. They used to occupy a whole tab
  /// of their own for what amounts to a handful of years.
  Widget _buildTemporadas() {
    final anios = temporadas.map((t) => t.toString()).toList();

    return _panel(
      header: _seccionHeader(
        Icons.event_repeat,
        'TEMPORADAS',
        trailing: anios.length == 1 ? '1 temporada' : '${anios.length} temporadas',
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 16),
        child: Wrap(
          spacing: 8,
          runSpacing: 8,
          children: anios.map((a) => YearPill(a)).toList(),
        ),
      ),
    );
  }

  /// A title is rendered — as a hero star or as a panel row — as long as it
  /// carries a team name. The zone is an enrichment, not the trophy: an
  /// older plugin deploy (predating `zona` on this endpoint) omits the key
  /// entirely, and a title with no zone still genuinely happened, so it
  /// must still show. Only an empty team name leaves nothing worth
  /// rendering (an empty bold headline), so that case alone is omitted.
  /// _tituloRow and _tituloEstrella degrade gracefully when zona is empty —
  /// they never render dangling text like "Campeón Zona " with a trailing
  /// space.
  bool _esTituloVisible(JugadorTitulo t) => t.equipoNombre.isNotEmpty;

  List<JugadorTitulo> get _titulosVisibles =>
      titulos.where(_esTituloVisible).toList();

  /// Copa Chaminade titles this player is linked to (API-2). Rendered only
  /// when there is at least one displayable title — the isNotEmpty guard in
  /// _buildDetalles is structural, so there is nothing to forget to hide
  /// (APP-1).
  Widget _buildTitulos() {
    final titulosVisibles = _titulosVisibles;

    return KeyedSubtree(
      key: PlayerDetailScreen.titulosPanelKey,
      child: _panel(
        header: _seccionHeader(
          Icons.emoji_events,
          'TÍTULOS',
          trailing: etiquetaTitulos(titulosVisibles.length),
        ),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Column(
            children: titulosVisibles
                .map((t) => _tituloRow(
                      t,
                      onTapEquipo: () => Navigator.push(
                        context,
                        MaterialPageRoute<void>(
                          builder: (_) => CampeonesScreen(
                            initialAnio: t.anio,
                            initialZona: t.zona,
                          ),
                        ),
                      ),
                    ))
                .toList(),
          ),
        ),
      ),
    );
  }

  /// One title row: year pill (reusing _buildTemporadas()'s chip styling),
  /// team name, and — when the zone is known — "Campeón Zona {X}"
  /// underneath. A title whose `zona` is missing or empty (an older plugin
  /// deploy predating `zona` on this endpoint) still renders the year and
  /// team; it simply omits that second line instead of rendering it with a
  /// dangling zone. No star, no captain marker — both were explicitly
  /// removed from this panel by the product owner (they still travel on
  /// the wire for the history screen).
  ///
  /// [onTapEquipo] navigates to the championship history screen
  /// ([CampeonesScreen]), opened on this title's year and zone — wired in
  /// slice 9. A null callback still renders plain, non-tappable text with no
  /// chevron and no ripple (no dead tap target); every call site today
  /// always supplies one, so that branch is effectively unreachable, kept
  /// only as the row's documented no-callback contract.
  Widget _tituloRow(JugadorTitulo t, {VoidCallback? onTapEquipo}) {
    final primary = Theme.of(context).colorScheme.primary;
    final esTappable = onTapEquipo != null;

    final contenido = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          t.equipoNombre,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            fontSize: 15,
            fontWeight: FontWeight.w700,
            color: esTappable ? primary : Colors.black87,
          ),
        ),
        // Omitted entirely when the zone is unknown (an older plugin deploy
        // that predates `zona` on this endpoint) rather than rendering the
        // dangling "Campeón Zona " with a trailing space.
        if (t.zona.isNotEmpty) ...[
          const SizedBox(height: 2),
          Text(
            'Campeón Zona ${t.zona}',
            style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
          ),
        ],
      ],
    );

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          YearPill('${t.anio}'),
          const SizedBox(width: 12),
          Expanded(
            child: esTappable
                ? InkWell(
                    onTap: onTapEquipo,
                    child: Row(
                      children: [
                        Expanded(child: contenido),
                        Icon(Icons.chevron_right,
                            size: 18, color: primary.withValues(alpha: 0.8)),
                      ],
                    ),
                  )
                : contenido,
          ),
        ],
      ),
    );
  }

  /// Career totals, side by side. They come from the API as a whole block, so
  /// the panel is only rendered when the backend actually sent it.
  Widget _buildEstadisticas() {
    final stats = jugador.estadisticas;

    return _panel(
      header: _seccionHeader(Icons.query_stats, 'ESTADÍSTICAS DEL JUGADOR'),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 16, 12, 16),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: _statTile(
                  Icons.directions_run, stats.partidosJugados, 'Partidos jugados'),
            ),
            Expanded(
              child: _statTile(Icons.sports_soccer, stats.goles, 'Goles'),
            ),
            Expanded(
              child: _statTile(
                  Icons.event_repeat, stats.temporadas, 'Temporadas'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _statTile(IconData icono, int valor, String label) {
    final primary = Theme.of(context).colorScheme.primary;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 44,
          height: 44,
          decoration: BoxDecoration(
            color: primary.withValues(alpha: 0.08),
            shape: BoxShape.circle,
          ),
          child: Icon(icono, size: 22, color: primary),
        ),
        const SizedBox(height: 8),
        Text(
          '$valor',
          style: TextStyle(
            fontSize: 22,
            fontWeight: FontWeight.w700,
            color: primary,
          ),
        ),
        const SizedBox(height: 2),
        Text(
          label,
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
        ),
      ],
    );
  }

  Widget _buildDetalles(BuildContext context) {
    String posicion = jugador.posicion.isNotEmpty ? jugador.posicion : '-';
    if (jugador.reemplazoAlta) posicion += ' - Reemplazo Alta';
    if (jugador.reemplazoBaja) posicion += ' - Reemplazo Baja';

    final edadVal = calcularEdad(jugador.fechaNacimiento);

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _buildHero(context),
        const SizedBox(height: 16),
        // The rating now lives in the hero, so it is not repeated here.
        _panel(
          header: _seccionHeader(Icons.badge_outlined, 'OTROS DATOS'),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
            child: Column(
              children: [
                // Deliberately the same value and the same glyph as the hero's
                // rating pill. The pill shows the number without a word, which
                // leaves a reader guessing what it measures; this row names it,
                // and the repeated Icons.speed is what ties the two together.
                _infoRow(_glifo(Icons.speed), 'Puntaje',
                    formatearPuntaje(jugador.puntaje)),
                _infoRow(_glifo(Icons.person_pin_circle), 'Posición', posicion),
                _infoRow(_glifo(Icons.cake_outlined), 'Fecha de nacimiento',
                    formatFechaNacimiento(jugador.fechaNacimiento)),
                _infoRow(_glifo(Icons.numbers), 'Edad',
                    edadVal > 0 ? '$edadVal años' : '-'),
              ],
            ),
          ),
        ),
        if (jugador.estadisticas.disponible) ...[
          const SizedBox(height: 16),
          _buildEstadisticas(),
        ],
        if (temporadas.isNotEmpty) ...[
          const SizedBox(height: 16),
          _buildTemporadas(),
        ],
        if (_titulosVisibles.isNotEmpty) ...[
          const SizedBox(height: 16),
          _buildTitulos(),
        ],
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final nombre = jugador.nombre;

    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: Text(nombre),
          bottom: const TabBar(
            indicatorColor: Colors.white,
            labelColor: Colors.white,
            unselectedLabelColor: Colors.white70,
            tabs: [
              Tab(text: 'Detalles'),
              Tab(text: 'Partidos'),
            ],
          ),
        ),
        body: isLoading
            ? const Center(child: CircularProgressIndicator())
            : TabBarView(
                children: [
                  _buildDetalles(context),
                  Builder(
                    builder: (_) {
                      if (isLoading) {
                        return const Center(child: CircularProgressIndicator());
                      }

                      if (partidos.isEmpty) {
                        return const Center(child: Text('No se registran partidos.'));
                      }

                      return ListView.builder(
                        controller: _partidosScrollController,
                        padding: const EdgeInsets.all(0),
                        itemCount: partidos.length + (isLoadingMore ? 1 : 0),
                        itemBuilder: (context, index) {
                          if (index < partidos.length) {
                            final partido =
                                Map<String, dynamic>.from(partidos[index] as Map);
                            // A player's history mixes played and scheduled
                            // matches, so each card reads its own shape from
                            // whether the match has a score.
                            final jugado = partido['goles_local'] != null &&
                                partido['goles_visitante'] != null;
                            return MatchCard(
                              partido: partido,
                              mostrarResultado: jugado,
                              onTap: () => Navigator.push(
                                context,
                                MaterialPageRoute(
                                  builder: (_) => MatchDetailScreen(partido: partido),
                                ),
                              ),
                            );
                          } else {
                            return const Padding(
                              padding: EdgeInsets.symmetric(vertical: 16),
                              child: Center(child: CircularProgressIndicator()),
                            );
                          }
                        },
                      );
                    },
                  ),
                ],
              ),
      ),
    );
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'team_detail_screen.dart';
import '../providers/service_providers.dart';
import '../models/jugador.dart';
import '../utils/date_utils.dart';
import '../utils/puntaje_utils.dart';
import 'match_detail_screen.dart';
import '../widgets/match_card.dart';

class PlayerDetailScreen extends ConsumerStatefulWidget {
  final Map<String, dynamic> player;

  const PlayerDetailScreen({super.key, required this.player});

  @override
  ConsumerState<PlayerDetailScreen> createState() => _PlayerDetailScreenState();
}

class _PlayerDetailScreenState extends ConsumerState<PlayerDetailScreen> with SingleTickerProviderStateMixin {
  late TabController _tabController;
  late ScrollController _partidosScrollController;
  late Jugador jugador;

  List<dynamic> temporadas = [];
  List<dynamic> partidos = [];
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
      // Ambos fetches arrancan en paralelo
      final api = ref.read(apiServiceProvider);
      final jugadorFuture = api.getJugadorPorId(jugador.id);
      final partidosFuture = api.getPartidosPorJugador(jugador.id, page: currentPage, perPage: perPage);

      try {
        final data = await jugadorFuture;
        jugador = Jugador.fromJson(data);
      } catch (_) {}
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
    } catch (e) {
      if (!mounted) return;
      setState(() => error = e.toString());
    } finally {
      if (mounted) setState(() => isLoading = false);
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
                // The rating is the one number that summarises a player, so it
                // sits with the name rather than buried among the other facts.
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
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
                        Icons.star_rounded,
                        size: 17,
                        color: sinPuntaje
                            ? Colors.grey.shade500
                            : Colors.amber.shade700,
                      ),
                      const SizedBox(width: 5),
                      Text(
                        sinPuntaje ? 'Sin puntaje' : puntaje,
                        style: TextStyle(
                          fontSize: sinPuntaje ? 12 : 15,
                          fontWeight: FontWeight.w800,
                          color: sinPuntaje
                              ? Colors.grey.shade600
                              : Colors.black87,
                        ),
                      ),
                    ],
                  ),
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

  Widget _infoRow(IconData icono, String label, String value) {
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
            child: Icon(icono, size: 20, color: primary),
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
    final primary = Theme.of(context).colorScheme.primary;
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
          children: anios
              .map((a) => Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
                    decoration: BoxDecoration(
                      color: primary.withValues(alpha: 0.07),
                      borderRadius: BorderRadius.circular(20),
                      border: Border.all(color: primary.withValues(alpha: 0.15)),
                    ),
                    child: Text(
                      a,
                      style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: primary.withValues(alpha: 0.9),
                      ),
                    ),
                  ))
              .toList(),
        ),
      ),
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
                _infoRow(Icons.sports_soccer, 'Posición', posicion),
                _infoRow(Icons.cake_outlined, 'Fecha de nacimiento',
                    formatFechaNacimiento(jugador.fechaNacimiento)),
                _infoRow(Icons.numbers, 'Edad',
                    edadVal > 0 ? '$edadVal años' : '-'),
                _infoRow(Icons.psychology_outlined, 'Carácter', jugador.caracter),
              ],
            ),
          ),
        ),
        if (temporadas.isNotEmpty) ...[
          const SizedBox(height: 16),
          _buildTemporadas(),
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

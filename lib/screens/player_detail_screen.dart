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
    _tabController = TabController(length: 3, vsync: this);
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

  Widget _infoTile(String label, String value) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Divider(),
        const SizedBox(height: 6),
        Text(label, style: const TextStyle(color: Colors.grey)),
        const SizedBox(height: 4),
        Text(value, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
        const SizedBox(height: 12),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final nombre = jugador.nombre;
    final avatar = jugador.imagen;
    String posicion = jugador.posicion.isNotEmpty ? jugador.posicion : '-';
    if (jugador.reemplazoAlta) posicion += ' - Reemplazo Alta';
    if (jugador.reemplazoBaja) posicion += ' - Reemplazo Baja';
    final puntaje = formatearPuntaje(jugador.puntaje);
    final caracter = jugador.caracter;
    final equipo = jugador.equipo;
    final escudo = jugador.escudo;
    final nacimientoFormatted = formatFechaNacimiento(jugador.fechaNacimiento);
    final edadVal = calcularEdad(jugador.fechaNacimiento);
    final edad = edadVal > 0 ? '$edadVal años' : '-';

    return DefaultTabController(
      length: 3,
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
              Tab(text: 'Temporadas'),
            ],
          ),
        ),
        body: isLoading
            ? const Center(child: CircularProgressIndicator())
            : TabBarView(
                children: [
                  ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      Center(
                        child: GestureDetector(
                          onTap: avatar != null
                              ? () => showDialog(
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
                                                avatar,
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
                                  )
                              : null,
                          child: Stack(
                            alignment: Alignment.topRight,
                            children: [
                              CircleAvatar(
                                radius: 50,
                                backgroundImage: avatar != null ? NetworkImage(avatar) : null,
                                backgroundColor: Colors.grey[300],
                                child: avatar == null ? const Icon(Icons.person, size: 50) : null,
                              ),
                            if (jugador.capitan)
                              Positioned(
                                top: 0,
                                right: 0,
                                child: Container(
                                  width: 28,
                                  height: 28,
                                  decoration: BoxDecoration(
                                    color: Colors.amber.shade800,
                                    shape: BoxShape.circle,
                                    boxShadow: [
                                      BoxShadow(
                                        color: Colors.black.withOpacity(0.3),
                                        blurRadius: 3,
                                        offset: const Offset(0, 2),
                                      ),
                                    ],
                                  ),
                                  child: const Center(
                                    child: Text(
                                      'C',
                                      style: TextStyle(
                                        color: Colors.white,
                                        fontWeight: FontWeight.bold,
                                        fontSize: 16,
                                      ),
                                    ),
                                  ),
                                ),
                              ),
                          ],
                        ),
                        ),
                      ),
                      const SizedBox(height: 16),
                      Center(
                        child: Text(nombre, style: Theme.of(context).textTheme.headlineSmall),
                      ),
                      Center(
                        child: (jugador.equipoId != null && equipo != 'Sin equipo')
                            ? GestureDetector(
                                onTap: () {
                                  Navigator.push(
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
                                  );
                                },
                                child: Row(
                                  mainAxisAlignment: MainAxisAlignment.center,
                                  children: [
                                    if (escudo.isNotEmpty)
                                      Padding(
                                        padding: const EdgeInsets.only(right: 6),
                                        child: Image.network(
                                          escudo,
                                          width: 24,
                                          height: 24,
                                          fit: BoxFit.contain,
                                        ),
                                      ),
                                    Text(
                                      equipo,
                                      style: const TextStyle(
                                        color: Colors.cyan,
                                        fontWeight: FontWeight.w500,
                                        fontSize: 16,
                                      ),
                                    ),
                                    const SizedBox(width: 4),
                                    const Icon(Icons.chevron_right, size: 18, color: Colors.cyan),
                                  ],
                                ),
                              )
                            : const SizedBox.shrink(),
                      ),
                      const SizedBox(height: 24),
                      _infoTile('Puntaje', puntaje),
                      _infoTile('Posición', posicion),
                      _infoTile('Fecha de Nacimiento', nacimientoFormatted),
                      _infoTile('Edad', edad),
                      _infoTile('Carácter', caracter),
                    ],
                  ),
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
                            final p = partidos[index];
                            return MatchCard(
                              partido: p,
                              onVerDetalle: () => Navigator.push(
                                context,
                                MaterialPageRoute(builder: (_) => MatchDetailScreen(partido: p)),
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
                  temporadas.isEmpty
                      ? const Center(child: Text('No se registran temporadas.'))
                      : ListView.builder(
                          padding: const EdgeInsets.all(12),
                          itemCount: temporadas.length,
                          itemBuilder: (context, index) {
                            final temporada = temporadas[index];
                            return Card(
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                              elevation: 2,
                              margin: const EdgeInsets.symmetric(vertical: 6),
                              child: ListTile(
                                leading: const Icon(Icons.calendar_today, color: Colors.cyan),
                                title: Text(temporada.toString()),
                              ),
                            );
                          },
                        ),
                ],
              ),
      ),
    );
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'match_detail_screen.dart';
import 'player_detail_screen.dart';
import '../widgets/zocalo_publicitario.dart';
import '../widgets/match_card.dart';
import '../providers/service_providers.dart';
import '../models/jugador.dart';
import '../utils/date_utils.dart';
import '../utils/posicion_utils.dart';
import '../utils/puntaje_utils.dart';

class TeamDetailScreen extends ConsumerStatefulWidget {
  final Map<String, dynamic> team;
  const TeamDetailScreen({super.key, required this.team});

  @override
  ConsumerState<TeamDetailScreen> createState() => _TeamDetailScreenState();
}

class _TeamDetailScreenState extends ConsumerState<TeamDetailScreen> with SingleTickerProviderStateMixin {
  List<dynamic> partidos = [];
  List<Jugador> jugadores = [];
  bool isLoadingPartidos = false;
  bool isLoadingJugadores = false;
  bool _jugadoresCargados = false;
  String? errorPartidos;
  String? errorJugadores;
  late TabController _tabController;

@override
void initState() {
  super.initState();
  _tabController = TabController(length: 2, vsync: this);
  _loadJugadoresDesdeCache();
  _fetchPartidos();
  _fetchJugadores();
}

  Future<void> _loadJugadoresDesdeCache() async {
    final cached = await ref.read(cacheServiceProvider).getCachedPlayersPorEquipo(widget.team['id']);
    if (cached != null && mounted) {
      final lista = cached.map<Jugador>((j) => Jugador.fromJson(j)).toList();
      lista.sort((a, b) => (ordenPosiciones[a.posicion] ?? 99).compareTo(ordenPosiciones[b.posicion] ?? 99));
      setState(() => jugadores = lista);
    }
  }

  Future<void> _fetchPartidos() async {
    setState(() => isLoadingPartidos = true);
    try {
      final teamId = widget.team['id'];
      final nuevos = await ref.read(apiServiceProvider).getPartidosPorEquipoId(teamId);
      nuevos.sort((a, b) => (b['id'] ?? 0).compareTo(a['id'] ?? 0));
      if (!mounted) return;
      setState(() => partidos = nuevos);
    } catch (e) {
      if (!mounted) return;
      setState(() => errorPartidos = e.toString());
    } finally {
      if (!mounted) return;
      setState(() => isLoadingPartidos = false);
    }
  }

  Future<void> _fetchJugadores() async {
    if (!mounted) return;
    setState(() => isLoadingJugadores = true);
    try {
      final teamId = widget.team['id'];
      final res = await ref.read(apiServiceProvider).getJugadoresRaw(equipoId: teamId);
      final nuevos = res['items'] ?? [];

      if (!mounted) return;

      // 🔥 Filtrá solo jugadores con el equipo_id coincidente (convertido a int)
      /*final jugadoresFiltrados = nuevos.where((j) {
        final equipoId = j['equipo_id'];
        if (equipoId == null) return false;
        return equipoId.toString() == teamId.toString();
      }).toList();*/
      final jugadoresFiltrados = nuevos;
      // final lista = jugadoresFiltrados.map<Jugador>((j) => Jugador.fromJson(j)).toList();

      final lista = <Jugador>[];
        for (var j in jugadoresFiltrados) {
          try {
            lista.add(Jugador.fromJson(j));
          } catch (e) {
            debugPrint('Jugador descartado por error de parseo (ID: ${j['id']}): $e');
          }
      }

      lista.sort((a, b) =>
          (ordenPosiciones[a.posicion] ?? 99).compareTo(ordenPosiciones[b.posicion] ?? 99));

      setState(() => jugadores = lista);
    } catch (e) {
      if (!mounted) return;
      setState(() => errorJugadores = e.toString());
    } finally {
      if (!mounted) return;
      setState(() {
        isLoadingJugadores = false;
        _jugadoresCargados = true;
      });
    }
  }


  Widget _buildPlayerCard(Jugador jugador) {
    final edad = calcularEdad(jugador.fechaNacimiento);

    final posicion = posicionAbreviada(jugador.posicion);
    final bgColor = posicionColor(posicion);

    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      child: Stack(
        children: [
          Container(
            decoration: BoxDecoration(
              color: Colors.grey[100],
              borderRadius: BorderRadius.circular(12),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withOpacity(0.05),
                  blurRadius: 4,
                  offset: const Offset(0, 2),
                )
              ],
            ),
            child: ListTile(
              onTap: () {
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => PlayerDetailScreen(player: jugador.toJson()),
                  ),
                );
              },
              contentPadding: const EdgeInsets.only(left: 36, right: 16, top: 12, bottom: 12),
              leading: jugador.imagen != null
                  ? CircleAvatar(backgroundImage: NetworkImage(jugador.imagen!))
                  : const Icon(Icons.person, size: 40),
              title: Text(jugador.nombre, style: const TextStyle(fontWeight: FontWeight.w600)),
              subtitle: Row(
                children: [
                  Text('Edad: $edad', style: const TextStyle(fontSize: 13)),
                  if (jugador.reemplazoAlta)
                    const Padding(
                      padding: EdgeInsets.only(left: 8),
                      child: Icon(Icons.arrow_upward, size: 16, color: Colors.green),
                    ),
                  if (jugador.reemplazoBaja)
                    const Padding(
                      padding: EdgeInsets.only(left: 8),
                      child: Icon(Icons.arrow_downward, size: 16, color: Colors.red),
                    ),
                ],
              ),
              trailing: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  const Text('Pts.', style: TextStyle(fontSize: 11)),
                  Text(
                    formatearPuntaje(jugador.puntaje == 0 ? null : jugador.puntaje),
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                  ),
                ],
              ),
            ),
          ),
          Positioned(
            left: 0,
            top: 10,
            bottom: 10,
            child: FractionallySizedBox(
              heightFactor: 0.9,
              child: Container(
                width: 28,
                decoration: BoxDecoration(
                  color: bgColor,
                  borderRadius: const BorderRadius.only(
                    topRight: Radius.circular(6),
                    bottomRight: Radius.circular(6),
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withOpacity(0.2),
                      blurRadius: 6,
                      offset: const Offset(2, 2),
                    )
                  ],
                ),
                child: Center(
                  child: RotatedBox(
                    quarterTurns: -1,
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 4.0),
                      child: Text(
                        posicion,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final team = widget.team;
    final nombre = team['nombre']?.toString() ?? 'Sin nombre';
    final avatarUrl = team['imagen'] is String ? team['imagen'] : null;

    final activos = jugadores.where((j) => !j.reemplazoBaja).toList();
    final bajas = jugadores.where((j) => j.reemplazoBaja).toList();
    final sinPlantel = _jugadoresCargados && jugadores.isEmpty;

    Widget buildPartidosContent() {
      if (errorPartidos != null) {
        return Center(child: Text('Error al cargar partidos: $errorPartidos'));
      }
      if (isLoadingPartidos) {
        return const Center(child: CircularProgressIndicator());
      }
      if (partidos.isEmpty) {
        return Center(
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: const [
              Icon(Icons.event_busy, size: 48, color: Colors.grey),
              SizedBox(height: 12),
              Text('No se han encontrado partidos para el equipo',
                  style: TextStyle(fontSize: 16, color: Colors.grey)),
            ],
          ),
        );
      }
      return ListView.builder(
        itemCount: partidos.length,
        itemBuilder: (context, index) {
          final p = partidos[index];
          return MatchCard(
            partido: p,
            onVerDetalle: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => MatchDetailScreen(partido: p)),
            ),
          );
        },
      );
    }

    Widget buildPlantelContent() {
      if (errorJugadores != null) {
        return Center(child: Text('Error al cargar jugadores: $errorJugadores'));
      }
      if (isLoadingJugadores) {
        return const Center(child: CircularProgressIndicator());
      }
      return ListView(
        children: [
          ...activos.map(_buildPlayerCard),
          if (bajas.isNotEmpty)
            const Padding(
              padding: EdgeInsets.fromLTRB(16, 24, 16, 8),
              child: Text('Bajas', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            ),
          ...bajas.map(_buildPlayerCard),
        ],
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            if (avatarUrl != null)
              Padding(
                padding: const EdgeInsets.only(right: 8),
                child: Image.network(
                  avatarUrl,
                  width: 32,
                  height: 32,
                  fit: BoxFit.contain,
                ),
              ),
            Expanded(
              child: Text(
                nombre,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ],
        ),
        centerTitle: true,
      ),
      body: sinPlantel
          ? buildPartidosContent()
          : Column(
              children: [
                const SizedBox(height: 12),
                TabBar(
                  controller: _tabController,
                  labelColor: Theme.of(context).primaryColor,
                  unselectedLabelColor: Colors.grey,
                  indicatorColor: Theme.of(context).primaryColor,
                  tabs: const [
                    Tab(text: 'Plantel'),
                    Tab(text: 'Partidos'),
                  ],
                ),
                const SizedBox(height: 8),
                Expanded(
                  child: TabBarView(
                    controller: _tabController,
                    children: [
                      buildPlantelContent(),
                      buildPartidosContent(),
                    ],
                  ),
                ),
              ],
            ),
      bottomNavigationBar: const ZocaloPublicitario(),
    );
  }
}
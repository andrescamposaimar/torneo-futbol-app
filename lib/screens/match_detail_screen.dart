import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../providers/service_providers.dart';
import '../widgets/zocalo_publicitario.dart';
import '../widgets/full_field_painter.dart';
import '../widgets/player_pod.dart';
import 'player_detail_screen.dart';


class MatchDetailScreen extends ConsumerStatefulWidget {
  final Map<String, dynamic> partido;
  const MatchDetailScreen({super.key, required this.partido});

  @override
  ConsumerState<MatchDetailScreen> createState() => _MatchDetailScreenState();
}

class _MatchDetailScreenState extends ConsumerState<MatchDetailScreen> with SingleTickerProviderStateMixin {
  Map<String, dynamic>? goleadores;
  bool isLoading = true;
  String? error;
  late TabController _tabController;
  String equipoSeleccionado = 'local';

  String? estadisticasAdUrl;
  String? alineacionesAdUrl;

  bool _goleadoresCargados = false;

  bool get _esFuturo => widget.partido['status'] == 'future';

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);

    // Cargar publicidad
    ref.read(remoteDataServiceProvider).fetchAdImages().then((ads) {
      if (!mounted) return;
      setState(() {
       // estadisticasAdUrl = ads['estadisticas'];
       // alineacionesAdUrl = ads['alineaciones'];
      });
    });

    // Solo cargar goleadores si el partido ya se disputó
    if (!_esFuturo) {
      _loadGoleadores();
    } else {
      // Marcar como no cargando para evitar indicadores infinitos
      isLoading = false;
    }

    _tabController.addListener(() {
      setState(() {}); // 🔁 Necesario para que se redibujen las pestañas al cambiar
    });
  }

  Future<void> _loadGoleadores() async {
    try {
      final data = await ref.read(apiServiceProvider).getGoleadoresDelPartido(widget.partido['id']);
      final cachedPlayers = await ref.read(cacheServiceProvider).getCachedPlayers();

      Map<int, dynamic> cachedMap = {};
      if (cachedPlayers != null) {
        for (var player in cachedPlayers) {
          if (player['id'] != null) {
            cachedMap[player['id']] = player;
          }
        }
      }

      Future<void> enrich(List<dynamic> jugadores, String equipo) async {
        for (var j in jugadores) {
          j['goles'] = j['goles'] ?? 0;
          j['tarjeta_amarilla'] = j['tarjetaamarilla'] ?? j['tarjeta_amarilla'] ?? 0;
          j['tarjeta_roja'] = j['tarjetaroja'] ?? j['tarjeta_roja'] ?? 0;
          j['figura'] = j['figura'] == '1' || j['figura'] == 1 || j['figura'] == true;
          j['equipo'] = equipo;
          j['posicion'] = j['posicion'] ?? '-';
          j['capitan'] = j['capitan'] == true;
          j['reemplazo_alta'] = j['reemplazo_alta'] == true;
          j['reemplazo_baja'] = j['reemplazo_baja'] == true;


          final jugadorId = j['id'];
          if (jugadorId != null && cachedMap.containsKey(jugadorId)) {
            final metrics = cachedMap[jugadorId]['metrics'];
            if (metrics != null && metrics['puntaje'] != null) {
              j['puntaje'] = metrics['puntaje'];
              continue;
            }
          }

          j['puntaje'] = '-';
        }
      }

      await enrich(data['equipo_local']['goleadores'], 'local');
      await enrich(data['equipo_visitante']['goleadores'], 'visitante');

      if (mounted) {
        setState(() {
          goleadores = data;
          isLoading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          error = e.toString();
          isLoading = false;
        });
      }
    }
  }

  Widget _buildResumen() {
    final p = widget.partido;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          '${p['equipo_local']} ${p['goles_local'] ?? '-'} vs ${p['equipo_visitante']} ${p['goles_visitante'] ?? '-'}',
          style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
        ),
        const SizedBox(height: 12),
        Card(
          elevation: 4,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Información del partido',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 16),
                _buildInfoRow(Icons.calendar_month, 'Fecha', p['fecha'] ?? ''),
                _buildInfoRow(Icons.access_time, 'Hora', p['hora'] ?? ''),
                _buildInfoRow(Icons.emoji_events, 'Liga', p['liga'] ?? ''),
                _buildInfoRow(Icons.timeline, 'Temporada', p['temporada'] ?? '2025'),
                _buildInfoRow(Icons.location_on, 'Cancha', p['cancha'] ?? ''),
                _buildInfoRow(Icons.person, 'Árbitro', p['arbitro'] ?? 'No informado'),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildInfoRow(IconData icon, String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Icon(icon, size: 20, color: Colors.green[800]),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              '$label: $value',
              style: const TextStyle(fontSize: 16),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildEstadisticas() {
    
  if (goleadores == null) {
    return LoadingSeccionConAd(
      texto: 'Cargando estadísticas...',
      //adImageUrl: estadisticasAdUrl,
    );
  }

    final localStats = goleadores?['equipo_local']['goleadores'] as List<dynamic>? ?? [];
    final visitanteStats = goleadores?['equipo_visitante']['goleadores'] as List<dynamic>? ?? [];

    final equipoLocal = widget.partido['equipo_local'] ?? '';
    final equipoVisitante = widget.partido['equipo_visitante'] ?? '';
    final escudoLocal = widget.partido['escudo_local'];
    final escudoVisitante = widget.partido['escudo_visitante'];

    int sum(String key, List<dynamic> jugadores) {
      return jugadores.fold(0, (total, jugador) {
        final raw = jugador[key];
        if (raw == null) return total;
        if (raw is num) return total + raw.toInt();
        if (raw is String) {
          final normalizado = raw.replaceAll(',', '.');
          final numParsed = double.tryParse(normalizado);
          return numParsed != null ? total + numParsed.toInt() : total;
        }
        return total;
      });
    }

    Widget statRow(String title, int localVal, int visitanteVal) {
      return Card(
        margin: const EdgeInsets.symmetric(vertical: 6),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text('$localVal', style: const TextStyle(fontSize: 16)),
              Text(title, style: const TextStyle(fontWeight: FontWeight.bold)),
              Text('$visitanteVal', style: const TextStyle(fontSize: 16)),
            ],
          ),
        ),
      );
    }

    final allPlayers = [...localStats, ...visitanteStats];
    final figura = allPlayers.cast<Map<String, dynamic>>().firstWhere(
      (j) => j['figura'] == true,
      orElse: () => {},
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Expanded(child: _buildTeamHeader(equipoLocal, escudoLocal)),
            const SizedBox(width: 16),
            Expanded(child: _buildTeamHeader(equipoVisitante, escudoVisitante)),
          ],
        ),
        const SizedBox(height: 12),
        statRow('Goles', sum('goles', localStats), sum('goles', visitanteStats)),
        statRow('Amarillas', sum('tarjeta_amarilla', localStats), sum('tarjeta_amarilla', visitanteStats)),
        statRow('Rojas', sum('tarjeta_roja', localStats), sum('tarjeta_roja', visitanteStats)),
        if (figura.isNotEmpty) _buildFiguraCard(figura),
      ],
    );
  }

  Widget _buildTeamHeader(String nombre, String? escudoUrl) {
    return Column(
      children: [
        if (escudoUrl != null && escudoUrl.isNotEmpty)
          Image.network(escudoUrl, height: 40),
        const SizedBox(height: 4),
        SizedBox(
          width: double.infinity,
          child: Text(
            nombre,
            style: const TextStyle(fontWeight: FontWeight.bold),
            textAlign: TextAlign.center,
            overflow: TextOverflow.ellipsis,
            maxLines: 2,
          ),
        ),
      ],
    );
  }

  Widget _buildFiguraCard(Map<String, dynamic> figura) {
    final nombre = figura['nombre'] ?? 'Jugador';
    final equipo = figura['equipo'] ?? '';
    final puntaje = PlayerPod(jugador: figura).puntajeStr;
    final foto = (figura['foto'] is String && figura['foto'].toString().isNotEmpty)
        ? figura['foto']
        : null;

    return Card(
      margin: const EdgeInsets.only(top: 16),
      child: InkWell(
        onTap: () {
          Navigator.pushNamed(context, '/player_detail', arguments: figura);
        },
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Figura del partido', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              const SizedBox(height: 8),
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: foto != null
                    ? CircleAvatar(backgroundImage: NetworkImage(foto))
                    : const Icon(Icons.person),
                title: Text(nombre),
                subtitle: Text('Equipo: $equipo'),
                trailing: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.star, color: Colors.amber),
                    const SizedBox(width: 4),
                    Text(puntaje),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

Widget _buildAlineaciones() {
  if (goleadores == null) {
    return LoadingSeccionConAd(
      texto: 'Cargando estadísticas...',
      //adImageUrl: alineacionesAdUrl,
    );
  }

  final List<dynamic> jugadoresRaw = equipoSeleccionado == 'local'
      ? (goleadores?['equipo_local']['goleadores'] ?? [])
      : (goleadores?['equipo_visitante']['goleadores'] ?? []);

  final jugadores = jugadoresRaw.whereType<Map<String, dynamic>>().toList();

  final jugadoresConIncidencia = jugadores.where((j) {
    final g = j['goles'] ?? 0;
    final a = j['tarjeta_amarilla'] ?? 0;
    final r = j['tarjeta_roja'] ?? 0;
    return (g is int && g > 0) || (a is int && a > 0) || (r is int && r > 0);
  }).toList();

  final bajas = jugadores.where((j) => j['reemplazo_baja'] == true).toList();
  final disponibles = jugadores.where((j) => j['reemplazo_baja'] != true).toList();

  final Map<String, List<Map<String, dynamic>>> porPosicion = {
    'Arquero': [],
    'Defensor': [],
    'Mediocampista': [],
    'Delantero': [],
  };

  // 1. Agrupar normalmente
  for (var j in disponibles) {
    final pos = (j['posicion'] ?? '').toString();
    if (porPosicion.containsKey(pos)) {
      porPosicion[pos]?.add(j);
    }
  }

  // 2. Validar si hay al menos 1 arquero disponible
  final arqueros = porPosicion['Arquero'] ?? [];
  final hayArquero = arqueros.any((j) => j['reemplazo_baja'] != true);

  if (!hayArquero) {
    // 3. Buscar un suplente con "Arquero Sup."
    final arqueroSup = disponibles.firstWhere(
      (j) => j['posicion'] == 'Arquero Sup.',
      orElse: () => {},
    );

    if (arqueroSup.isNotEmpty) {
      porPosicion['Arquero']?.add(arqueroSup);
    }
  }

  Widget wrapFila(List<Map<String, dynamic>> filaJugadores) {
    return Wrap(
      alignment: WrapAlignment.center,
      spacing: 12,
      runSpacing: 12,
      children: filaJugadores.map((j) =>
        GestureDetector(
          onTap: () async {
            showDialog(
              context: context,
              barrierDismissible: false,
              builder: (context) => const Center(child: CircularProgressIndicator()),
            );
            try {
              final jugadorCompleto = await ref.read(apiServiceProvider).getJugadorPorId(j['id']);
              if (context.mounted) {
                Navigator.of(context).pop(); // Quitar loader
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => PlayerDetailScreen(player: jugadorCompleto),
                  ),
                );
              }
            } catch (e) {
              if (context.mounted) {
                Navigator.of(context).pop();
                showDialog(
                  context: context,
                  builder: (context) => AlertDialog(
                    title: const Text('Error'),
                    content: Text('No se pudo cargar la información del jugador.'),
                    actions: [
                      TextButton(
                        onPressed: () => Navigator.of(context).pop(),
                        child: const Text('OK'),
                      ),
                    ],
                  ),
                );
              }
            }
          },
          child: AnimatedOpacity(
            opacity: 1,
            duration: const Duration(milliseconds: 500),
            child: PlayerPod(jugador: j),
          ),
        )
      ).toList(),
    );
  }

  Widget wrapLineas(String key) {
    final jugadores = porPosicion[key]!;
    if (jugadores.isEmpty) return const SizedBox.shrink();

    final filas = <List<Map<String, dynamic>>>[];
    for (var i = 0; i < jugadores.length; i += 3) {
      filas.add(jugadores.skip(i).take(3).toList());
    }

    return Column(children: filas.map(wrapFila).toList());
  }

  return Column(
    children: [
      Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Flexible(
            child: ChoiceChip(
              label: Text(
                widget.partido['equipo_local'],
                overflow: TextOverflow.ellipsis,
                maxLines: 1,
              ),
              selected: equipoSeleccionado == 'local',
              onSelected: (_) => setState(() => equipoSeleccionado = 'local'),
            ),
          ),
          const SizedBox(width: 8),
          Flexible(
            child: ChoiceChip(
              label: Text(
                widget.partido['equipo_visitante'],
                overflow: TextOverflow.ellipsis,
                maxLines: 1,
              ),
              selected: equipoSeleccionado == 'visitante',
              onSelected: (_) => setState(() => equipoSeleccionado = 'visitante'),
            ),
          ),
        ],
      ),
      const SizedBox(height: 16),
      Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.green[100],
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: Colors.green.shade800, width: 2),
        ),
        child: CustomPaint(
          painter: FullFieldPainter(),
          child: AnimatedSwitcher(
            duration: const Duration(milliseconds: 400),
            child: Column(
              key: ValueKey(equipoSeleccionado),
              children: [
                wrapLineas('Arquero'),
                wrapLineas('Defensor'),
                wrapLineas('Mediocampista'),
                wrapLineas('Delantero'),
              ],
            ),
          ),
        ),
      ),
      if (bajas.isNotEmpty)
        Padding(
          padding: const EdgeInsets.only(top: 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Bajas:', style: TextStyle(fontWeight: FontWeight.bold)),
              const SizedBox(height: 8),
              Wrap(
                spacing: 12,
                runSpacing: 12,
                alignment: WrapAlignment.center,
                children: bajas.map((j) => PlayerPod(jugador: j)).toList(),
              ),
            ],
          ),
        ),
    ],
  );
}

  Widget _buildPendiente() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: const [
            Icon(Icons.schedule, size: 72, color: Colors.black26),
            SizedBox(height: 20),
            Text(
              'El partido no se ha disputado aún.',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold, color: Colors.black54),
            ),
            SizedBox(height: 8),
            Text(
              'La información estará disponible luego de disputado el partido.',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 14, color: Colors.black45),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Detalle del Partido')),
      body: Column(
        children: [
          TabBar(
            controller: _tabController,
            labelColor: Colors.black,
            indicatorColor: Theme.of(context).colorScheme.primary,
            tabs: const [
              Tab(text: 'Resumen'),
              Tab(text: 'Estadísticas'),
              Tab(text: 'Alineaciones'),
            ],
          ),
          Expanded(
            child: TabBarView(
              controller: _tabController,
              children: [
                _esFuturo
                    ? _buildPendiente()
                    : SingleChildScrollView(
                        padding: const EdgeInsets.all(16),
                        child: _buildResumen(),
                      ),
                _esFuturo
                    ? _buildPendiente()
                    : goleadores == null && isLoading
                        ? LoadingSeccionConAd(
                            texto: 'Cargando estadísticas...',
                            adImageUrl: estadisticasAdUrl,
                          )
                        : SingleChildScrollView(
                            padding: const EdgeInsets.all(16),
                            child: _buildEstadisticas(),
                          ),
                _esFuturo
                    ? _buildPendiente()
                    : goleadores == null && isLoading
                        ? LoadingSeccionConAd(
                            texto: 'Cargando alineaciones...',
                          )
                        : SingleChildScrollView(
                            padding: const EdgeInsets.all(16),
                            child: _buildAlineaciones(),
                          ),
              ],
            ),
          ),
        ],
      ),
      bottomNavigationBar: const ZocaloPublicitario(), // ✅ Ahora está acá
    );
  }
}

  class LoadingSeccionConAd extends StatelessWidget {
    final String texto;
    final String? adImageUrl;

    const LoadingSeccionConAd({
      super.key,
      required this.texto,
      this.adImageUrl,
    });

    @override
    Widget build(BuildContext context) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const CircularProgressIndicator(),
            const SizedBox(height: 12),
            Text(texto, style: const TextStyle(fontSize: 14)),
            if (adImageUrl != null && adImageUrl!.isNotEmpty) ...[
              const SizedBox(height: 24),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                  child: AspectRatio(
                    aspectRatio: 16 / 9,
                    child: Image.network(
                      adImageUrl!,
                      fit: BoxFit.contain,
                      alignment: Alignment.center,
                      width: double.infinity,
                      errorBuilder: (context, error, stackTrace) =>
                          const Text('No se pudo cargar la imagen publicitaria'),
                    ),
                  ),
              ),
            ]          ],
        ),
      );
    }
  }
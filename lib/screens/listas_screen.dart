import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'player_detail_screen.dart';
import '../providers/service_providers.dart';
import '../services/player_filter_service.dart';
import '../utils/date_utils.dart';
import '../utils/posicion_utils.dart';
import '../utils/puntaje_utils.dart';
import '../widgets/entre_redes_app_bar.dart';
import '../widgets/zocalo_publicitario.dart';

class ListasScreen extends ConsumerStatefulWidget {
  const ListasScreen({super.key});

  @override
  ConsumerState<ListasScreen> createState() => _ListasScreenState();
}

class _ListasScreenState extends ConsumerState<ListasScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;

  // --- Search ---
  final TextEditingController _searchController = TextEditingController();
  Timer? _debounce;
  String _queryText = '';

  // --- Filtro por puntaje ---
  static const List<double> _valoresPuntaje = [5, 4.5, 4, 3.5, 3, 2.5, 2, 1.5, 1];
  List<double> _puntajesFiltro = [];

  // --- Ads ---
  String? adImageUrl;

  // --- IDs de equipos-contenedor (resueltos desde el JSON remoto) ---
  List<int> _esperaIds = [];
  List<int> _reservaIds = [];
  bool _idsResolved = false;

  // --- Tab 0: Lista de Espera ---
  List<dynamic> esperaPlayers = [];
  List<dynamic> filteredEspera = [];
  bool isLoadingEspera = true;
  String? errorEspera;

  // --- Tab 1: Lista de Reserva ---
  List<dynamic> reservaPlayers = [];
  List<dynamic> filteredReserva = [];
  bool _reservaStarted = false;
  bool isLoadingReserva = false;
  String? errorReserva;
  double _reservaLoadProgress = 0.0; // 0.0–1.0: fracción de equipos procesados
  int _reservaTotalEquipos = 0;      // total de equipos-contenedor de reserva
  int _reservaBufferCount = 0;       // jugadores encontrados durante la carga

  // --- Scroll controllers ---
  final ScrollController _scrollEspera = ScrollController();
  final ScrollController _scrollReserva = ScrollController();

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _tabController.addListener(_onTabChanged);
    _loadAds();
    _loadEspera();
  }

  // ───────────────────────────────────────────────
  // Ads
  // ───────────────────────────────────────────────

  Future<void> _loadAds() async {
    ref.read(remoteDataServiceProvider).fetchAdImages().then((ads) {
      if (!mounted) return;
      setState(() {
        adImageUrl = ads['jugadores'];
      });
    });
  }

  // ───────────────────────────────────────────────
  // Tab listener
  // ───────────────────────────────────────────────

  void _onTabChanged() {
    if (_tabController.index == 1 && !_reservaStarted) {
      _reservaStarted = true;
      setState(() => isLoadingReserva = true);
      _loadReserva();
    }
  }

  // ───────────────────────────────────────────────
  // Resolución de IDs (JSON remoto, una sola vez)
  // ───────────────────────────────────────────────

  Future<void> _resolveIds() async {
    if (_idsResolved) return;
    final listas =
        await ref.read(remoteDataServiceProvider).fetchListasJugadores();
    _esperaIds = List<int>.from(listas['espera'] ?? []);
    _reservaIds = List<int>.from(listas['reserva'] ?? []);
    _idsResolved = true;
  }

  /// Trae todos los jugadores de un conjunto de equipos-contenedor, paginando
  /// cada equipo hasta agotarlo. Deduplica por id. `onProgress` se invoca tras
  /// completar cada equipo con (equiposProcesados, totalEquipos, jugadoresAcum).
  Future<List<dynamic>> _fetchJugadoresDeEquipos(
    List<int> equipoIds, {
    void Function(int done, int total, int count)? onProgress,
  }) async {
    final all = <dynamic>[];
    final seenIds = <dynamic>{};
    for (var i = 0; i < equipoIds.length; i++) {
      int page = 1;
      while (true) {
        final res = await ref.read(apiServiceProvider).getJugadoresRaw(
              equipoId: equipoIds[i],
              page: page,
              perPage: 100,
            );
        final items = List<dynamic>.from(res['items'] ?? []);
        for (final j in items) {
          if (seenIds.add(j['id'])) all.add(j);
        }
        if (items.length < 100) break;
        page++;
      }
      onProgress?.call(i + 1, equipoIds.length, all.length);
    }
    return all;
  }

  // ───────────────────────────────────────────────
  // Tab 0 — Lista de Espera
  // ───────────────────────────────────────────────

  Future<void> _loadEspera() async {
    if (!mounted) return;
    setState(() {
      isLoadingEspera = true;
      errorEspera = null;
    });

    try {
      await _resolveIds();
      final all = await _fetchJugadoresDeEquipos(_esperaIds);
      if (!mounted) return;
      setState(() {
        esperaPlayers = all;
        filteredEspera = _applyFilters(all);
        isLoadingEspera = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        isLoadingEspera = false;
        errorEspera = 'Error al cargar la lista de espera: $e';
      });
    }
  }

  // ───────────────────────────────────────────────
  // Tab 1 — Lista de Reserva
  // ───────────────────────────────────────────────

  Future<void> _loadReserva() async {
    try {
      await _resolveIds();

      if (_reservaIds.isEmpty) {
        if (!mounted) return;
        setState(() {
          reservaPlayers = [];
          filteredReserva = [];
          isLoadingReserva = false;
        });
        return;
      }

      if (mounted) {
        setState(() {
          _reservaLoadProgress = 0.0;
          _reservaTotalEquipos = _reservaIds.length;
          _reservaBufferCount = 0;
          errorReserva = null;
        });
      }

      final all = await _fetchJugadoresDeEquipos(
        _reservaIds,
        onProgress: (done, total, count) {
          if (!mounted) return;
          setState(() {
            _reservaTotalEquipos = total;
            _reservaLoadProgress =
                total > 0 ? (done / total).clamp(0.0, 0.95) : 0.0;
            _reservaBufferCount = count;
          });
        },
      );

      if (!mounted) return;
      setState(() {
        reservaPlayers = all;
        filteredReserva = _applyFilters(all);
        isLoadingReserva = false;
        _reservaLoadProgress = 1.0;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        isLoadingReserva = false;
        errorReserva = 'Error al cargar la lista de reserva: $e';
      });
    }
  }

  void _retryReserva() {
    if (!mounted) return;
    setState(() {
      errorReserva = null;
      isLoadingReserva = true;
      _reservaLoadProgress = 0.0;
      _reservaTotalEquipos = 0;
      _reservaBufferCount = 0;
      reservaPlayers = [];
      filteredReserva = [];
    });
    _loadReserva();
  }

  // ───────────────────────────────────────────────
  // Search + filtro puntaje
  // ───────────────────────────────────────────────

  List<dynamic> _applyFilters(List<dynamic> players) {
    final result = PlayerFilterService.filtrar(
      players,
      query: _queryText,
      puntajes: _puntajesFiltro,
    );
    if (_puntajesFiltro.isNotEmpty) {
      result.sort(PlayerFilterService.comparadorPuntaje);
    }
    return result;
  }

  void _onSearchChanged(String query) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 300), () {
      if (!mounted) return;
      setState(() {
        _queryText = query;
        filteredEspera = _applyFilters(esperaPlayers);
        filteredReserva = _applyFilters(reservaPlayers);
      });
    });
  }

  void _onPuntajeToggled(double valor) {
    setState(() {
      if (_puntajesFiltro.contains(valor)) {
        _puntajesFiltro.remove(valor);
      } else {
        _puntajesFiltro.add(valor);
      }
      filteredEspera = _applyFilters(esperaPlayers);
      filteredReserva = _applyFilters(reservaPlayers);
    });
  }

  // ───────────────────────────────────────────────
  // Dispose
  // ───────────────────────────────────────────────

  @override
  void dispose() {
    _debounce?.cancel();
    _tabController.removeListener(_onTabChanged);
    _tabController.dispose();
    _scrollEspera.dispose();
    _scrollReserva.dispose();
    _searchController.dispose();
    super.dispose();
  }

  // ───────────────────────────────────────────────
  // Filtro por puntaje
  // ───────────────────────────────────────────────

  Widget _buildPuntajeFilter() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 4, 12, 8),
      child: Row(
        children: [
          const Text(
            'Puntaje:',
            style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: Colors.black54),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: _valoresPuntaje.map((valor) {
                  final isSelected = _puntajesFiltro.contains(valor);
                  final label = valor == valor.truncateToDouble()
                      ? valor.toInt().toString()
                      : valor.toString();
                  return Padding(
                    padding: const EdgeInsets.only(right: 6),
                    child: GestureDetector(
                      onTap: () => _onPuntajeToggled(valor),
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 150),
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                        decoration: BoxDecoration(
                          color: isSelected ? Theme.of(context).colorScheme.primary : Colors.grey[200],
                          borderRadius: BorderRadius.circular(20),
                        ),
                        child: Text(
                          label,
                          style: TextStyle(
                            fontSize: 13,
                            fontWeight: FontWeight.w600,
                            color: isSelected ? Colors.white : Colors.black87,
                          ),
                        ),
                      ),
                    ),
                  );
                }).toList(),
              ),
            ),
          ),
        ],
      ),
    );
  }

  // ───────────────────────────────────────────────
  // Build
  // ───────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: EntreRedesAppBar(
        title: 'Listas de Jugadores',
        bottom: TabBar(
          controller: _tabController,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white70,
          indicatorColor: Colors.white,
          tabs: const [
            Tab(text: 'Lista de Espera'),
            Tab(text: 'Lista de Reserva'),
          ],
        ),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
            child: TextField(
              controller: _searchController,
              onChanged: _onSearchChanged,
              decoration: InputDecoration(
                hintText: 'Buscar jugador...',
                prefixIcon: const Icon(Icons.search),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                ),
                filled: true,
                fillColor: Colors.grey[100],
              ),
            ),
          ),
          _buildPuntajeFilter(),
          Expanded(
            child: TabBarView(
              controller: _tabController,
              children: [
                _buildEsperaTab(),
                _buildReservaTab(),
              ],
            ),
          ),
        ],
      ),
      bottomNavigationBar: const ZocaloPublicitario(),
    );
  }

  // ───────────────────────────────────────────────
  // Tab 0 widget
  // ───────────────────────────────────────────────

  Widget _buildEsperaTab() {
    if (isLoadingEspera) {
      return LoadingSeccionConAd(
        texto: 'Cargando jugadores...',
        adImageUrl: adImageUrl,
      );
    }
    if (errorEspera != null) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.error_outline, size: 48, color: Colors.red),
            const SizedBox(height: 12),
            Text(
              errorEspera!,
              style: const TextStyle(color: Colors.red),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 16),
            ElevatedButton(
              onPressed: _loadEspera,
              child: const Text('Reintentar'),
            ),
          ],
        ),
      );
    }
    if (filteredEspera.isEmpty) {
      return const Center(child: Text('No se encontraron jugadores.'));
    }
    return ListView.builder(
      controller: _scrollEspera,
      itemCount: filteredEspera.length,
      itemBuilder: (context, index) => _buildPlayerRow(filteredEspera[index]),
    );
  }

  // ───────────────────────────────────────────────
  // Tab 1 widget
  // ───────────────────────────────────────────────

  Widget _buildReservaTab() {
    if (!_reservaStarted || isLoadingReserva) {
      return _ReservaLoadingWidget(
        progress: _reservaTotalEquipos > 0 ? _reservaLoadProgress : null,
        loadedCount: _reservaBufferCount,
      );
    }
    if (errorReserva != null) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.error_outline, size: 48, color: Colors.red),
            const SizedBox(height: 12),
            Text(
              errorReserva!,
              style: const TextStyle(color: Colors.red),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 16),
            ElevatedButton(
              onPressed: _retryReserva,
              child: const Text('Reintentar'),
            ),
          ],
        ),
      );
    }
    if (filteredReserva.isEmpty) {
      return const Center(child: Text('No se encontraron jugadores.'));
    }
    return ListView.builder(
      controller: _scrollReserva,
      itemCount: filteredReserva.length,
      itemBuilder: (context, index) => _buildPlayerRow(filteredReserva[index]),
    );
  }

  // ───────────────────────────────────────────────
  // Player row (compartido entre ambos tabs)
  // ───────────────────────────────────────────────

  Widget _buildPlayerRow(dynamic j) {
    try {
      final rawTitle = j['title'];
      final nombre = (rawTitle is Map &&
              rawTitle['rendered'] is String &&
              rawTitle['rendered'].toString().isNotEmpty)
          ? rawTitle['rendered']
          : (j['title']?.toString().isNotEmpty == true
              ? j['title'].toString()
              : 'Sin nombre');

      final edad = calcularEdad(j['fecha_nacimiento']);

      final rawFoto = j['featured_image'];
      final foto = (rawFoto is String && rawFoto.isNotEmpty) ? rawFoto : null;

      final metrics = j['metrics'];
      final puntaje = formatearPuntaje(
          metrics is Map ? metrics['puntaje'] : null);

      final rawPos = (j['posicion'] ?? '').toString();
      final posicion = posicionAbreviada(rawPos);
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
                      builder: (_) => PlayerDetailScreen(player: j),
                    ),
                  );
                },
                contentPadding: const EdgeInsets.only(
                    left: 36, right: 16, top: 12, bottom: 12),
                leading: foto != null
                    ? CircleAvatar(backgroundImage: NetworkImage(foto))
                    : const Icon(Icons.person, size: 40),
                title: Text(nombre,
                    style: const TextStyle(fontWeight: FontWeight.w600)),
                subtitle: Text('Edad: $edad',
                    style: const TextStyle(fontSize: 13)),
                trailing: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Text('Pts.', style: TextStyle(fontSize: 11)),
                    Text(
                      puntaje,
                      style: const TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 16,
                      ),
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
    } catch (e, stack) {
      debugPrint('🛑 Error al renderizar jugador: $e\n$stack');
      debugPrint('🛑 Datos del jugador problemático: $j');
      return const ListTile(
        title: Text('Error al mostrar jugador'),
        subtitle: Text('Este jugador tiene datos inválidos.'),
      );
    }
  }
}

// ───────────────────────────────────────────────
// Widget de carga con progreso para Lista de Reserva
// ───────────────────────────────────────────────

class _ReservaLoadingWidget extends StatelessWidget {
  /// null = indeterminado (aún sin equipos procesados)
  final double? progress;

  /// Jugadores encontrados en el buffer hasta el momento
  final int loadedCount;

  const _ReservaLoadingWidget({
    required this.progress,
    required this.loadedCount,
  });

  @override
  Widget build(BuildContext context) {
    final percent = progress != null ? (progress! * 100).round() : 0;
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 32),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text(
              'Cargando lista de reserva...',
              style: TextStyle(fontSize: 15, color: Colors.grey[600]),
            ),
            const SizedBox(height: 20),
            FractionallySizedBox(
              widthFactor: 0.8,
              child: ClipRRect(
                borderRadius: BorderRadius.circular(4),
                child: LinearProgressIndicator(
                  value: progress,
                  minHeight: 8,
                ),
              ),
            ),
            const SizedBox(height: 12),
            if (progress != null)
              Text(
                '$percent%',
                style: const TextStyle(
                    fontSize: 18, fontWeight: FontWeight.bold),
              ),
            if (loadedCount > 0) ...[
              const SizedBox(height: 6),
              Text(
                '$loadedCount jugadores encontrados',
                style: TextStyle(fontSize: 13, color: Colors.grey[600]),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

// ───────────────────────────────────────────────
// LoadingSeccionConAd (compartido con otras pantallas)
// ───────────────────────────────────────────────

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
          ]
        ],
      ),
    );
  }
}

import 'dart:async';
import 'dart:math';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'player_detail_screen.dart';
import '../providers/service_providers.dart';
import '../providers/temporadas_provider.dart';
import '../services/player_filter_service.dart';
import '../utils/date_utils.dart';
import '../utils/posicion_utils.dart';
import '../utils/puntaje_utils.dart';
import '../widgets/entre_redes_app_bar.dart';
import '../widgets/zocalo_publicitario.dart';

class PlayersScreen extends ConsumerStatefulWidget {
  const PlayersScreen({super.key});

  @override
  ConsumerState<PlayersScreen> createState() => _PlayersScreenState();
}

class _PlayersScreenState extends ConsumerState<PlayersScreen>
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

  // --- Temporada actual (resuelto via temporadaActualProvider) ---
  int? _temporadaActualId;
  String? _temporadaActualName;

  // --- Tab 0: Temporada Actual ---
  List<dynamic> currentPlayers = [];
  List<dynamic> filteredCurrent = [];
  bool isLoadingCurrent = true;
  String? errorCurrent;

  // --- Tab 1: Histórico ---
  List<dynamic> historicPlayers = [];
  List<dynamic> filteredHistoric = [];
  bool _historicStarted = false;
  bool isLoadingHistoric = false;
  String? errorHistoric;
  double _historicLoadProgress = 0.0;   // 0.0–1.0: fracción de páginas cargadas
  int _historicTotalPages = 0;           // conocido tras la primera página
  int _historicBufferCount = 0;          // históricos encontrados durante la carga

  // --- Scroll controllers ---
  final ScrollController _scrollCurrent = ScrollController();
  final ScrollController _scrollHistoric = ScrollController();

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _tabController.addListener(_onTabChanged);
    _loadAds();
    _loadCurrentPlayers();
  }

  // ───────────────────────────────────────────────
  // Ads
  // ───────────────────────────────────────────────

  Future<void> _loadAds() async {
    ref.read(remoteDataServiceProvider).fetchAdImages().then((ads) {
      if (!mounted) return;
      setState(() {
        //adImageUrl = ads['jugadores'];
      });
    });
  }

  // ───────────────────────────────────────────────
  // Tab listener
  // ───────────────────────────────────────────────

  void _onTabChanged() {
    if (_tabController.index == 1 && !_historicStarted) {
      _historicStarted = true;
      setState(() => isLoadingHistoric = true);
      _loadHistoricInitial();
    }
  }

  // ───────────────────────────────────────────────
  // Tab 0 — Temporada Actual
  // ───────────────────────────────────────────────

  Future<void> _loadCurrentPlayers() async {
    if (!mounted) return;
    setState(() {
      isLoadingCurrent = true;
      errorCurrent = null;
    });

    // Resolver temporada actual (solo si no se resolvió antes)
    if (_temporadaActualId == null) {
      try {
        final temporada = await ref.read(temporadaActualProvider.future);
        _temporadaActualId = temporada.id;
        _temporadaActualName = temporada.name;
      } catch (e) {
        if (!mounted) return;
        setState(() {
          isLoadingCurrent = false;
          errorCurrent = 'No se pudo resolver la temporada actual.';
        });
        return;
      }
    }

    // Check caché (clave por temporadaId para evitar datos stale de versiones anteriores)
    try {
      final cached = await ref
          .read(cacheServiceProvider)
          .getCachedPlayersCurrentSeason(_temporadaActualId!);
      if (cached != null && cached.isNotEmpty) {
        if (!mounted) return;
        setState(() {
          currentPlayers = List.from(cached);
          filteredCurrent = _applyFilters(currentPlayers);
          isLoadingCurrent = false;
        });
        return;
      }
    } catch (_) {
      // caché inaccesible → continuar con API
    }

    // Fetch desde API (paginado hasta agotar temporada actual)
    try {
      final all = <dynamic>[];
      int page = 1;
      while (true) {
        final res = await ref.read(apiServiceProvider).getJugadoresRaw(
              temporada: _temporadaActualId,
              page: page,
              perPage: 100,
            );
        final items = List<dynamic>.from(res['items'] ?? []);
        all.addAll(items);
        if (items.length < 100) break;
        page++;
      }

      await ref
          .read(cacheServiceProvider)
          .cachePlayersCurrentSeason(_temporadaActualId!, all);

      if (!mounted) return;
      setState(() {
        currentPlayers = all;
        filteredCurrent = _applyFilters(all);
        isLoadingCurrent = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        isLoadingCurrent = false;
        errorCurrent = 'Error al cargar jugadores: $e';
      });
    }
  }

  // ───────────────────────────────────────────────
  // Tab 1 — Histórico
  // ───────────────────────────────────────────────

  Future<void> _loadHistoricInitial() async {
    // Check caché — carga instantánea sin indicador de progreso
    try {
      final cached =
          await ref.read(cacheServiceProvider).getCachedPlayersHistoricos();
      if (cached != null && cached.isNotEmpty) {
        if (!mounted) return;
        setState(() {
          historicPlayers = List.from(cached);
          filteredHistoric = _applyFilters(historicPlayers);
          isLoadingHistoric = false;
        });
        return;
      }
    } catch (_) {
      // caché inaccesible → continuar con API
    }

    // Sin caché: fetch completo con seguimiento de progreso
    if (mounted) {
      setState(() {
        _historicLoadProgress = 0.0;
        _historicTotalPages = 0;
        _historicBufferCount = 0;
        errorHistoric = null;
      });
    }

    try {
      // Página 1: obtiene total (si el servidor lo provee) y primera tanda de items
      final res1 = await ref
          .read(apiServiceProvider)
          .getJugadoresRaw(page: 1, perPage: 100);
      final items1 = List<dynamic>.from(res1['items'] ?? []);

      if (items1.isEmpty) {
        if (!mounted) return;
        setState(() {
          historicPlayers = [];
          filteredHistoric = [];
          isLoadingHistoric = false;
        });
        return;
      }

      // totalPages conocido si x-wp-total está disponible; si no, heurística
      final rawTotal = res1['total'] as int?;
      final knownTotalPages = (rawTotal != null && rawTotal > 0)
          ? max(1, (rawTotal / 100).ceil())
          : null;
      final firstPageFull = items1.length >= 100;
      // Si la primera página está incompleta, sabemos que es la única
      final resolvedTotalPages =
          knownTotalPages ?? (firstPageFull ? null : 1);

      final buffer = List<dynamic>.from(items1);
      final seenIds = <dynamic>{...items1.map((j) => j['id'])};

      if (!mounted) return;
      setState(() {
        _historicTotalPages = resolvedTotalPages ?? 0;
        _historicLoadProgress =
            resolvedTotalPages != null ? (1 / resolvedTotalPages).clamp(0.0, 0.95) : 0.0;
        _historicBufferCount = items1.where(_esHistorico).length;
      });

      // Páginas restantes — continúa hasta que una página sea incompleta
      if (firstPageFull) {
        int page = 2;
        while (true) {
          if (!mounted) return;
          final res = await ref
              .read(apiServiceProvider)
              .getJugadoresRaw(page: page, perPage: 100);
          final items = List<dynamic>.from(res['items'] ?? []);
          for (final j in items) {
            if (seenIds.add(j['id'])) {
              buffer.add(j);
            }
          }
          // Para el progreso: usar knownTotalPages si existe,
          // si no, mostrar indeterminado (0) hasta la última página
          final progressDenominator =
              knownTotalPages != null && knownTotalPages > 0
                  ? knownTotalPages
                  : (items.length < 100 ? page : 0);
          if (!mounted) return;
          setState(() {
            if (progressDenominator > 0) {
              _historicTotalPages = progressDenominator;
              _historicLoadProgress =
                  (page / progressDenominator).clamp(0.0, 0.95);
            }
            _historicBufferCount = buffer.where(_esHistorico).length;
          });
          if (items.length < 100) break; // última página
          page++;
        }
      }

      // Filtrar históricos del buffer completo y persistir en caché
      final allHistoric = buffer.where(_esHistorico).toList();
      await ref
          .read(cacheServiceProvider)
          .cachePlayersHistoricos(allHistoric);

      if (!mounted) return;
      setState(() {
        historicPlayers = allHistoric;
        filteredHistoric = _applyFilters(allHistoric);
        isLoadingHistoric = false;
        _historicLoadProgress = 1.0;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        isLoadingHistoric = false;
        errorHistoric = 'Error al cargar jugadores históricos: $e';
      });
    }
  }

  /// Un jugador es histórico si no pertenece a la temporada actual.
  /// Si `temporadas` está ausente o vacío → se trata como histórico.
  bool _esHistorico(dynamic j) {
    if (_temporadaActualName == null) return true;
    final temporadas = j['temporadas'];
    if (temporadas == null) return true;
    if (temporadas is List && temporadas.isEmpty) return true;
    if (temporadas is List) {
      return !temporadas.any((t) => t.toString() == _temporadaActualName);
    }
    return true;
  }

  void _retryHistoric() {
    if (!mounted) return;
    setState(() {
      errorHistoric = null;
      isLoadingHistoric = true;
      _historicLoadProgress = 0.0;
      _historicTotalPages = 0;
      _historicBufferCount = 0;
      historicPlayers = [];
      filteredHistoric = [];
    });
    _loadHistoricInitial();
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
        filteredCurrent  = _applyFilters(currentPlayers);
        filteredHistoric = _applyFilters(historicPlayers);
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
      filteredCurrent  = _applyFilters(currentPlayers);
      filteredHistoric = _applyFilters(historicPlayers);
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
    _scrollCurrent.dispose();
    _scrollHistoric.dispose();
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
        title: 'Jugadores',
        bottom: TabBar(
          controller: _tabController,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white70,
          indicatorColor: Colors.white,
          tabs: const [
            Tab(text: 'Temporada Actual'),
            Tab(text: 'Histórico'),
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
                _buildCurrentTab(),
                _buildHistoricTab(),
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

  Widget _buildCurrentTab() {
    if (isLoadingCurrent) {
      return LoadingSeccionConAd(
        texto: 'Cargando jugadores...',
        adImageUrl: adImageUrl,
      );
    }
    if (errorCurrent != null) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.error_outline, size: 48, color: Colors.red),
            const SizedBox(height: 12),
            Text(
              errorCurrent!,
              style: const TextStyle(color: Colors.red),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 16),
            ElevatedButton(
              onPressed: _loadCurrentPlayers,
              child: const Text('Reintentar'),
            ),
          ],
        ),
      );
    }
    if (filteredCurrent.isEmpty) {
      return const Center(child: Text('No se encontraron jugadores.'));
    }
    return ListView.builder(
      controller: _scrollCurrent,
      itemCount: filteredCurrent.length,
      itemBuilder: (context, index) => _buildPlayerRow(filteredCurrent[index]),
    );
  }

  // ───────────────────────────────────────────────
  // Tab 1 widget
  // ───────────────────────────────────────────────

  Widget _buildHistoricTab() {
    if (!_historicStarted || isLoadingHistoric) {
      return _HistoricLoadingWidget(
        progress: _historicTotalPages > 0 ? _historicLoadProgress : null,
        loadedCount: _historicBufferCount,
      );
    }
    if (errorHistoric != null) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.error_outline, size: 48, color: Colors.red),
            const SizedBox(height: 12),
            Text(
              errorHistoric!,
              style: const TextStyle(color: Colors.red),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 16),
            ElevatedButton(
              onPressed: _retryHistoric,
              child: const Text('Reintentar'),
            ),
          ],
        ),
      );
    }
    if (filteredHistoric.isEmpty) {
      return const Center(
          child: Text('No se encontraron jugadores históricos.'));
    }
    return ListView.builder(
      controller: _scrollHistoric,
      itemCount: filteredHistoric.length,
      itemBuilder: (context, index) => _buildPlayerRow(filteredHistoric[index]),
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
// Widget de carga con progreso para Histórico
// ───────────────────────────────────────────────

class _HistoricLoadingWidget extends StatelessWidget {
  /// null = indeterminado (aún esperando respuesta de página 1)
  final double? progress;

  /// Históricos encontrados en el buffer hasta el momento
  final int loadedCount;

  const _HistoricLoadingWidget({
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
              'Cargando jugadores históricos...',
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

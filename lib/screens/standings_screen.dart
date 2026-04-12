import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../widgets/entre_redes_app_bar.dart';
import '../widgets/zocalo_publicitario.dart';
import '../models/temporada.dart';
import '../providers/service_providers.dart';
import '../providers/partidos_cache_provider.dart';
import '../providers/repository_providers.dart';
import '../providers/temporadas_provider.dart';
import '../services/standings_service.dart';
import '../utils/liga_utils.dart';
import '../providers/config_provider.dart';
import 'team_detail_screen.dart';

class StandingsScreen extends ConsumerStatefulWidget {
  const StandingsScreen({super.key});

  @override
  ConsumerState<StandingsScreen> createState() => _StandingsScreenState();
}

class _StandingsScreenState extends ConsumerState<StandingsScreen> with TickerProviderStateMixin {
  late TabController _tabController;
  List<Temporada> temporadas = [];
  Temporada? temporadaActual;
  List<String>? _ligasOrden;

  // ── Estado Temporada Actual ──
  List<dynamic> _actPosiciones = [];
  List<dynamic> _actTodasLasPosiciones = [];
  List<String> _actTitulos = [];
  String? _actTituloSeleccionado;
  int _actPage = 1;
  final int _perPage = 20;
  bool _actIsLoading = false;
  bool _actHasMore = true;
  late ScrollController _actScrollController;
  int _actGeneration = 0;

  // ── Estado Histórico ──
  List<dynamic> _histPosiciones = [];
  List<dynamic> _histTodasLasPosiciones = [];
  List<String> _histTitulos = [];
  String? _histTituloSeleccionado;
  int? _histTemporadaId;
  int _histPage = 1;
  bool _histIsLoading = false;
  bool _histHasMore = true;
  late ScrollController _histScrollController;
  int _histGeneration = 0;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _actScrollController = ScrollController()..addListener(_onScrollActual);
    _histScrollController = ScrollController()..addListener(_onScrollHist);
    _loadConfig();
    _loadTemporadas();
  }

  @override
  void dispose() {
    _tabController.dispose();
    _actScrollController.dispose();
    _histScrollController.dispose();
    super.dispose();
  }

  Future<void> _loadConfig() async {
    final config = await ref.read(appConfigProvider.future);
    if (mounted && config?.ligasOrden != null) {
      setState(() => _ligasOrden = config!.ligasOrden);
    }
  }

  Future<void> _loadTemporadas() async {
    try {
      final data = await ref.read(temporadasProvider.future);
      final parsed = data.map<Temporada>((t) => Temporada.fromJson(t)).toList();
      final actual = parsed.firstWhere((t) => t.isCurrent, orElse: () => parsed.first);
      setState(() {
        temporadas = parsed;
        temporadaActual = actual;
      });
      await _cargarPartidos(actual.id);
      await _obtenerPartidosTemporada(actual.id);
      await _loadTablasDesdeCache(isActual: true);
      await _fetchTablas(isActual: true);
    } catch (e) {
      debugPrint('Error al cargar temporadas: $e');
    }
  }

  Future<void> _cargarPartidos(int temporadaId) async {
    await ref.read(partidosCacheProvider).getPartidosJugados(temporadaId);
  }

  // ── Scroll listeners ──

  void _onScrollActual() {
    if (_actScrollController.position.pixels >= _actScrollController.position.maxScrollExtent - 200 &&
        !_actIsLoading && _actHasMore) {
      _fetchTablas(isActual: true, generation: _actGeneration);
    }
  }

  void _onScrollHist() {
    if (_histScrollController.position.pixels >= _histScrollController.position.maxScrollExtent - 200 &&
        !_histIsLoading && _histHasMore) {
      _fetchTablas(isActual: false, generation: _histGeneration);
    }
  }

  // ── Fetch tablas (compartido) ──

  Future<void> _fetchTablas({required bool isActual, int? generation}) async {
    final hasMore = isActual ? _actHasMore : _histHasMore;
    final isLoading = isActual ? _actIsLoading : _histIsLoading;
    if (!hasMore || isLoading) return;

    final temporadaId = isActual ? temporadaActual?.id : _histTemporadaId;
    if (temporadaId == null) return;

    final myGen = generation ?? (isActual ? _actGeneration : _histGeneration);
    setState(() {
      if (isActual) _actIsLoading = true;
      else _histIsLoading = true;
    });

    try {
      final page = isActual ? _actPage : _histPage;
      final response = await ref.read(apiRepositoryProvider).getTablas(
        temporada: temporadaId,
        page: page,
        perPage: _perPage,
      );

      final currentGen = isActual ? _actGeneration : _histGeneration;
      if (myGen != currentGen) return;

      final List<dynamic> nuevasTablas = response['items'] ?? [];
      final todasPrev = isActual ? _actTodasLasPosiciones : _histTodasLasPosiciones;

      if (page == 1 && nuevasTablas.isNotEmpty) {
        await _guardarCacheTablas(temporadaId, nuevasTablas);
      }

      final Map<String, dynamic> uniqueTables = {};
      for (var tabla in todasPrev) {
        uniqueTables[tabla['titulo'] ?? ''] = tabla;
      }
      for (var tabla in nuevasTablas) {
        if (!uniqueTables.containsKey(tabla['titulo'] ?? '')) {
          uniqueTables[tabla['titulo'] ?? ''] = tabla;
        }
      }

      final nuevasTodas = uniqueTables.values.toList();
      final nuevosTitulos = _sortTitulos(nuevasTodas);
      setState(() {
        if (isActual) {
          _actPage++;
          _actTodasLasPosiciones = nuevasTodas;
          _actTitulos = nuevosTitulos;
          if (_actTituloSeleccionado == null && nuevosTitulos.isNotEmpty) {
            _actTituloSeleccionado = nuevosTitulos.first;
          }
          _actPosiciones = _filtrar(nuevasTodas, _actTituloSeleccionado);
          _actHasMore = nuevasTablas.length == _perPage;
        } else {
          _histPage++;
          _histTodasLasPosiciones = nuevasTodas;
          _histTitulos = nuevosTitulos;
          if (_histTituloSeleccionado == null && nuevosTitulos.isNotEmpty) {
            _histTituloSeleccionado = nuevosTitulos.first;
          }
          _histPosiciones = _filtrar(nuevasTodas, _histTituloSeleccionado);
          _histHasMore = nuevasTablas.length == _perPage;
        }
      });
    } catch (e) {
      debugPrint('Error al cargar tablas: $e');
    } finally {
      final currentGen = isActual ? _actGeneration : _histGeneration;
      if (myGen == currentGen && mounted) {
        setState(() {
          if (isActual) _actIsLoading = false;
          else _histIsLoading = false;
        });
      }
    }
  }

  // ── Cache ──

  Future<void> _guardarCacheTablas(int temporadaId, List<dynamic> data) async {
    final prefs = await SharedPreferences.getInstance();
    final payload = {
      'timestamp': DateTime.now().millisecondsSinceEpoch,
      'data': data,
    };
    prefs.setString('cache_tablas_$temporadaId', jsonEncode(payload));
  }

  Future<void> _loadTablasDesdeCache({required bool isActual}) async {
    final temporadaId = isActual ? temporadaActual?.id : _histTemporadaId;
    if (temporadaId == null) return;

    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString('cache_tablas_$temporadaId');
    if (raw == null) return;

    final decoded = jsonDecode(raw);
    final timestamp = decoded['timestamp'] as int;
    final now = DateTime.now().millisecondsSinceEpoch;
    if ((now - timestamp) >= 3600000) return;

    final cached = List<dynamic>.from(decoded['data']);
    final nuevosTitulos = _sortTitulos(cached);

    setState(() {
      if (isActual) {
        _actTodasLasPosiciones = cached;
        _actTitulos = nuevosTitulos;
        if (_actTituloSeleccionado == null && nuevosTitulos.isNotEmpty) {
          _actTituloSeleccionado = nuevosTitulos.first;
        }
        _actPosiciones = _filtrar(cached, _actTituloSeleccionado);
      } else {
        _histTodasLasPosiciones = cached;
        _histTitulos = nuevosTitulos;
        if (_histTituloSeleccionado == null && nuevosTitulos.isNotEmpty) {
          _histTituloSeleccionado = nuevosTitulos.first;
        }
        _histPosiciones = _filtrar(cached, _histTituloSeleccionado);
      }
    });
  }

  // ── Histórico: selección de temporada ──

  void _histSeleccionarTemporada(Temporada t) async {
    final myGen = ++_histGeneration;
    setState(() {
      _histTemporadaId = t.id;
      _histPosiciones.clear();
      _histTodasLasPosiciones.clear();
      _histTitulos.clear();
      _histTituloSeleccionado = null;
      _histPage = 1;
      _histHasMore = true;
      _histIsLoading = false;
    });

    try {
      await _cargarPartidos(t.id);
      await _obtenerPartidosTemporada(t.id);
      if (myGen != _histGeneration) return;
      await _loadTablasDesdeCache(isActual: false);
      if (myGen != _histGeneration) return;
      await _fetchTablas(isActual: false, generation: myGen);
    } catch (e) {
      debugPrint('Error en _histSeleccionarTemporada: $e');
      if (mounted) setState(() => _histIsLoading = false);
    }
  }

  // ── Helpers ──

  List<String> _sortTitulos(List<dynamic> tablas) {
    final titulos = tablas
        .map<String>((t) => t['titulo']?.toString() ?? 'Sin título')
        .toSet()
        .toList();
    titulos.sort((a, b) {
      final p1 = prioridadLiga(a, orden: _ligasOrden) != 99
          ? prioridadLiga(a, orden: _ligasOrden)
          : prioridadTitulo(a);
      final p2 = prioridadLiga(b, orden: _ligasOrden) != 99
          ? prioridadLiga(b, orden: _ligasOrden)
          : prioridadTitulo(b);
      return (p1 != p2) ? p1.compareTo(p2) : a.compareTo(b);
    });
    return titulos;
  }

  List<dynamic> _filtrar(List<dynamic> todas, String? titulo) {
    if (titulo == null) return List.from(todas);
    return todas.where((t) => t['titulo'] == titulo).toList();
  }

  Future<List<dynamic>> _obtenerPartidosTemporada(int temporadaId) async {
    final pc = ref.read(partidosCacheProvider);
    final todosLosPartidos = pc.partidosJugados;

    final partidosFiltrados = todosLosPartidos.where((p) {
      return p['temporada']?.toString() == temporadaId.toString();
    }).toList();

    if (partidosFiltrados.isNotEmpty) {
      return partidosFiltrados;
    } else {
      final res = await ref.read(apiServiceProvider).getPartidos(
        temporada: temporadaId,
        page: 1,
        perPage: 500,
      );
      final fetched = res['items'] ?? [];
      final existingIds = pc.partidosJugados.map((p) => p['id']).toSet();
      for (final partido in fetched) {
        if (!existingIds.contains(partido['id'])) {
          pc.partidosJugados.add(partido);
          existingIds.add(partido['id']);
        }
      }
      await ref.read(cacheServiceProvider).cachePartidosJugadosPorTemporada(
        temporadaId,
        pc.partidosJugados,
      );
      return fetched;
    }
  }

  // ── Widgets compartidos ──

  Widget _buildTituloFilter(List<String> titulos, String? seleccionado, ValueChanged<String> onSelect) {
    if (titulos.isEmpty) return const SizedBox.shrink();
    final sorted = List<String>.from(titulos)
      ..sort((a, b) {
        final p1 = prioridadLiga(a, orden: _ligasOrden) != 99
            ? prioridadLiga(a, orden: _ligasOrden)
            : prioridadTitulo(a);
        final p2 = prioridadLiga(b, orden: _ligasOrden) != 99
            ? prioridadLiga(b, orden: _ligasOrden)
            : prioridadTitulo(b);
        if (p1 != p2) return p1.compareTo(p2);
        return a.compareTo(b);
      });

    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: sorted.map((titulo) {
          final isSelected = titulo == seleccionado;
          final displayTitle = titulo.split(' ').skip(1).join(' ');
          return Padding(
            padding: const EdgeInsets.symmetric(horizontal: 4),
            child: ElevatedButton(
              onPressed: () => onSelect(titulo),
              style: ElevatedButton.styleFrom(
                backgroundColor: isSelected ? const Color(0xFF00A3FF) : Colors.grey[200],
                foregroundColor: isSelected ? Colors.white : Colors.black87,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                elevation: isSelected ? 2 : 0,
              ),
              child: Text(displayTitle),
            ),
          );
        }).toList(),
      ),
    );
  }

  Widget _buildRow(dynamic tabla, int temporadaId) {
    final List<dynamic> equipos = tabla['equipos']
        .where((e) => (e['equipo']?.toString().toLowerCase() ?? '') != 'equipo')
        .toList();

    final todosLosPartidos = ref.read(partidosCacheProvider).partidosJugados;
    final partidosTemporada = todosLosPartidos.where((p) {
      return p['temporada']?.toString() == temporadaId.toString();
    }).toList();

    StandingsService.ordenarYAsignarPosicion(equipos, partidosTemporada);

    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.all(16),
            child: Text(
              tabla['titulo'] ?? 'Tabla sin título',
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Colors.black87),
            ),
          ),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: DataTable(
              columnSpacing: 12,
              headingRowHeight: 36,
              dataRowMinHeight: 48,
              dataRowMaxHeight: 48,
              columns: const [
                DataColumn(label: Text('#', style: TextStyle(fontWeight: FontWeight.bold))),
                DataColumn(label: Text('Equipo', style: TextStyle(fontWeight: FontWeight.bold))),
                DataColumn(label: Text('PTS', style: TextStyle(fontWeight: FontWeight.bold))),
                DataColumn(label: Text('PJ', style: TextStyle(fontWeight: FontWeight.bold))),
                DataColumn(label: Text('Gol', style: TextStyle(fontWeight: FontWeight.bold))),
                DataColumn(label: Text('+/-', style: TextStyle(fontWeight: FontWeight.bold))),
                DataColumn(label: Text('PG', style: TextStyle(fontWeight: FontWeight.bold))),
                DataColumn(label: Text('PE', style: TextStyle(fontWeight: FontWeight.bold))),
                DataColumn(label: Text('PP', style: TextStyle(fontWeight: FontWeight.bold))),
              ],
              rows: List<DataRow>.generate(equipos.length, (i) {
                final e = equipos[i];
                final String? logoUrl = (e['logo'] is String && e['logo'].toString().isNotEmpty) ? e['logo'] : null;
                return DataRow(
                  color: WidgetStateProperty.resolveWith<Color?>((states) => i.isEven ? Colors.grey[50] : null),
                  cells: [
                    DataCell(Text('${e['posicion']}')),
                    DataCell(
                      Row(
                        children: [
                          CircleAvatar(
                            radius: 14,
                            backgroundImage: logoUrl != null ? NetworkImage(logoUrl) : null,
                            backgroundColor: Colors.grey[300],
                            child: logoUrl == null ? const Icon(Icons.shield, size: 14) : null,
                          ),
                          const SizedBox(width: 8),
                          SizedBox(
                            width: 100,
                            child: Text(
                              e['equipo'],
                              style: const TextStyle(fontWeight: FontWeight.w500),
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ],
                      ),
                      onTap: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) => TeamDetailScreen(team: {
                            'id': e['id'],
                            'nombre': e['equipo'],
                            'imagen': logoUrl,
                          }),
                        ),
                      ),
                    ),
                    DataCell(Text('${e['pts']}', style: const TextStyle(fontWeight: FontWeight.bold))),
                    DataCell(Text('${e['pj']}')),
                    DataCell(Text('${e['gf']}:${e['gc']}')),
                    DataCell(Text('${e['dg']}')),
                    DataCell(Text('${e['pg']}')),
                    DataCell(Text('${e['pe']}')),
                    DataCell(Text('${e['pp']}')),
                  ],
                );
              }),
            ),
          ),
          const SizedBox(height: 16),
        ],
      ),
    );
  }

  Widget _buildTablaList({
    required List<dynamic> posiciones,
    required bool isLoading,
    required bool hasMore,
    required ScrollController scrollController,
    required int temporadaId,
  }) {
    if (posiciones.isEmpty && isLoading) {
      return const LoadingSeccionConAd(texto: 'Cargando tablas...');
    }
    return ListView.builder(
      controller: scrollController,
      itemCount: posiciones.length + (hasMore ? 1 : 0),
      padding: const EdgeInsets.only(top: 8),
      itemBuilder: (context, index) {
        if (index < posiciones.length) {
          return _buildRow(posiciones[index], temporadaId);
        }
        return const Padding(
          padding: EdgeInsets.symmetric(vertical: 16),
          child: Center(child: CircularProgressIndicator()),
        );
      },
    );
  }

  // ── Tab: Temporada Actual ──

  Widget _buildActualTab() {
    return Column(
      children: [
        Container(
          color: Colors.white,
          padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
          child: _buildTituloFilter(_actTitulos, _actTituloSeleccionado, (titulo) {
            setState(() {
              _actTituloSeleccionado = titulo;
              _actPosiciones = _filtrar(_actTodasLasPosiciones, titulo);
            });
          }),
        ),
        Expanded(
          child: Container(
            color: Colors.white,
            child: _buildTablaList(
              posiciones: _actPosiciones,
              isLoading: _actIsLoading,
              hasMore: _actHasMore,
              scrollController: _actScrollController,
              temporadaId: temporadaActual?.id ?? 0,
            ),
          ),
        ),
      ],
    );
  }

  // ── Tab: Histórico ──

  Widget _buildHistoricoTab() {
    final histTemporadas = List<Temporada>.from(temporadas)
      ..removeWhere((t) => t.isCurrent)
      ..sort((a, b) => b.name.compareTo(a.name));

    return Column(
      children: [
        Container(
          color: Colors.white,
          padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
          child: Column(
            children: [
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(
                  children: histTemporadas.map((t) {
                    final isSelected = t.id == _histTemporadaId;
                    return Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 4),
                      child: ElevatedButton(
                        onPressed: () => _histSeleccionarTemporada(t),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: isSelected ? const Color(0xFF00A3FF) : Colors.grey[200],
                          foregroundColor: isSelected ? Colors.white : Colors.black87,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                          elevation: isSelected ? 2 : 0,
                        ),
                        child: Text(t.name.isEmpty ? 'Temporada' : t.name),
                      ),
                    );
                  }).toList(),
                ),
              ),
              if (_histTemporadaId != null) ...[
                const SizedBox(height: 8),
                _buildTituloFilter(_histTitulos, _histTituloSeleccionado, (titulo) {
                  setState(() {
                    _histTituloSeleccionado = titulo;
                    _histPosiciones = _filtrar(_histTodasLasPosiciones, titulo);
                  });
                }),
              ],
            ],
          ),
        ),
        Expanded(
          child: Container(
            color: Colors.white,
            child: _histTemporadaId == null
                ? const Center(
                    child: Text(
                      'Selecciona una temporada',
                      style: TextStyle(fontSize: 16, color: Colors.black54),
                    ),
                  )
                : _buildTablaList(
                    posiciones: _histPosiciones,
                    isLoading: _histIsLoading,
                    hasMore: _histHasMore,
                    scrollController: _histScrollController,
                    temporadaId: _histTemporadaId!,
                  ),
          ),
        ),
      ],
    );
  }

  // ── Build ──

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: EntreRedesAppBar(
        title: 'Tablas de posiciones',
        centerTitle: true,
      ),
      body: Column(
        children: [
          Material(
            color: Colors.transparent,
            child: TabBar(
              controller: _tabController,
              labelColor: Theme.of(context).primaryColor,
              unselectedLabelColor: Colors.grey,
              indicatorColor: Theme.of(context).primaryColor,
              tabs: const [
                Tab(text: 'Temporada Actual'),
                Tab(text: 'Histórico'),
              ],
            ),
          ),
          Expanded(
            child: TabBarView(
        controller: _tabController,
        children: [
          _buildActualTab(),
              _buildHistoricoTab(),
            ],
          ),
          ),
        ],
      ),
      bottomNavigationBar: const ZocaloPublicitario(),
    );
  }
}

class LoadingSeccionConAd extends StatelessWidget {
  final String texto;
  final String? adImageUrl;

  const LoadingSeccionConAd({super.key, required this.texto, this.adImageUrl});

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
          ],
        ],
      ),
    );
  }
}

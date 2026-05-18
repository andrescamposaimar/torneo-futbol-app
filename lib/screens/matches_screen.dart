import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'match_detail_screen.dart';
import '../widgets/entre_redes_app_bar.dart';
import '../widgets/zocalo_publicitario.dart';
import 'dart:async';
import '../providers/service_providers.dart';
import '../providers/partidos_cache_provider.dart';
import '../utils/liga_utils.dart';

class MatchesScreen extends ConsumerStatefulWidget {
  final int temporadaId;
  const MatchesScreen({super.key, required this.temporadaId});

  @override
  ConsumerState<MatchesScreen> createState() => _MatchesScreenState();
}

class _MatchesScreenState extends ConsumerState<MatchesScreen> with TickerProviderStateMixin {
  late TabController _tabController;

  String selectedFilterJugados = 'fecha'; // 'fecha', 'zona' o 'equipo'
  int? selectedEquipoId;
  String? selectedEquipoNombre;
  String? selectedEquipoEscudo;
  List<dynamic> equiposTemporada = [];
  bool isCargandoEquipos = false;

  String? selectedZonaNombre;

  final ScrollController _scrollControllerJugados = ScrollController();

  List<dynamic> partidosJugados = [];

  int currentPageJugados = 1;

  bool isLoadingMoreJugados = false;

  bool isInitialLoadingJugados = true;

  bool hasMoreJugados = true;

  String? error;

  int selectedTab = 0;

  List<dynamic> partidosPorEquipo = [];
  bool isLoadingPorEquipo = false;

  // Fixture
  List<dynamic> todosLosPartidosProgramados = [];
  bool isLoadingFixture = false;
  bool _fixtureLoaded = false;
  String selectedFixtureFilter = 'fecha';
  String? selectedFixtureFecha;
  String? selectedFixtureZona;
  String? selectedFixtureEquipo;
  Map<String, String?> _equiposEscudos = {};


  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);
    _loadCachedThenFetchJugados();
    _loadFixture();

    _scrollControllerJugados.addListener(() {
      if (_scrollControllerJugados.position.pixels >= _scrollControllerJugados.position.maxScrollExtent - 200) {
        if (selectedFilterJugados != 'equipo') {
          if (!isLoadingMoreJugados && hasMoreJugados) {
            _fetchPartidosJugados();
          }
        }
      }
    });

    _tabController.addListener(() {
      if (mounted) {
        setState(() {
          selectedTab = _tabController.index;
        });
      }
    });
  }

  Widget _buildListaJugadosFiltrada() {
    if (isInitialLoadingJugados) return const Center(child: CircularProgressIndicator());

    if (selectedFilterJugados == 'fecha') return _buildListaPorFecha();
    if (selectedFilterJugados == 'zona') return _buildListaPorZona();
    if (selectedFilterJugados == 'equipo') return _buildListaPorEquipo();

    return const SizedBox.shrink();
  }

  String? _obtenerProximaFecha() {
    if (todosLosPartidosProgramados.isEmpty) return null;

    final fechasUnicas = todosLosPartidosProgramados
        .map((p) => p['fecha']?.toString() ?? '')
        .where((f) => f.isNotEmpty)
        .toSet()
        .toList()
      ..sort();

    final ahora = DateTime.now();
    final hoy = DateTime(ahora.year, ahora.month, ahora.day);
    final cutoffHoy = DateTime(ahora.year, ahora.month, ahora.day, 18, 0);

    for (final fecha in fechasUnicas) {
      final parsed = DateTime.tryParse(fecha);
      if (parsed == null) continue;
      final dia = DateTime(parsed.year, parsed.month, parsed.day);

      if (dia.isAfter(hoy)) return fecha;       // fecha futura → es la próxima
      if (dia == hoy && ahora.isBefore(cutoffHoy)) return fecha; // hoy antes de las 18hs
      // hoy >= 18hs → saltar esta fecha
    }
    return null;
  }

  Widget _buildListaProximosPartidos() {
    if (isLoadingFixture) return const Center(child: CircularProgressIndicator());

    final proximaFecha = _obtenerProximaFecha();

    if (proximaFecha == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24.0),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.event_busy, size: 64, color: Colors.grey.shade400),
              const SizedBox(height: 16),
              const Text(
                'Próxima Fecha aún no ha sido cargada por la comisión de fútbol',
                style: TextStyle(fontSize: 16, color: Colors.black54),
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      );
    }

    final partidosProxFecha = todosLosPartidosProgramados
        .where((p) => p['fecha']?.toString() == proximaFecha)
        .toList();

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
          child: Text(
            _formatearFechaLarga(proximaFecha),
            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: ListView.builder(
            itemCount: partidosProxFecha.length,
            itemBuilder: (context, index) => _buildMatchCard(partidosProxFecha[index]),
          ),
        ),
      ],
    );
  }

  @override
  void dispose() {
    _scrollControllerJugados.dispose();
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _loadCachedThenFetchJugados() async {
    final cached = await _loadCache('jugados');
    if (cached != null) {
      setState(() {
        partidosJugados = cached;
        currentPageJugados = 2;
        isInitialLoadingJugados = false;
      });
      _fetchPartidosJugados();
    } else {
      await _fetchPartidosJugados(initial: true);
    }
  }

  Future<void> _fetchPartidosJugados({bool initial = false}) async {
    if (initial && mounted) setState(() => isInitialLoadingJugados = true);
    if (mounted) setState(() => isLoadingMoreJugados = true);

    try {
      final res = await ref.read(apiServiceProvider).getPartidos(page: currentPageJugados, temporada: widget.temporadaId);
      final nuevos = res['items'] ?? [];

      if (currentPageJugados == 1 && nuevos.isNotEmpty) {
        await _guardarCache('jugados', nuevos);
      }

      if (!mounted) return;

      setState(() {
        if (initial) {
          partidosJugados = nuevos;
          currentPageJugados = 2;
        } else {
          final existingIds = partidosJugados.map((p) => p['id']).toSet();
          final sinDuplicados = nuevos.where((p) => !existingIds.contains(p['id'])).toList();
          partidosJugados.addAll(sinDuplicados);
          currentPageJugados++;
        }
        hasMoreJugados = nuevos.length >= 16;
      });
      // Guardar en memoria
      ref.read(partidosCacheProvider).partidosJugados = partidosJugados;

      // Guardar en caché persistente por temporada
      await ref.read(cacheServiceProvider).cachePartidosJugadosPorTemporada(
        widget.temporadaId,
        partidosJugados,
      );
    } catch (e) {
      if (mounted) setState(() => error = e.toString());
    } finally {
      if (mounted) {
        setState(() {
          isLoadingMoreJugados = false;
          isInitialLoadingJugados = false;
        });
      }
    }
  }

  Future<void> _guardarCache(String key, List<dynamic> data) async {
    final prefs = await SharedPreferences.getInstance();
    final payload = {
      'timestamp': DateTime.now().millisecondsSinceEpoch,
      'data': data,
    };
    prefs.setString('cache_partidos_${key}_${widget.temporadaId}', jsonEncode(payload));
  }

  Future<List<dynamic>?> _loadCache(String key) async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString('cache_partidos_${key}_${widget.temporadaId}');
    if (raw != null) {
      final decoded = jsonDecode(raw);
      final timestamp = decoded['timestamp'] as int;
      final now = DateTime.now().millisecondsSinceEpoch;
      if ((now - timestamp) < 3600000) {
        return List<dynamic>.from(decoded['data']);
      }
    }
    return null;
  }


  Widget _buildMatchCard(dynamic partido) {
    final liga = _decodeHtmlEntities(partido['liga']?.toString());
    final local = _decodeHtmlEntities(partido['equipo_local']?.toString());
    final visitante = _decodeHtmlEntities(partido['equipo_visitante']?.toString());
    final escudoLocal = partido['escudo_local']?.toString();
    final escudoVisitante = partido['escudo_visitante']?.toString();
    final fecha = partido['fecha'] ?? '-';
    final hora = partido['hora'] ?? '-';
    final cancha = _decodeHtmlEntities(partido['cancha']?.toString() ?? '-');
    final mesa = (selectedTab != 0) ? (partido['mesa']?.toString() ?? '') : '';

    return Card(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      elevation: 2,
      margin: const EdgeInsets.symmetric(vertical: 6, horizontal: 12),
      child: Padding(
        padding: const EdgeInsets.all(8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(liga, style: const TextStyle(fontWeight: FontWeight.w500, fontSize: 15)),
            const Divider(height: 16, thickness: 1, color: Color(0xFFE0E0E0)),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  flex: 3,
                  child: Column(
                    children: [
                      _teamRow(local, escudoLocal, selectedTab == 0 ? _parseGoles(partido['goles_local']) : ''),
                      const SizedBox(height: 6),
                      _teamRow(visitante, escudoVisitante, selectedTab == 0 ? _parseGoles(partido['goles_visitante']) : ''),
                    ],
                  ),
                ),
                if (selectedTab == 1 || selectedTab == 2)
                  Expanded(
                    flex: 2,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(Icons.calendar_today, size: 16, color: Colors.green),
                            const SizedBox(width: 4),
                            Text(_formatearFecha(fecha), style: const TextStyle(fontSize: 12)),
                          ],
                        ),
                        const SizedBox(height: 3),
                        Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(Icons.access_time, size: 16, color: Colors.green),
                            const SizedBox(width: 4),
                            Text(hora, style: const TextStyle(fontSize: 12)),
                          ],
                        ),
                        const SizedBox(height: 3),
                        Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(Icons.location_on, size: 16, color: Colors.green),
                            const SizedBox(width: 4),
                            Flexible(
                              child: Text(
                                cancha.isNotEmpty ? cancha : '-',
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(fontSize: 12),
                              ),
                            ),
                          ],
                        ),
                        if (mesa.isNotEmpty) ...[
                          const SizedBox(height: 3),
                          Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              const Icon(Icons.groups, size: 16, color: Colors.green),
                              const SizedBox(width: 4),
                              Flexible(
                                child: Text(
                                  'Mesa: $mesa',
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(fontSize: 12),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ],
                    ),
                  ),
                if (selectedTab == 0)
                  TextButton(
                    onPressed: () {
                      Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) => MatchDetailScreen(partido: partido),
                        ),
                      );
                    },
                    child: const Text('Ver detalle'),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _teamRow(String nombre, String? escudoUrl, String goles) {
    return Row(
      children: [
        if (escudoUrl != null && escudoUrl.isNotEmpty && Uri.tryParse(escudoUrl)?.hasScheme == true)
          Image.network(
            escudoUrl,
            width: 24,
            height: 24,
            errorBuilder: (context, error, stackTrace) => const Icon(Icons.shield, size: 20, color: Colors.grey),
          )
        else
          const Icon(Icons.shield, size: 20, color: Colors.grey),
        const SizedBox(width: 8),
        Expanded(child: Text(nombre, style: const TextStyle(fontSize: 16))),
        Text(goles, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: EntreRedesAppBar(
        title: 'Partidos',
        centerTitle: true,
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: 'Actualizar caché de partidos',
            onPressed: _mostrarDialogYActualizarCache,
          ),
        ],
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
                Tab(text: 'Jugados'),
                Tab(text: 'Prox. Fecha'),
                Tab(text: 'Fixture'),
              ],
            ),
          ),
          if (selectedTab == 0) _filtroJugadosSelector(),
          if (selectedTab == 2) _filtroFixtureSelector(),
          Expanded(
            child: TabBarView(
              controller: _tabController,
              children: [
                _buildListaJugadosFiltrada(),
                _buildListaProximosPartidos(),
                _buildFixtureTab(),
              ],
            ),
          ),
        ],
      ),
      bottomNavigationBar: const Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          ZocaloPublicitario(),
        ],
      ),
    );
  }

  Widget _filtroJugadosSelector() {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          _filtroButton('fecha', 'Por Fecha'),
          _filtroButton('zona', 'Por Zona'),
          _filtroButton('equipo', 'Por Equipo'),
        ],
      ),
    );
  }

  Widget _filtroButton(String value, String label) {
    final bool selected = selectedFilterJugados == value;
    return GestureDetector(
      onTap: () {
        setState(() {
          selectedFilterJugados = value;
          if (value != 'equipo') {
            selectedEquipoId = null;
            selectedEquipoNombre = null;
            selectedEquipoEscudo = null;
          }
          if (value != 'zona') {
            selectedZonaNombre = null;
          }
        });
        if (value == 'equipo' && equiposTemporada.isEmpty && !isCargandoEquipos) {
          _cargarEquiposTemporadaActual();
        }
      },
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(
          color: selected ? Theme.of(context).colorScheme.primary : Colors.grey[200],
          borderRadius: BorderRadius.circular(20),
        ),
        child: Text(
          label,
          style: TextStyle(
            color: selected ? Colors.white : Colors.black87,
            fontWeight: FontWeight.w500,
          ),
        ),
      ),
    );
  }
  String _formatearFecha(String fechaOriginal) {
    try {
      final partes = fechaOriginal.split('-');
      if (partes.length == 3) {
        final yyyy = partes[0];
        final mm = partes[1];
        final dd = partes[2];
        final yy = yyyy.substring(2);
        return '$dd-$mm-$yy';
      }
    } catch (_) {}
    return fechaOriginal;
  }

  String _decodeHtmlEntities(String? text) {
    if (text == null || text.isEmpty) return '-';
    return text
        .replaceAll('&amp;', '&')
        .replaceAll('&#8211;', '-')
        .replaceAll('&quot;', '"')
        .replaceAll('&#8217;', "'")
        .replaceAll('&#038;', '&')
        .replaceAll('&#8216;', "'");
  }

  String _parseGoles(dynamic valor) {
    if (valor == null || valor.toString().trim().isEmpty) return '-';
    return valor.toString();
  }

Widget _buildEmptyJugados() {
  return Center(
    child: Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: const [
        Icon(Icons.sports_soccer, size: 64, color: Colors.black26),
        SizedBox(height: 16),
        Text(
          'No se han disputado partidos\nen la temporada actual',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 16, color: Colors.black45),
        ),
      ],
    ),
  );
}

Widget _buildListaPorFecha() {
  if (partidosJugados.isEmpty && !isInitialLoadingJugados) return _buildEmptyJugados();

  final Map<String, List<dynamic>> grupos = {};
  for (var partido in partidosJugados) {
    final fecha = partido['fecha']?.toString() ?? 'Sin fecha';
    grupos.putIfAbsent(fecha, () => []).add(partido);
  }

  final fechasOrdenadas = grupos.keys.toList()
    ..sort((a, b) => b.compareTo(a)); // DESCENDENTE

  final children = fechasOrdenadas.expand((fecha) {
    final partidos = grupos[fecha]!
      ..sort((a, b) {
        final pa = prioridadLiga(a['liga']?.toString());
        final pb = prioridadLiga(b['liga']?.toString());
        return pa.compareTo(pb);
      });

    return [
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 16, 12, 4),
        child: Text(fecha, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
      ),
      ...partidos.map((p) => _buildMatchCard(p)).toList(),
    ];
  }).toList();

  if (isLoadingMoreJugados) {
    children.add(
      const Padding(
        padding: EdgeInsets.symmetric(vertical: 16),
        child: Center(child: Text('Cargando más partidos...')),
      ),
    );
  }

  return ListView(
    controller: _scrollControllerJugados,
    children: children,
  );
}

  Widget _buildListaPorZona() {
    final ligasDisponibles = partidosJugados
        .map((p) => p['liga']?.toString() ?? '')
        .where((l) => l.isNotEmpty)
        .toSet()
        .toList()
      ..sort((a, b) => prioridadLiga(a).compareTo(prioridadLiga(b)));

    final filtrados = selectedZonaNombre == null
        ? partidosJugados
        : partidosJugados.where((p) => p['liga']?.toString() == selectedZonaNombre).toList();

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: DropdownButtonHideUnderline(
            child: Container(
              height: 48,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                border: Border.all(color: Colors.grey.shade300),
                borderRadius: BorderRadius.circular(8),
              ),
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: DropdownButton<String>(
                isExpanded: true,
                isDense: true,
                value: selectedZonaNombre,
                hint: const Text('Seleccionar Zona'),
                items: ligasDisponibles.map<DropdownMenuItem<String>>((liga) {
                  return DropdownMenuItem<String>(
                    value: liga,
                    child: Text(liga),
                  );
                }).toList(),
                onChanged: (String? value) {
                  setState(() => selectedZonaNombre = value);
                },
              ),
            ),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: filtrados.isEmpty && !isInitialLoadingJugados
              ? _buildEmptyJugados()
              : ListView.builder(
                  controller: _scrollControllerJugados,
                  itemCount: filtrados.length + (isLoadingMoreJugados ? 1 : 0),
                  itemBuilder: (context, index) {
                    if (index == filtrados.length) {
                      return const Padding(
                        padding: EdgeInsets.symmetric(vertical: 16),
                        child: Center(child: Text('Cargando más partidos...')),
                      );
                    }
                    return _buildMatchCard(filtrados[index]);
                  },
                ),
        ),
      ],
    );
  }

  Widget _buildListaPorEquipo() {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            children: [
              Container(
                width: 40,
                height: 40,
                margin: const EdgeInsets.only(right: 12),
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: Colors.grey[300],
                  image: selectedEquipoEscudo != null
                      ? DecorationImage(image: NetworkImage(selectedEquipoEscudo!), fit: BoxFit.cover)
                      : null,
                ),
                child: selectedEquipoEscudo == null
                    ? const Icon(Icons.shield, color: Colors.grey)
                    : null,
              ),
              Expanded(
                child: isCargandoEquipos
                    ? const Center(child: CircularProgressIndicator())
                    : DropdownButtonHideUnderline(
                        child: Container(
                          height: 48,
                          alignment: Alignment.center,
                          child: DropdownButton<int>(
                            isExpanded: true,
                            isDense: true,
                            value: selectedEquipoId,
                            hint: const Text('Seleccionar equipo'),
                            selectedItemBuilder: (context) {
                              return equiposTemporada.map<Widget>((equipo) {
                                final rawName = equipo['nombre'];
                                final name = (rawName is String) ? rawName : '';
                                return Text(name);
                              }).toList();
                            },
                            items: equiposTemporada.map<DropdownMenuItem<int>>((equipo) {
                              final rawLogo = equipo['escudo'] ?? equipo['imagen'];
                              final logo = (rawLogo is String && rawLogo.isNotEmpty) ? rawLogo : null;
                              final rawName = equipo['nombre'];
                              final name = (rawName is String) ? rawName : '';
                              return DropdownMenuItem<int>(
                                value: equipo['id'],
                                child: Row(
                                  children: [
                                    if (logo != null)
                                      Image.network(
                                        logo,
                                        width: 24,
                                        height: 24,
                                        errorBuilder: (_, __, ___) => const Icon(Icons.shield, size: 20),
                                      )
                                    else
                                      const Icon(Icons.shield, size: 20),
                                    const SizedBox(width: 8),
                                    Expanded(child: Text(name)),
                                  ],
                                ),
                              );
                            }).toList(),
                            onChanged: (int? newId) async {
                              final equipo = equiposTemporada.firstWhere((e) => e['id'] == newId);
                              setState(() {
                                selectedEquipoId = newId;
                                final rawName = equipo['nombre'];
                                selectedEquipoNombre = (rawName is String) ? rawName : '';
                                final rawEscudo = equipo['escudo'];
                                final rawImagen = equipo['imagen'];
                                selectedEquipoEscudo = (rawEscudo is String && rawEscudo.isNotEmpty)
                                    ? rawEscudo
                                    : (rawImagen is String && rawImagen.isNotEmpty)
                                        ? rawImagen
                                        : null;
                                isLoadingPorEquipo = true;
                                partidosPorEquipo = [];
                              });
                              try {
                                final partidos = await ref.read(apiServiceProvider).getHistorialDePartidosPorEquipo(selectedEquipoNombre ?? '');
                                setState(() {
                                  partidosPorEquipo = partidos;
                                  isLoadingPorEquipo = false;
                                });
                              } catch (e) {
                                setState(() {
                                  partidosPorEquipo = [];
                                  isLoadingPorEquipo = false;
                                });
                              }
                            },
                          ),
                        ),
                      ),
              ),
            ],
          ),
        ),
        const Divider(),
        if (selectedEquipoId == null)
          const Padding(
            padding: EdgeInsets.all(16),
            child: Text(
              'Seleccioná un equipo para ver sus partidos',
              style: TextStyle(color: Colors.grey),
            ),
          )
        else if (isLoadingPorEquipo)
          const Expanded(
            child: Center(child: CircularProgressIndicator()),
          )
        else
          Expanded(
            child: Builder(
              builder: (context) {
                final partidosFiltrados = partidosPorEquipo
                  ..sort((a, b) => b['fecha'].compareTo(a['fecha']));
                return ListView(
                  controller: _scrollControllerJugados,
                  children: partidosFiltrados.map<Widget>((p) => _buildMatchCard(p)).toList(),
                );
              },
            ),
          ),
      ],
    );
  }

  Future<void> _cargarEquiposTemporadaActual() async {
    setState(() => isCargandoEquipos = true);
    try {
      final cached = await _cargarCacheEquipos();
      if (cached != null) {
        final listas = await ref.read(remoteDataServiceProvider).fetchListasJugadores();
        final idsAExcluir = [
          ...?listas['espera'],
          ...?listas['reserva'],
          ...?listas['no_inscriptos'],
        ];

        final filtrados = cached
            .where((e) => !idsAExcluir.contains(e['id']))
            .toList()
          ..sort((a, b) => (a['nombre'] ?? '').toString().toLowerCase().compareTo((b['nombre'] ?? '').toString().toLowerCase()));

        setState(() {
          equiposTemporada = filtrados;
          isCargandoEquipos = false;
        });
        return;
      }

      final listas = await ref.read(remoteDataServiceProvider).fetchListasJugadores();
      final idsAExcluir = [
        ...?listas['espera'],
        ...?listas['reserva'],
        ...?listas['no_inscriptos'],
      ];

      final res = await ref.read(apiServiceProvider).getEquipos(temporada: widget.temporadaId);
      final filtrados = res
          .where((e) => !idsAExcluir.contains(e['id']))
          .toList()
        ..sort((a, b) => (a['nombre'] ?? '').toString().toLowerCase().compareTo((b['nombre'] ?? '').toString().toLowerCase()));

      setState(() {
        equiposTemporada = filtrados;
      });
      await _guardarCacheEquipos(filtrados);
    } catch (e) {
      debugPrint('Error cargando equipos: $e');
    } finally {
      setState(() => isCargandoEquipos = false);
    }
  }

  Future<void> _guardarCacheEquipos(List<dynamic> data) async {
    final prefs = await SharedPreferences.getInstance();
    final payload = {
      'timestamp': DateTime.now().millisecondsSinceEpoch,
      'data': data,
    };
    prefs.setString('cache_equipos_${widget.temporadaId}', jsonEncode(payload));
  }

  Future<List<dynamic>?> _cargarCacheEquipos() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString('cache_equipos_${widget.temporadaId}');
    if (raw != null) {
      final decoded = jsonDecode(raw);
      final timestamp = decoded['timestamp'] as int;
      final now = DateTime.now().millisecondsSinceEpoch;
      const sieteDiasEnMs = 7 * 24 * 60 * 60 * 1000;
      if ((now - timestamp) < sieteDiasEnMs) {
        return List<dynamic>.from(decoded['data']);
      }
    }
    return null;
  }

  Future<void> _mostrarDialogYActualizarCache() async {
    showDialog(
      context: context,
      barrierDismissible: false, // 🔒 impide tocar fuera del modal
      builder: (BuildContext context) {
        return AlertDialog(
          content: Row(
            children: const [
              CircularProgressIndicator(),
              SizedBox(width: 20),
              Expanded(child: Text('Actualizando cache de partidos...')),
            ],
          ),
        );
      },
    );

    await _forzarActualizacionCacheJugados(); // Llama a la función original

    if (mounted) {
      Navigator.of(context).pop(); // Cierra el modal
    }
  }

  // ─── FIXTURE ──────────────────────────────────────────────────────────────

  Future<void> _loadFixture() async {
    if (isLoadingFixture || !mounted) return;
    setState(() => isLoadingFixture = true);
    try {
      final List<dynamic> todos = [];
      int page = 1;
      bool hasMore = true;
      while (hasMore) {
        final res = await ref.read(apiServiceProvider).getPartidosProgramados(page: page, perPage: 16);
        final items = List<dynamic>.from(res['items'] ?? []);
        todos.addAll(items);
        hasMore = items.length >= 16;
        page++;
      }

      todos.sort((a, b) {
        final fa = DateTime.tryParse('${a['fecha'] ?? ''} ${a['hora'] ?? '00:00'}') ?? DateTime(2100);
        final fb = DateTime.tryParse('${b['fecha'] ?? ''} ${b['hora'] ?? '00:00'}') ?? DateTime(2100);
        return fa.compareTo(fb);
      });

      final Map<String, String?> escudos = {};
      for (final p in todos) {
        final local = _decodeHtmlEntities(p['equipo_local']?.toString());
        final visitante = _decodeHtmlEntities(p['equipo_visitante']?.toString());
        final eLocal = p['escudo_local']?.toString();
        final eVisitante = p['escudo_visitante']?.toString();
        if (local != '-') escudos.putIfAbsent(local, () => (eLocal?.isNotEmpty == true) ? eLocal : null);
        if (visitante != '-') escudos.putIfAbsent(visitante, () => (eVisitante?.isNotEmpty == true) ? eVisitante : null);
      }

      if (!mounted) return;
      setState(() {
        todosLosPartidosProgramados = todos;
        _equiposEscudos = escudos;
        isLoadingFixture = false;
        _fixtureLoaded = true;
      });
    } catch (e) {
      if (mounted) setState(() => isLoadingFixture = false);
    }
  }

  Widget _buildFixtureTab() {
    if (isLoadingFixture) return const Center(child: CircularProgressIndicator());
    if (!_fixtureLoaded) return const SizedBox.shrink();
    if (todosLosPartidosProgramados.isEmpty) return _buildEmptyFixture();

    if (selectedFixtureFilter == 'fecha') return _buildFixturePorFecha();
    if (selectedFixtureFilter == 'zona') return _buildFixturePorZona();
    if (selectedFixtureFilter == 'equipo') return _buildFixturePorEquipo();
    return const SizedBox.shrink();
  }

  Widget _filtroFixtureSelector() {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          _filtroFixtureButton('fecha', 'Por Fecha'),
          _filtroFixtureButton('zona', 'Por Zona'),
          _filtroFixtureButton('equipo', 'Por Equipo'),
        ],
      ),
    );
  }

  Widget _filtroFixtureButton(String value, String label) {
    final bool selected = selectedFixtureFilter == value;
    return GestureDetector(
      onTap: () => setState(() {
        selectedFixtureFilter = value;
        selectedFixtureFecha = null;
        selectedFixtureZona = null;
        selectedFixtureEquipo = null;
      }),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(
          color: selected ? Theme.of(context).colorScheme.primary : Colors.grey[200],
          borderRadius: BorderRadius.circular(20),
        ),
        child: Text(
          label,
          style: TextStyle(
            color: selected ? Colors.white : Colors.black87,
            fontWeight: FontWeight.w500,
          ),
        ),
      ),
    );
  }

  Widget _buildFixturePorFecha() {
    final fechasUnicas = todosLosPartidosProgramados
        .map((p) => p['fecha']?.toString() ?? '')
        .where((f) => f.isNotEmpty)
        .toSet()
        .toList()
      ..sort();

    final filtrados = selectedFixtureFecha == null
        ? todosLosPartidosProgramados
        : todosLosPartidosProgramados
            .where((p) => p['fecha']?.toString() == selectedFixtureFecha)
            .toList();

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: DropdownButtonHideUnderline(
            child: Container(
              height: 48,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                border: Border.all(color: Colors.grey.shade300),
                borderRadius: BorderRadius.circular(8),
              ),
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: DropdownButton<String?>(
                isExpanded: true,
                isDense: true,
                value: selectedFixtureFecha,
                hint: const Text('Todas las fechas'),
                items: [
                  const DropdownMenuItem<String?>(value: null, child: Text('Todas las fechas')),
                  ...fechasUnicas.map((f) => DropdownMenuItem<String?>(
                        value: f,
                        child: Text(_formatearFechaLarga(f)),
                      )),
                ],
                onChanged: (v) => setState(() => selectedFixtureFecha = v),
              ),
            ),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: filtrados.isEmpty
              ? _buildEmptyFixture()
              : _buildFixtureGroupedList(filtrados),
        ),
      ],
    );
  }

  Widget _buildFixturePorZona() {
    final zonasUnicas = todosLosPartidosProgramados
        .map((p) => p['liga']?.toString() ?? '')
        .where((l) => l.isNotEmpty)
        .toSet()
        .toList()
      ..sort((a, b) => prioridadLiga(a).compareTo(prioridadLiga(b)));

    final filtrados = selectedFixtureZona == null
        ? todosLosPartidosProgramados
        : todosLosPartidosProgramados
            .where((p) => p['liga']?.toString() == selectedFixtureZona)
            .toList();

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: DropdownButtonHideUnderline(
            child: Container(
              height: 48,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                border: Border.all(color: Colors.grey.shade300),
                borderRadius: BorderRadius.circular(8),
              ),
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: DropdownButton<String?>(
                isExpanded: true,
                isDense: true,
                value: selectedFixtureZona,
                hint: const Text('Todas las zonas'),
                items: [
                  const DropdownMenuItem<String?>(value: null, child: Text('Todas las zonas')),
                  ...zonasUnicas.map((z) => DropdownMenuItem<String?>(
                        value: z,
                        child: Text(z),
                      )),
                ],
                onChanged: (v) => setState(() => selectedFixtureZona = v),
              ),
            ),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: filtrados.isEmpty
              ? _buildEmptyFixture()
              : _buildFixtureGroupedList(filtrados),
        ),
      ],
    );
  }

  Widget _buildFixturePorEquipo() {
    final equiposOrdenados = _equiposEscudos.keys.toList()
      ..sort((a, b) => a.toLowerCase().compareTo(b.toLowerCase()));

    final filtrados = selectedFixtureEquipo == null
        ? todosLosPartidosProgramados
        : todosLosPartidosProgramados.where((p) {
            final local = _decodeHtmlEntities(p['equipo_local']?.toString());
            final visitante = _decodeHtmlEntities(p['equipo_visitante']?.toString());
            return local == selectedFixtureEquipo || visitante == selectedFixtureEquipo;
          }).toList();

    final escudoSeleccionado = selectedFixtureEquipo != null
        ? _equiposEscudos[selectedFixtureEquipo]
        : null;

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            children: [
              Container(
                width: 40,
                height: 40,
                margin: const EdgeInsets.only(right: 12),
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: Colors.grey[300],
                  image: escudoSeleccionado != null
                      ? DecorationImage(image: NetworkImage(escudoSeleccionado), fit: BoxFit.cover)
                      : null,
                ),
                child: escudoSeleccionado == null
                    ? const Icon(Icons.shield, color: Colors.grey)
                    : null,
              ),
              Expanded(
                child: DropdownButtonHideUnderline(
                  child: Container(
                    height: 48,
                    alignment: Alignment.center,
                    child: DropdownButton<String?>(
                      isExpanded: true,
                      isDense: true,
                      value: selectedFixtureEquipo,
                      hint: const Text('Todos los equipos'),
                      selectedItemBuilder: (context) => [
                        const Text('Todos los equipos'),
                        ...equiposOrdenados.map((e) => Text(e)),
                      ],
                      items: [
                        const DropdownMenuItem<String?>(value: null, child: Text('Todos los equipos')),
                        ...equiposOrdenados.map((nombre) {
                          final escudo = _equiposEscudos[nombre];
                          return DropdownMenuItem<String?>(
                            value: nombre,
                            child: Row(
                              children: [
                                if (escudo != null && escudo.isNotEmpty)
                                  Image.network(escudo, width: 24, height: 24,
                                      errorBuilder: (_, __, ___) => const Icon(Icons.shield, size: 20))
                                else
                                  const Icon(Icons.shield, size: 20),
                                const SizedBox(width: 8),
                                Expanded(child: Text(nombre)),
                              ],
                            ),
                          );
                        }),
                      ],
                      onChanged: (v) => setState(() => selectedFixtureEquipo = v),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: filtrados.isEmpty
              ? _buildEmptyFixture()
              : _buildFixtureGroupedList(filtrados),
        ),
      ],
    );
  }

  Widget _buildFixtureGroupedList(List<dynamic> partidos) {
    final Map<String, List<dynamic>> grupos = {};
    for (final p in partidos) {
      final fecha = p['fecha']?.toString() ?? '';
      grupos.putIfAbsent(fecha, () => []).add(p);
    }
    final fechasOrdenadas = grupos.keys.toList()..sort();

    final children = fechasOrdenadas.expand((fecha) {
      return <Widget>[
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 16, 12, 4),
          child: Text(
            _formatearFechaLarga(fecha),
            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
          ),
        ),
        ...grupos[fecha]!.map((p) => _buildMatchCard(p)),
      ];
    }).toList();

    return ListView(children: children);
  }

  Widget _buildEmptyFixture() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: const [
            Icon(Icons.event_busy, size: 64, color: Colors.black26),
            SizedBox(height: 16),
            Text(
              'No hay partidos programados para mostrar',
              style: TextStyle(fontSize: 16, color: Colors.black54),
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }

  String _formatearFechaLarga(String fechaRaw) {
    try {
      final partes = fechaRaw.split('-');
      if (partes.length == 3) {
        return '${partes[2]}/${partes[1]}/${partes[0]}';
      }
    } catch (_) {}
    return fechaRaw;
  }

  // ──────────────────────────────────────────────────────────────────────────

  Future<void> _forzarActualizacionCacheJugados() async {
    try {
      final res = await ref.read(apiServiceProvider).getPartidos(page: 1, perPage: 16, temporada: widget.temporadaId);
      final nuevos = res['items'] ?? [];

      if (nuevos.isNotEmpty) {
        await _guardarCache('jugados', nuevos);
        if (mounted) {
          setState(() {
            partidosJugados = nuevos;
            currentPageJugados = 2;
            hasMoreJugados = nuevos.length >= 16;
            selectedZonaNombre = null;
          });
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Caché actualizada correctamente')),
          );
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error al actualizar caché: $e')),
        );
      }
    }
  }
}

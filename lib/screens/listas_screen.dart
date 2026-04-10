import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../services/remote_data_service.dart';
import '../providers/service_providers.dart';
import '../services/player_filter_service.dart';
import '../utils/date_utils.dart';
import 'player_detail_screen.dart';
import '../widgets/entre_redes_app_bar.dart';

class ListasScreen extends ConsumerStatefulWidget {
  const ListasScreen({super.key});

  @override
  ConsumerState<ListasScreen> createState() => _ListasScreenState();
}

class _ListasScreenState extends ConsumerState<ListasScreen> {
  List<int> esperaIds = [];
  List<int> reservaIds = [];

  List<dynamic> jugadoresEspera = [];
  List<dynamic> jugadoresReserva = [];
  List<dynamic> filteredEspera = [];
  List<dynamic> filteredReserva = [];

  bool isLoadingEspera = true;
  bool isLoadingReserva = false;
  bool todosCargadosEspera = false;
  bool todosCargadosReserva = false;
  bool estaCargandoExtra = false;
  bool yaCargoReserva = false;

  String? adImageUrl;

  int pageEspera = 1;
  int pageReserva = 1;
  final int perPage = 20;

  bool hasMoreEspera = true;
  bool hasMoreReserva = true;

  final ScrollController _scrollControllerEspera = ScrollController();
  final ScrollController _scrollControllerReserva = ScrollController();

  final TextEditingController _searchEsperaController = TextEditingController();
  final TextEditingController _searchReservaController = TextEditingController();

  bool showSearch = false;
  String? posicionSeleccionadaEspera;
  String? posicionSeleccionadaReserva;
  List<double> puntajesSeleccionadosEspera = [];
  List<double> puntajesSeleccionadosReserva = [];

  @override
  void initState() {
    super.initState();

    _loadListasIDs();

    _scrollControllerEspera.addListener(_onScrollEspera);
    _scrollControllerReserva.addListener(_onScrollReserva);

    RemoteDataService.fetchAdImages().then((ads) {
      if (!mounted) return;
      setState(() {
        adImageUrl = ads['jugadores'];
      });
    });
  }

  Future<void> _loadListasIDs() async {
    final listas = await RemoteDataService.fetchListasJugadores();
    esperaIds = List<int>.from(listas['espera'] ?? []);
    reservaIds = List<int>.from(listas['reserva'] ?? []);

    if (esperaIds.isNotEmpty) {
      await _loadJugadoresEspera();
    } else {
      setState(() => isLoadingEspera = false);
    }
  }

  Future<void> _loadJugadoresEspera() async {
    List<dynamic> todos = [];
    for (var id in esperaIds) {
      final response = await ref.read(apiServiceProvider).getJugadoresRaw(equipoId: id, page: pageEspera, perPage: perPage);
      final nuevos = response['items'] ?? [];
      todos.addAll(nuevos);
    }

    todos.sort(PlayerFilterService.comparadorPuntaje);

    setState(() {
      jugadoresEspera.addAll(todos);
      filteredEspera = List.from(jugadoresEspera);
      isLoadingEspera = false;
      if (todos.length < perPage) {
        hasMoreEspera = false;
        todosCargadosEspera = true;
      }
    });
  }

  Future<void> _loadJugadoresReserva() async {
    setState(() => isLoadingReserva = true);
    List<dynamic> todos = [];
    for (var id in reservaIds) {
      final response = await ref.read(apiServiceProvider).getJugadoresRaw(equipoId: id, page: pageReserva, perPage: perPage);
      final nuevos = response['items'] ?? [];
      todos.addAll(nuevos);
    }

    todos.sort(PlayerFilterService.comparadorPuntaje);

    setState(() {
      jugadoresReserva.addAll(todos);
      filteredReserva = List.from(jugadoresReserva);
      isLoadingReserva = false;
      if (todos.length < perPage) {
        hasMoreReserva = false;
        todosCargadosReserva = true;
      }
    });
  }

  void _onScrollEspera() {
    if (_scrollControllerEspera.position.pixels >= _scrollControllerEspera.position.maxScrollExtent - 200 &&
        hasMoreEspera && !isLoadingEspera) {
      pageEspera++;
      _loadJugadoresEspera();
    }
  }

  void _onScrollReserva() {
    if (_scrollControllerReserva.position.pixels >= _scrollControllerReserva.position.maxScrollExtent - 200 &&
        hasMoreReserva && !isLoadingReserva) {
      pageReserva++;
      _loadJugadoresReserva();
    }
  }

  void _onTabChanged(int index) {
    if (index == 1 && !yaCargoReserva && reservaIds.isNotEmpty) {
      yaCargoReserva = true;
      _loadJugadoresReserva();
    }
  }

  void _resetFiltros(bool isEspera) {
    setState(() {
      if (isEspera) {
        posicionSeleccionadaEspera = null;
        puntajesSeleccionadosEspera = [];
        filteredEspera = List.from(jugadoresEspera);
        _searchEsperaController.clear();
      } else {
        posicionSeleccionadaReserva = null;
        puntajesSeleccionadosReserva = [];
        filteredReserva = List.from(jugadoresReserva);
        _searchReservaController.clear();
      }
      showSearch = false;
    });
  }

  Future<void> _aplicarFiltros(bool isEspera) async {
    if ((isEspera && !todosCargadosEspera) || (!isEspera && !todosCargadosReserva)) {
      setState(() => estaCargandoExtra = true);
      if (isEspera) {
        while (hasMoreEspera) {
          pageEspera++;
          await _loadJugadoresEspera();
        }
      } else {
        while (hasMoreReserva) {
          pageReserva++;
          await _loadJugadoresReserva();
        }
      }
      setState(() => estaCargandoExtra = false);
    }

    final original = isEspera ? jugadoresEspera : jugadoresReserva;
    final posicion = isEspera ? posicionSeleccionadaEspera : posicionSeleccionadaReserva;
    final puntajes = isEspera ? puntajesSeleccionadosEspera : puntajesSeleccionadosReserva;
    final query = (isEspera ? _searchEsperaController.text : _searchReservaController.text).toLowerCase();

    setState(() {
      final filtrado = PlayerFilterService.filtrar(
        original,
        query: query,
        posicion: posicion,
        puntajes: puntajes,
      );
      if (isEspera) {
        filteredEspera = filtrado;
      } else {
        filteredReserva = filtrado;
      }
    });
  }

  @override
  void dispose() {
    _scrollControllerEspera.dispose();
    _scrollControllerReserva.dispose();
    _searchEsperaController.dispose();
    _searchReservaController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: EntreRedesAppBar(
          title: 'Listas de Jugadores',
          centerTitle: true,
          bottom: PreferredSize(
            preferredSize: const Size.fromHeight(48.0),
            child: Container(
              color: Colors.white,
              child: TabBar(
                onTap: _onTabChanged,
                labelColor: const Color(0xFF005BBB),
                unselectedLabelColor: Colors.grey,
                indicatorColor: const Color(0xFF005BBB),
                indicatorWeight: 3,
                labelStyle: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w600,
                ),
                tabs: const [
                  Tab(text: 'Lista de Espera'),
                  Tab(text: 'Lista de Reserva'),
                ],
              ),
            ),
          ),
        ),
        body: TabBarView(
          children: [
            _buildListaTab(true),
            _buildListaTab(false),
          ],
        ),
      ),
    );
  }

  Widget _buildListaTab(bool isEspera) {
    final controller = isEspera ? _searchEsperaController : _searchReservaController;
    final jugadores = isEspera ? filteredEspera : filteredReserva;
    final cargando = isEspera ? isLoadingEspera : isLoadingReserva;
    final scroll = isEspera ? _scrollControllerEspera : _scrollControllerReserva;
    final posicionSeleccionada = isEspera ? posicionSeleccionadaEspera : posicionSeleccionadaReserva;
    final puntajesSeleccionados = isEspera ? puntajesSeleccionadosEspera : puntajesSeleccionadosReserva;

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            children: [
              IconButton(
                icon: const Icon(Icons.search),
                onPressed: () => setState(() => showSearch = true),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: DropdownButton<String?>(
                  value: posicionSeleccionada,
                  isExpanded: true,
                  hint: const Text('Todas las posiciones'),
                  onChanged: (value) {
                    setState(() {
                      if (isEspera) {
                        posicionSeleccionadaEspera = value;
                      } else {
                        posicionSeleccionadaReserva = value;
                      }
                      _aplicarFiltros(isEspera);
                    });
                  },
                  items: ['Todas', 'Arquero', 'Defensor', 'Mediocampista', 'Delantero']
                      .map((pos) => DropdownMenuItem<String?>(
                            value: pos == 'Todas' ? null : pos,
                            child: Text(pos),
                          ))
                      .toList(),
                ),
              ),
              const SizedBox(width: 8),
              PopupMenuButton<double>(
                icon: const Icon(Icons.filter_alt),
                tooltip: 'Seleccionar puntajes',
                itemBuilder: (context) => [1.0, 1.5, 2.0, 2.5, 3.0, 3.5, 4.0, 4.5, 5.0]
                    .map((p) => CheckedPopupMenuItem<double>(
                          value: p,
                          checked: puntajesSeleccionados.contains(p),
                          child: Text('$p'),
                          onTap: () {
                            setState(() {
                              final lista = isEspera ? puntajesSeleccionadosEspera : puntajesSeleccionadosReserva;
                              if (lista.contains(p)) {
                                lista.remove(p);
                              } else {
                                lista.add(p);
                              }
                              _aplicarFiltros(isEspera);
                            });
                          },
                        ))
                    .toList(),
              ),
            ],
          ),
        ),
        AnimatedSwitcher(
          duration: const Duration(milliseconds: 250),
          child: showSearch
              ? Padding(
                  key: const ValueKey('search'),
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  child: Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: controller,
                          autofocus: true,
                          onChanged: (_) => _aplicarFiltros(isEspera),
                          decoration: InputDecoration(
                            isDense: true,
                            contentPadding: const EdgeInsets.symmetric(vertical: 10, horizontal: 12),
                            hintText: 'Buscar jugador...',
                            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                            filled: true,
                            fillColor: Colors.grey[100],
                          ),
                        ),
                      ),
                      IconButton(
                        icon: const Icon(Icons.close),
                        onPressed: () => _resetFiltros(isEspera),
                      ),
                    ],
                  ),
                )
              : const SizedBox.shrink(),
        ),
        if (estaCargandoExtra)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 12),
            child: Center(child: Text('Cargando más jugadores...')),
          ),
        Expanded(
          child: cargando && jugadores.isEmpty
              ? LoadingSeccionConAd(texto: 'Cargando jugadores...', adImageUrl: adImageUrl)
              : jugadores.isEmpty
                  ? _buildEmptyMessage()
                  : ListView.builder(
                      controller: scroll,
                      itemCount: jugadores.length,
                      itemBuilder: (context, index) => _buildJugadorItem(jugadores[index]),
                    ),
        ),
      ],
    );
  }

  Widget _buildJugadorItem(dynamic j) {
    final nombre = j['title']?['rendered'] ?? 'Sin nombre';
    final edad = calcularEdad(j['fecha_nacimiento']);
    final puntaje = _formatearPuntaje(j['metrics']?['puntaje']);
    final rawPos = (j['posicion'] ?? '').toString();
    final posicion = rawPos.isEmpty || rawPos.toLowerCase() == 'sin posicion'
        ? 'Sin Cargar'
        : rawPos.toLowerCase() == 'mediocampista'
            ? 'Medio.'
            : rawPos;
    final foto = j['featured_image'] is String && j['featured_image'].isNotEmpty
        ? j['featured_image']
        : null;

    Color bgColor;
    switch (posicion.toLowerCase()) {
      case 'arquero':
        bgColor = Colors.cyan.shade700;
        break;
      case 'defensor':
        bgColor = Colors.indigo.shade600;
        break;
      case 'medio.':
        bgColor = Colors.orange.shade600;
        break;
      case 'delantero':
        bgColor = Colors.red.shade600;
        break;
      case 'sin cargar':
        bgColor = Colors.grey.shade600;
        break;
      default:
        bgColor = Colors.grey.shade400;
    }

    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      child: Stack(
        children: [
          Card(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            elevation: 2,
            child: Padding(
              padding: const EdgeInsets.only(left: 12),
              child: ListTile(
                contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                leading: foto != null
                    ? CircleAvatar(backgroundImage: NetworkImage(foto))
                    : const Icon(Icons.person, size: 40),
                title: Text(nombre, style: const TextStyle(fontWeight: FontWeight.w600)),
                subtitle: Text('Edad: $edad', style: const TextStyle(fontSize: 13)),
                trailing: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Text('Pts.', style: TextStyle(fontSize: 11)),
                    Text(puntaje, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                  ],
                ),
                onTap: () {
                  Navigator.push(
                    context,
                    MaterialPageRoute(builder: (_) => PlayerDetailScreen(player: j)),
                  );
                },
              ),
            ),
          ),
          Positioned(
            left: 0,
            top: 10,
            bottom: 10,
            child: FractionallySizedBox(
              heightFactor: 0.8,
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
        ],
      ),
    );
  }

  Widget _buildEmptyMessage() {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: const [
          Icon(Icons.person_outline, size: 60, color: Colors.grey),
          SizedBox(height: 12),
          Text(
            'No se encontraron jugadores en la lista',
            style: TextStyle(fontSize: 16, color: Colors.grey),
          ),
        ],
      ),
    );
  }

  String _formatearPuntaje(dynamic valor) {
    try {
      if (valor == null || valor is bool) return '-';
      if (valor is num) {
        return valor.toStringAsFixed(valor.truncateToDouble() == valor ? 0 : 1);
      }
      if (valor is String) {
        final normalizado = valor.replaceAll(',', '.');
        final numParsed = double.tryParse(normalizado);
        if (numParsed != null) {
          return numParsed.toStringAsFixed(numParsed.truncateToDouble() == numParsed ? 0 : 1);
        }
      }
    } catch (_) {}
    return '-';
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
          ]
        ],
      ),
    );
  }
}
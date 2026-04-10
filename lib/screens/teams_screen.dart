import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'team_detail_screen.dart';
import '../providers/service_providers.dart';
import '../providers/temporadas_provider.dart';
import '../services/remote_data_service.dart';
import '../widgets/entre_redes_app_bar.dart';
import '../widgets/zocalo_publicitario.dart';


class TeamsScreen extends ConsumerStatefulWidget {
  const TeamsScreen({super.key});

  @override
  ConsumerState<TeamsScreen> createState() => _TeamsScreenState();
}

class _TeamsScreenState extends ConsumerState<TeamsScreen> with SingleTickerProviderStateMixin {
  List<dynamic> equiposTemporada = [];
  List<dynamic> equiposHistoricos = [];
  bool isLoading = false;
  String? error;
  String? equiposAdUrl;
  bool initialLoading = true;
  String searchQuery = '';
  late TabController _tabController;
  final TextEditingController _searchController = TextEditingController();
  Timer? _debounce;

  @override
  void initState() {
    super.initState();

    _tabController = TabController(length: 2, vsync: this);

    RemoteDataService.fetchAdImages().then((ads) {
      if (!mounted) return;
      setState(() {
        //equiposAdUrl = ads['equipos'];
      });
    });

    _loadFromCacheThenFetch();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _searchController.dispose();
    _tabController.dispose();
    super.dispose();
  }

  void _onSearchChanged(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 300), () {
      if (mounted) setState(() => searchQuery = value);
    });
  }

  Future<void> _loadFromCacheThenFetch() async {
    if (!mounted) return;
    setState(() {
      isLoading = true;
      initialLoading = true;
    });

    final actuales = await _loadCache('cache_equipos_actuales');
    final historicos = await _loadCache('cache_equipos_historicos');

    if (!mounted) return;
    if (actuales != null) {
      setState(() {
        equiposTemporada = actuales;
      });
    }

    if (historicos != null) {
      equiposHistoricos = historicos;
    }

    if (!mounted) return;
    setState(() {
      isLoading = false;
      initialLoading = false;
    });

    _fetchEquiposTemporadaActual();
  }

  Future<void> _fetchEquiposTemporadaActual() async {
    try {
      final excludedIds = await fetchEquiposExcluidos();
      final temporadaActual = await ref.read(temporadaActualProvider.future);
      final temporadaActualId = temporadaActual.id;
      final all = (await ref.read(apiServiceProvider).getEquipos(temporada: temporadaActualId))
          .where((e) => !excludedIds.contains(e["id"]))
          .toList()
        ..sort((a, b) => (a['nombre'] ?? '').toString().toLowerCase().compareTo((b['nombre'] ?? '').toString().toLowerCase()));

      await _saveCache('cache_equipos_actuales', all);
      if (!mounted) return;
      setState(() {
        equiposTemporada = all;
      });
      _fetchEquiposHistoricos(temporadaActualId);
    } catch (e) {
      if (!mounted) return;
      setState(() => error = e.toString());
    }
  }

  Future<void> _fetchEquiposHistoricos(int temporadaActualId) async {
    try {
      final excludedIds = await fetchEquiposExcluidos();
      final all = (await ref.read(apiServiceProvider).getEquipos())
          .where((e) => !excludedIds.contains(e["id"]))
          .toList();

      final actualesIds = equiposTemporada.map((e) => e['id']).toSet();
      final historicos = all.where((e) {
        final temporadas = List.from(e['temporadas'] ?? []);
        final noEsActual = !temporadas.contains(temporadaActualId);
        final noEsDuplicado = !actualesIds.contains(e['id']);
        return noEsActual && noEsDuplicado;
      }).toList()
        ..sort((a, b) => (a['nombre'] ?? '').toString().toLowerCase().compareTo((b['nombre'] ?? '').toString().toLowerCase()));

      if (!mounted) return;
      setState(() {
        equiposHistoricos = historicos;
      });

      await _saveCache('cache_equipos_historicos', historicos);
    } catch (e) {
      if (!mounted) return;
      setState(() => error = 'Error al cargar equipos históricos: $e');
    }
  }

  Future<void> _saveCache(String key, List<dynamic> data) async {
    final prefs = await SharedPreferences.getInstance();
    final payload = {
      'timestamp': DateTime.now().millisecondsSinceEpoch,
      'data': data,
    };
    await prefs.setString(key, jsonEncode(payload));
  }

  Future<List<dynamic>?> _loadCache(String key) async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(key);
    if (raw != null) {
      final decoded = jsonDecode(raw);
      final timestamp = decoded['timestamp'] as int;
      final now = DateTime.now().millisecondsSinceEpoch;
      final cacheAge = key == 'cache_equipos_historicos' ? 7 * 86400000 : 3600000;
      if ((now - timestamp) < cacheAge) {
        return List<dynamic>.from(decoded['data']);
      }
    }
    return null;
  }

  List<dynamic> _filteredEquipos(List<dynamic> equipos) {
    if (searchQuery.isEmpty) return equipos;
    return equipos.where((e) {
      final nombre = (e['nombre'] ?? '').toString().toLowerCase();
      return nombre.contains(searchQuery.toLowerCase());
    }).toList();
  }

  Widget _buildTeamCard(dynamic team) {
    final nombre = team['nombre'] ?? 'Sin nombre';
    final avatarRaw = team['imagen'];
    final avatar = (avatarRaw is String && avatarRaw.isNotEmpty) ? avatarRaw : null;

    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      elevation: 2,
      child: ListTile(
        leading: CircleAvatar(
          backgroundImage: avatar != null ? NetworkImage(avatar) : null,
          backgroundColor: Colors.grey[300],
          child: avatar == null ? const Icon(Icons.shield) : null,
        ),
        title: Text(nombre, overflow: TextOverflow.ellipsis),
        onTap: () {
          Navigator.push(
            context,
            MaterialPageRoute(builder: (_) => TeamDetailScreen(team: team)),
          );
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: EntreRedesAppBar(title: 'Equipos', centerTitle: true),
        body: Column(
          children: [
            TabBar(
              controller: _tabController,
              labelColor: Theme.of(context).primaryColor,
              unselectedLabelColor: Colors.grey,
              indicatorColor: Theme.of(context).primaryColor,
              tabs: const [
                Tab(text: 'Temporada Actual'),
                Tab(text: 'Histórico'),
              ],
            ),
            Expanded(
              child: TabBarView(
                controller: _tabController,
                children: [
                  // Temporada Actual
                  error != null
                      ? Center(child: Text('Error: $error'))
                      : (initialLoading && equiposTemporada.isEmpty)
                          ? LoadingSeccionConAd(
                              texto: 'Cargando equipos...',
                              adImageUrl: equiposAdUrl,
                            )
                          : ListView.builder(
                              padding: const EdgeInsets.all(8),
                              itemCount: equiposTemporada.length,
                              itemBuilder: (context, index) {
                                return _buildTeamCard(equiposTemporada[index]);
                              },
                            ),

                  // Histórico
                  Column(
                    children: [
                      Padding(
                        padding: const EdgeInsets.all(8.0),
                        child: TextField(
                          controller: _searchController,
                          decoration: const InputDecoration(
                            labelText: 'Buscar equipo',
                            border: OutlineInputBorder(),
                            prefixIcon: Icon(Icons.search),
                          ),
                          onChanged: _onSearchChanged,
                        ),
                      ),
                      Expanded(
                        child: ListView.builder(
                          padding: const EdgeInsets.all(8),
                          itemCount: _filteredEquipos(equiposHistoricos).length,
                          itemBuilder: (context, index) {
                            final filtered = _filteredEquipos(equiposHistoricos);
                            return _buildTeamCard(filtered[index]);
                          },
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
        bottomNavigationBar: const ZocaloPublicitario(), // ✅ Aquí se inserta el zócalo
      ),
    );
  }

  Future<List<int>> fetchEquiposExcluidos() async {
    final listas = await RemoteDataService.fetchListasJugadores();
    return [
      ...(listas['reserva'] ?? []),
      ...(listas['espera'] ?? []),
      ...(listas['no_inscriptos'] ?? []),
    ];
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
          ]
        ],
      ),
    );
  }
}
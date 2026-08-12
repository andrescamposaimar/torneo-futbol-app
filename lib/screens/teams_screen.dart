import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'team_detail_screen.dart';
import '../config/tenant_provider.dart';
import '../providers/service_providers.dart';
import '../providers/temporadas_provider.dart';
import '../utils/text_utils.dart';
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

    ref.read(remoteDataServiceProvider).fetchAdImages().then((ads) {
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

  /// A team tile: the crest is the content, the name labels it.
  ///
  /// The crest sits on a tinted disc so logos of wildly different shapes,
  /// aspect ratios and background colours all read as the same kind of object.
  Widget _buildTeamCard(dynamic team) {
    final primary = Theme.of(context).colorScheme.primary;
    final nombre = decodeHtmlEntities(team['nombre']?.toString()).isEmpty
        ? 'Sin nombre'
        : decodeHtmlEntities(team['nombre']?.toString());
    final avatarRaw = team['imagen'];
    final avatar = (avatarRaw is String && avatarRaw.isNotEmpty) ? avatarRaw : null;

    // Sized to the larger disc so a team without a crest does not look empty.
    final placeholder =
        Icon(Icons.shield_outlined, size: 46, color: Colors.grey.shade400);

    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: Colors.grey.shade200),
      ),
      clipBehavior: Clip.antiAlias,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: () => Navigator.push(
            context,
            MaterialPageRoute(builder: (_) => TeamDetailScreen(team: team)),
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 12),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                // The disc takes the slack the tile used to waste as padding:
                // it expands to whatever height is left once the name is laid
                // out, and stays a circle by matching its own width.
                Expanded(
                  child: AspectRatio(
                    aspectRatio: 1,
                    child: Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: primary.withValues(alpha: 0.05),
                        shape: BoxShape.circle,
                      ),
                      child: avatar != null
                          ? Image.network(
                              avatar,
                              fit: BoxFit.contain,
                              errorBuilder: (_, __, ___) => placeholder,
                            )
                          : placeholder,
                    ),
                  ),
                ),
                const SizedBox(height: 10),
                // Fixed height for two lines whether the name needs them or
                // not: otherwise a one-word team leaves its disc larger than
                // its neighbour's, and the grid reads as a mistake.
                SizedBox(
                  height: 34,
                  child: Align(
                    alignment: Alignment.topCenter,
                    child: Text(
                      nombre,
                      textAlign: TextAlign.center,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 13,
                        height: 1.25,
                        fontWeight: FontWeight.w700,
                        color: Colors.black87,
                        letterSpacing: 0.2,
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// Tabs drawn as a pill segmented control rather than the default underline,
  /// matching the team selector used on the lineup pitch.
  Widget _buildTabSelector() {
    final primary = Theme.of(context).colorScheme.primary;

    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
      child: Container(
        padding: const EdgeInsets.all(3),
        decoration: BoxDecoration(
          color: Colors.grey.shade200,
          borderRadius: BorderRadius.circular(24),
        ),
        child: TabBar(
          controller: _tabController,
          indicator: BoxDecoration(
            color: primary,
            borderRadius: BorderRadius.circular(24),
          ),
          indicatorSize: TabBarIndicatorSize.tab,
          dividerColor: Colors.transparent,
          splashBorderRadius: BorderRadius.circular(24),
          labelColor: Colors.white,
          unselectedLabelColor: Colors.black54,
          labelStyle: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
          unselectedLabelStyle:
              const TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
          tabs: const [
            Tab(text: 'Temporada Actual'),
            Tab(text: 'Histórico'),
          ],
        ),
      ),
    );
  }

  Widget _buildBuscador() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
      child: TextField(
        controller: _searchController,
        onChanged: _onSearchChanged,
        style: const TextStyle(fontSize: 15),
        decoration: InputDecoration(
          hintText: 'Buscar equipo',
          hintStyle: TextStyle(color: Colors.grey.shade500, fontSize: 15),
          prefixIcon: Icon(Icons.search, color: Colors.grey.shade500, size: 21),
          filled: true,
          fillColor: Colors.grey.shade100,
          isDense: true,
          contentPadding: const EdgeInsets.symmetric(vertical: 14),
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: BorderSide.none,
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: BorderSide(color: Colors.grey.shade200),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: BorderSide(
              color: Theme.of(context).colorScheme.primary.withValues(alpha: 0.5),
            ),
          ),
        ),
      ),
    );
  }

  /// The grid every tab renders into.
  Widget _buildTeamsGrid(List<dynamic> equipos) {
    if (equipos.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.groups_outlined, size: 56, color: Colors.grey.shade400),
              const SizedBox(height: 16),
              Text(
                'No se encontraron equipos',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 16, color: Colors.grey.shade600),
              ),
            ],
          ),
        ),
      );
    }

    return GridView.builder(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 20),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        crossAxisSpacing: 12,
        mainAxisSpacing: 12,
        childAspectRatio: 0.92,
      ),
      itemCount: equipos.length,
      itemBuilder: (context, index) => _buildTeamCard(equipos[index]),
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
            _buildTabSelector(),
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
                          : _buildTeamsGrid(equiposTemporada),

                  // Histórico
                  Column(
                    children: [
                      _buildBuscador(),
                      Expanded(
                        child: _buildTeamsGrid(_filteredEquipos(equiposHistoricos)),
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
    if (!ref.read(tenantConfigProvider).features.waitingLists) return [];
    final listas = await ref.read(remoteDataServiceProvider).fetchListasJugadores();
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
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../services/remote_data_service.dart';
import '../providers/repository_providers.dart';
import '../providers/temporadas_provider.dart';
import '../models/temporada.dart';
import '../widgets/entre_redes_app_bar.dart';

class ScorersScreen extends ConsumerStatefulWidget {
  const ScorersScreen({super.key});

  @override
  ConsumerState<ScorersScreen> createState() => _ScorersScreenState();
}

class _ScorersScreenState extends ConsumerState<ScorersScreen> {
  List<dynamic> goleadores = [];
  List<Temporada> temporadas = [];

  int? temporadaSeleccionadaId;
  bool isLoading = false;
  bool hasMore = true;
  int currentPage = 1;
  final int perPage = 10;
  String? adImageUrl;

  final ScrollController _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    _scrollController.addListener(_onScroll);
    _loadTemporadas();
    RemoteDataService.fetchAdImages().then((ads) {
      if (!mounted) return;
      setState(() {
        //adImageUrl = ads['goleadores'];
      });
    });
  }

  @override
  void dispose() {
    _scrollController.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scrollController.position.pixels >= _scrollController.position.maxScrollExtent - 200 &&
        !isLoading && hasMore) {
      _loadMasGoleadores();
    }
  }

  Future<void> _loadTemporadas() async {
    try {
      final data = await ref.read(temporadasProvider.future);
      final parsed = data.map<Temporada>((t) => Temporada.fromJson(t)).toList();
      final temporadaActual = parsed.firstWhere((t) => t.isCurrent, orElse: () => parsed[0]);
      setState(() {
        temporadas = parsed;
        temporadaSeleccionadaId = temporadaActual.id;
        goleadores.clear();
        currentPage = 1;
        hasMore = true;
      });
      await _loadMasGoleadores();
    } catch (e) {
      debugPrint('Error al cargar temporadas: $e');
    }
  }

  Future<void> _loadMasGoleadores() async {
    if (!hasMore || isLoading) return;
    setState(() => isLoading = true);

    try {
      final data = await ref.read(apiRepositoryProvider).getScorersPage(
        temporadaId: temporadaSeleccionadaId,
        page: currentPage,
        perPage: perPage,
      );

      final nuevos = data['items'] as List<dynamic>;
      setState(() {
        goleadores.addAll(nuevos);
        currentPage++;
        hasMore = currentPage <= (data['total_pages'] ?? 1);
      });
    } catch (e) {
      debugPrint('Error al cargar goleadores: $e');
    } finally {
      setState(() => isLoading = false);
    }
  }

  Widget _buildTemporadaFilter() {
    final sortedTemporadas = List<Temporada>.from(temporadas)
      ..sort((a, b) => b.name.compareTo(a.name));

    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 8),
      child: Row(
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 4),
            child: ElevatedButton(
              onPressed: () {
                setState(() {
                  temporadaSeleccionadaId = null;
                  goleadores.clear();
                  currentPage = 1;
                  hasMore = true;
                });
                _loadMasGoleadores();
              },
              style: ElevatedButton.styleFrom(
                backgroundColor: temporadaSeleccionadaId == null
                    ? const Color(0xFF00A3FF)
                    : Colors.grey[200],
                foregroundColor: temporadaSeleccionadaId == null ? Colors.white : Colors.black87,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
              ),
              child: const Text('Histórico'),
            ),
          ),
          ...sortedTemporadas.map((t) {
            final isSelected = t.id == temporadaSeleccionadaId;
            return Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4),
              child: ElevatedButton(
                onPressed: () {
                  setState(() {
                    temporadaSeleccionadaId = t.id;
                    goleadores.clear();
                    currentPage = 1;
                    hasMore = true;
                  });
                  _loadMasGoleadores();
                },
                style: ElevatedButton.styleFrom(
                  backgroundColor: isSelected ? const Color(0xFF00A3FF) : Colors.grey[200],
                  foregroundColor: isSelected ? Colors.white : Colors.black87,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                ),
                child: Text(t.name.isEmpty ? 'Temporada' : t.name),
              ),
            );
          }).toList(),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: EntreRedesAppBar(title: 'Tabla de Goleadores'),
      body: Column(
        children: [
          const SizedBox(height: 8),
          _buildTemporadaFilter(),
          const SizedBox(height: 8),
          Expanded(
            child: isLoading && goleadores.isEmpty
                ? LoadingSeccionConAd(
                    texto: 'Cargando goleadores...',
                    adImageUrl: adImageUrl,
                  )
                : goleadores.isEmpty
                    ? const Center(
                        child: Padding(
                          padding: EdgeInsets.all(16),
                          child: Text(
                            'No hay goleadores en esta temporada.',
                            style: TextStyle(fontSize: 16),
                            textAlign: TextAlign.center,
                          ),
                        ),
                      )
                    : ListView.builder(
                        controller: _scrollController,
                        itemCount: goleadores.length + (hasMore ? 1 : 0),
                        itemBuilder: (context, index) {
                          if (index < goleadores.length) {
                            final g = goleadores[index];
                            final bool esPrimero = index == 0;

                            return Container(
                              decoration: esPrimero
                                  ? BoxDecoration(
                                      color: const Color(0xFFE8F5E9),
                                      border: Border.all(color: Color(0xFF388E3C), width: 1.5),
                                      borderRadius: BorderRadius.circular(8),
                                    )
                                  : null,
                              margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                              child: Row(
                                children: [
                                  (g['foto'] != null && g['foto'].toString().isNotEmpty)
                                      ? CircleAvatar(
                                          backgroundImage: NetworkImage(g['foto']),
                                          radius: 28,
                                        )
                                      : const CircleAvatar(
                                          radius: 28,
                                          child: Icon(Icons.person),
                                        ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          g['nombre'] ?? 'Sin nombre',
                                          style: const TextStyle(
                                            fontSize: 16,
                                            fontWeight: FontWeight.bold,
                                          ),
                                        ),
                                        Text(
                                          g['equipo'] ?? 'Sin equipo',
                                          style: const TextStyle(
                                            fontSize: 14,
                                            color: Colors.grey,
                                          ),
                                        ),
                                        if (esPrimero)
                                          const Padding(
                                            padding: EdgeInsets.only(top: 4),
                                            child: Text(
                                              '👟 Goleador del Torneo',
                                              style: TextStyle(
                                                color: Color(0xFF388E3C),
                                                fontWeight: FontWeight.w600,
                                                fontSize: 13,
                                              ),
                                            ),
                                          ),
                                      ],
                                    ),
                                  ),
                                  Row(
                                    children: [
                                      Text(
                                        '${g['goles'] ?? 0}',
                                        style: const TextStyle(
                                          fontSize: 17,
                                          fontWeight: FontWeight.bold,
                                        ),
                                      ),
                                      const SizedBox(width: 4),
                                      const Icon(Icons.sports_soccer, size: 18),
                                    ],
                                  ),
                                ],
                              ),
                            );
                          } else {
                            return const Padding(
                              padding: EdgeInsets.symmetric(vertical: 16),
                              child: Center(child: CircularProgressIndicator()),
                            );
                          }
                        },
                      ),
          )
        ],
      ),
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
          ]
        ],
      ),
    );
  }
}
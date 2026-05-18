import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../providers/service_providers.dart';
import '../providers/repository_providers.dart';
import '../providers/temporadas_provider.dart';
import '../models/temporada.dart';
import '../widgets/entre_redes_app_bar.dart';

class ImbatiblesScreen extends ConsumerStatefulWidget {
  const ImbatiblesScreen({super.key});

  @override
  ConsumerState<ImbatiblesScreen> createState() => _ImbatiblesScreenState();
}

class _ImbatiblesScreenState extends ConsumerState<ImbatiblesScreen> {
  List<dynamic> arqueros = [];
  // List<dynamic> temporadas = []; // Ya no se usa
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
    ref.read(remoteDataServiceProvider).fetchAdImages().then((ads) {
      if (!mounted) return;
      setState(() {
        adImageUrl = ads['imbatibles'];
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
      _loadMasArqueros();
    }
  }

  Future<void> _loadTemporadas() async {
    try {
      final data = await ref.read(temporadasProvider.future);
      final temporadas = data.map<Temporada>((t) => Temporada.fromJson(t)).toList();
      final temporadaActual = temporadas.firstWhere((t) => t.isCurrent, orElse: () => temporadas[0]);

      if (mounted) {
        setState(() {
          temporadaSeleccionadaId = temporadaActual.id;
          arqueros.clear();
          currentPage = 1;
          hasMore = true;
        });
        await _loadMasArqueros();
      }
    } catch (e) {
      debugPrint('Error al cargar temporadas: $e');
    }
  }

  Future<void> _loadMasArqueros() async {
    if (!hasMore || isLoading || temporadaSeleccionadaId == null) return;
    setState(() => isLoading = true);

    try {
      if (currentPage == 1) {
        // Repository handles: check cache → if miss, fetch all → save cache
        final all = await ref.read(apiRepositoryProvider).getImbatibles(temporadaSeleccionadaId!);
        setState(() {
          arqueros = List<dynamic>.from(all.take(perPage));
          currentPage = 2;
          hasMore = all.length > perPage;
        });
      } else {
        final data = await ref.read(apiRepositoryProvider).getImbatiblesPage(
          temporadaId: temporadaSeleccionadaId!,
          page: currentPage,
          perPage: perPage,
        );
        final nuevos = data['items'] as List<dynamic>;
        setState(() {
          arqueros.addAll(nuevos);
          currentPage++;
          hasMore = currentPage <= (data['total_pages'] ?? 1);
        });
      }
    } catch (e) {
      debugPrint('Error al cargar arqueros: $e');
    } finally {
      setState(() => isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: EntreRedesAppBar(title: 'Tabla de Imbatibles'),
      body: Column(
        children: [
          const SizedBox(height: 8),
          // _buildTemporadaFilter eliminado
          const SizedBox(height: 8),
          Expanded(
            child: isLoading && arqueros.isEmpty
                ? LoadingSeccionConAd(
                    texto: 'Cargando arqueros...',
                    adImageUrl: adImageUrl,
                  )
                : arqueros.isEmpty
                    ? const Center(
                        child: Padding(
                          padding: EdgeInsets.all(16),
                          child: Text(
                            'No hay arqueros en esta temporada.',
                            style: TextStyle(fontSize: 16),
                            textAlign: TextAlign.center,
                          ),
                        ),
                      )
                    : ListView.builder(
                        controller: _scrollController,
                        itemCount: arqueros.length + (hasMore ? 1 : 0),
                        itemBuilder: (context, index) {
                          if (index < arqueros.length) {
                            final a = arqueros[index];
                            return Container(
                              margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                              decoration: BoxDecoration(
                                borderRadius: BorderRadius.circular(8),
                                color: Colors.blueGrey[50],
                              ),
                              child: Row(
                                children: [
                                  (a['foto'] != null && a['foto'].toString().isNotEmpty)
                                      ? CircleAvatar(
                                          backgroundImage: NetworkImage(a['foto']),
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
                                          a['nombre'] ?? 'Sin nombre',
                                          style: const TextStyle(
                                            fontSize: 16,
                                            fontWeight: FontWeight.bold,
                                          ),
                                        ),
                                        Text(
                                          a['equipo'] ?? 'Sin equipo',
                                          style: const TextStyle(
                                            fontSize: 14,
                                            color: Colors.grey,
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                  Row(
                                    children: [
                                      Text(
                                        '${a['goles_recibidos'] ?? 0}',
                                        style: const TextStyle(
                                          fontSize: 17,
                                          fontWeight: FontWeight.bold,
                                        ),
                                      ),
                                      const SizedBox(width: 4),
                                      const Text('🧤', style: TextStyle(fontSize: 18)),
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
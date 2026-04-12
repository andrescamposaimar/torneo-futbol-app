import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../widgets/entre_redes_app_bar.dart';
import '../widgets/zocalo_publicitario.dart';
import '../widgets/cumpleanos_banner.dart';
import '../widgets/noticia_card.dart';
import '../providers/service_providers.dart';
import 'noticia_detail_screen.dart';

class NoticiasScreen extends ConsumerStatefulWidget {
  const NoticiasScreen({super.key});

  @override
  ConsumerState<NoticiasScreen> createState() => _NoticiasScreenState();
}

class _NoticiasScreenState extends ConsumerState<NoticiasScreen> {
  List<dynamic> _noticias = [];
  int _currentPage = 1;
  bool _isLoading = true;
  bool _isLoadingMore = false;
  bool _hasMore = true;
  String? _error;
  final ScrollController _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    _scrollController.addListener(_onScroll);
    _loadCachedThenFetch();
  }

  @override
  void dispose() {
    _scrollController.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scrollController.position.pixels >=
        _scrollController.position.maxScrollExtent - 200) {
      if (!_isLoadingMore && _hasMore) {
        _fetchMore();
      }
    }
  }

  Future<void> _loadCachedThenFetch() async {
    final cache = ref.read(cacheServiceProvider);
    final cached = await cache.getCachedNoticias();
    if (cached != null && cached.isNotEmpty && mounted) {
      setState(() {
        _noticias = cached;
        _isLoading = false;
      });
    }
    await _fetchNoticias(refresh: true);
  }

  Future<void> _fetchNoticias({bool refresh = false}) async {
    if (refresh) {
      _currentPage = 1;
      _hasMore = true;
    }

    try {
      final api = ref.read(apiServiceProvider);
      final result = await api.getNoticias(page: 1, perPage: 10);
      final items = List<dynamic>.from(result['items'] ?? []);
      final totalPages = result['total_pages'] ?? 1;

      if (!mounted) return;

      // Cachear primera página
      final cache = ref.read(cacheServiceProvider);
      await cache.cacheNoticias(items);

      setState(() {
        _noticias = items;
        _currentPage = 1;
        _hasMore = _currentPage < totalPages;
        _isLoading = false;
        _error = null;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _isLoading = false;
        if (_noticias.isEmpty) {
          _error = 'No se pudieron cargar las noticias';
        }
      });
    }
  }

  Future<void> _fetchMore() async {
    if (_isLoadingMore || !_hasMore) return;
    setState(() => _isLoadingMore = true);

    try {
      final api = ref.read(apiServiceProvider);
      final nextPage = _currentPage + 1;
      final result = await api.getNoticias(page: nextPage, perPage: 10);
      final items = List<dynamic>.from(result['items'] ?? []);
      final totalPages = result['total_pages'] ?? 1;

      if (!mounted) return;

      setState(() {
        _noticias.addAll(items);
        _currentPage = nextPage;
        _hasMore = nextPage < totalPages;
        _isLoadingMore = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _isLoadingMore = false);
    }
  }

  Future<void> _onRefresh() async {
    await _fetchNoticias(refresh: true);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF2F2F7),
      appBar: const EntreRedesAppBar(title: 'Noticias'),
      body: Column(
        children: [
          const CumpleanosBanner(),
          Expanded(child: _buildBody()),
          const ZocaloPublicitario(),
        ],
      ),
    );
  }

  Widget _buildBody() {
    if (_isLoading && _noticias.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _noticias.isEmpty) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.cloud_off, size: 48, color: Colors.grey),
            const SizedBox(height: 12),
            Text(_error!, style: const TextStyle(color: Colors.grey)),
            const SizedBox(height: 12),
            ElevatedButton(
              onPressed: () {
                setState(() {
                  _isLoading = true;
                  _error = null;
                });
                _fetchNoticias(refresh: true);
              },
              child: const Text('Reintentar'),
            ),
          ],
        ),
      );
    }

    if (_noticias.isEmpty) {
      return const Center(
        child: Text('No hay noticias disponibles', style: TextStyle(color: Colors.grey)),
      );
    }

    return RefreshIndicator(
      onRefresh: _onRefresh,
      child: ListView.builder(
        controller: _scrollController,
        padding: const EdgeInsets.only(top: 8, bottom: 8),
        itemCount: _noticias.length + (_hasMore ? 1 : 0),
        itemBuilder: (context, index) {
          if (index == _noticias.length) {
            return const Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator()),
            );
          }

          final noticia = _noticias[index];
          return NoticiaCard(
            noticia: noticia,
            onTap: () {
              Navigator.of(context).push(MaterialPageRoute(
                builder: (_) => NoticiaDetailScreen(noticia: noticia),
              ));
            },
          );
        },
      ),
    );
  }
}

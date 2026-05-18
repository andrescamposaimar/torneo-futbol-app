import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';
import '../config/tenant_provider.dart';
import '../providers/service_providers.dart';
import '../services/remote_data_service.dart';

class ZocaloPublicitario extends ConsumerStatefulWidget {
  const ZocaloPublicitario({super.key});

  @override
  ConsumerState<ZocaloPublicitario> createState() => _ZocaloPublicitarioState();
}

class _ZocaloPublicitarioState extends ConsumerState<ZocaloPublicitario> {
  List<AdItem> ads = [];
  int _currentIndex = 0;
  bool _visible = false;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _loadAds();
  }

  Future<void> _loadAds() async {
    if (!ref.read(tenantConfigProvider).features.ads) return;
    final prefs = await SharedPreferences.getInstance();
    //final lastClosed = prefs.getInt('zocalo_ad_closed_at');
    final now = DateTime.now().millisecondsSinceEpoch;
    await prefs.remove('zocalo_ad_closed_at'); // Fuerza su visibilidad

    /*if (lastClosed != null && now - lastClosed < 3600000) {
      debugPrint('Zócalo oculto por cierre reciente');
      return;
    }*/

    final remoteData = ref.read(remoteDataServiceProvider);
    final loadedAds = await remoteData.fetchZocaloAds();

    if (!mounted || loadedAds.isEmpty) {
      debugPrint('No se montó el widget o la lista está vacía');
      return;
    }

    setState(() {
      ads = loadedAds;
      _visible = true;
    });
    debugPrint('Zócalo visible con ${ads.length} anuncios');
    _startCarrusel();
  }

  void _startCarrusel() {
    _timer = Timer.periodic(const Duration(seconds: 3), (_) {
      if (!mounted || ads.isEmpty) return;
      setState(() {
        _currentIndex = (_currentIndex + 1) % ads.length;
      });
    });
  }

  Future<void> _closeAd() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setInt('zocalo_ad_closed_at', DateTime.now().millisecondsSinceEpoch);
    setState(() => _visible = false);
    _timer?.cancel();
  }

  Future<void> _launchCurrentAdUrl() async {
    final url = ads[_currentIndex].link;
    final uri = Uri.parse(url);
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (!_visible || ads.isEmpty) return const SizedBox.shrink();

    final ad = ads[_currentIndex];

    return GestureDetector(
      onTap: _launchCurrentAdUrl,
      child: SizedBox(
        height: 80,
        width: double.infinity,
        child: Stack(
          children: [
            Positioned.fill(
              child: Container(
                color: Colors.white,
                child: Image.network(
                  ad.imageUrl,
                  fit: BoxFit.contain,
                  alignment: Alignment.center,
                  errorBuilder: (context, error, stackTrace) =>
                      const Center(child: Text('No se pudo cargar la imagen')),
                ),
              ),
            ),
            Positioned(
              top: 4,
              right: 4,
              child: GestureDetector(
                onTap: _closeAd,
                child: Container(
                  padding: const EdgeInsets.all(4),
                  decoration: const BoxDecoration(
                    color: Colors.black54,
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(Icons.close, size: 16, color: Colors.white),
                ),
              ),
            )
          ],
        ),
      ),
    );
  }
}

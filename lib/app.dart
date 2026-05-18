import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';
import 'config/tenant_provider.dart';
import 'screens/players_screen.dart';
import 'screens/standings_screen.dart';
import 'screens/teams_screen.dart';
import 'screens/more_screen.dart';
import 'providers/service_providers.dart';
import 'providers/temporadas_provider.dart';
import 'providers/config_provider.dart';
import 'screens/matches_screen.dart';
import 'screens/noticias_screen.dart';
import 'theme.dart';

class EntreRedesApp extends ConsumerWidget {
  const EntreRedesApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final cfg = ref.watch(tenantConfigProvider);
    return MaterialApp(
      title: cfg.appName,
      debugShowCheckedModeBanner: false,
      theme: buildAppTheme(cfg.colors),
      home: const SplashToMain(),
    );
  }
}

class SplashToMain extends StatefulWidget {
  const SplashToMain({super.key});

  @override
  State<SplashToMain> createState() => _SplashToMainState();
}

class _SplashToMainState extends State<SplashToMain> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      Navigator.of(context).pushReplacement(MaterialPageRoute(
        builder: (_) => const MainNavigation(),
      ));
    });
  }

  @override
  Widget build(BuildContext context) {
    final primary = Theme.of(context).colorScheme.primary;
    return Scaffold(
      backgroundColor: primary,
      body: const Center(
        child: SizedBox.shrink(),
      ),
    );
  }
}

class MainNavigation extends ConsumerStatefulWidget {
  const MainNavigation({super.key});

  @override
  ConsumerState<MainNavigation> createState() => _MainNavigationState();
}

class _MainNavigationState extends ConsumerState<MainNavigation> {
  int _selectedIndex = 2;
  List<Widget>? _screens;
  String? _maintenanceMessage;

  @override
  void initState() {
    super.initState();
    _initScreens();
    ref.read(notificationServiceProvider).init();
  }

  Future<void> _initScreens() async {
    final temporada = await ref.read(temporadaActualProvider.future);
    if (!mounted) return;

    final config = await ref.read(appConfigProvider.future);

    if (!mounted) return;

    setState(() {
      _screens = [
        MatchesScreen(temporadaId: temporada.id),
        const StandingsScreen(),
        const NoticiasScreen(),
        const TeamsScreen(),
        const PlayersScreen(),
        const MoreScreen(),
      ];
      _maintenanceMessage = config?.maintenanceMessage;
    });

    if (config != null && mounted) {
      await _checkMinAppVersion(config.minAppVersion);
      if (mounted) await _checkSeasonAnnouncement(config.seasonAnnouncement);
    }
  }

  Future<void> _checkMinAppVersion(String? minVersion) async {
    if (minVersion == null) return;
    final info = await PackageInfo.fromPlatform();
    if (_isVersionSufficient(info.version, minVersion)) return;
    if (!mounted) return;
    final cfg = ref.read(tenantConfigProvider);
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => AlertDialog(
        title: const Text('Actualización requerida'),
        content: Text(
          'Esta versión de la app (${info.version}) ya no está soportada.\n'
          'Por favor actualizá a la versión $minVersion o superior.',
        ),
        actions: [
          TextButton(
            onPressed: () async {
              final isIos = Theme.of(ctx).platform == TargetPlatform.iOS;
              final storeUrl =
                  isIos ? cfg.iosStoreUrl : cfg.androidStoreUrl;
              if (storeUrl == null) return;
              final uri = Uri.parse(storeUrl);
              if (await canLaunchUrl(uri)) {
                launchUrl(uri, mode: LaunchMode.externalApplication);
              }
            },
            child: const Text('Actualizar'),
          ),
        ],
      ),
    );
  }

  Future<void> _checkSeasonAnnouncement(String? announcement) async {
    if (announcement == null) return;
    final prefs = await SharedPreferences.getInstance();
    final shown = prefs.getString('shown_season_announcement');
    if (shown == announcement) return;
    if (!mounted) return;
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Novedades de la temporada'),
        content: Text(announcement),
        actions: [
          TextButton(
            onPressed: () async {
              await prefs.setString('shown_season_announcement', announcement);
              if (ctx.mounted) Navigator.of(ctx).pop();
            },
            child: const Text('Entendido'),
          ),
        ],
      ),
    );
  }

  bool _isVersionSufficient(String current, String minimum) {
    List<int> parse(String v) =>
        v.split('.').map((s) => int.tryParse(s) ?? 0).toList();
    final c = parse(current);
    final m = parse(minimum);
    while (c.length < 3) c.add(0);
    while (m.length < 3) m.add(0);
    for (int i = 0; i < 3; i++) {
      if (c[i] > m[i]) return true;
      if (c[i] < m[i]) return false;
    }
    return true;
  }

  void _onItemTapped(int index) {
    setState(() {
      _selectedIndex = index;
    });
  }

  @override
  Widget build(BuildContext context) {
    final primary = Theme.of(context).colorScheme.primary;

    if (_screens == null) {
      return Scaffold(
        backgroundColor: primary,
        body: const Center(child: CircularProgressIndicator(color: Colors.white)),
      );
    }

    return Scaffold(
      body: Column(
        children: [
          if (_maintenanceMessage != null)
            MaterialBanner(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              content: Text(
                _maintenanceMessage!,
                style: const TextStyle(color: Colors.black87),
              ),
              leading: const Icon(Icons.warning_amber_rounded, color: Colors.orange),
              backgroundColor: Colors.amber.shade100,
              actions: [
                TextButton(
                  onPressed: () => setState(() => _maintenanceMessage = null),
                  child: const Text('Cerrar'),
                ),
              ],
            ),
          Expanded(
            child: IndexedStack(index: _selectedIndex, children: _screens!),
          ),
        ],
      ),
      bottomNavigationBar: BottomNavigationBar(
        currentIndex: _selectedIndex,
        onTap: _onItemTapped,
        type: BottomNavigationBarType.fixed,
        backgroundColor: primary,
        selectedItemColor: Colors.white,
        unselectedItemColor: Colors.white70,
        items: [
          _buildNavItem(0, Icons.sports_soccer, 'Partidos'),
          _buildNavItem(1, Icons.bar_chart, 'Posiciones'),
          _buildNavItem(2, Icons.newspaper, 'Noticias'),
          _buildNavItem(3, Icons.group, 'Equipos'),
          _buildNavItem(4, Icons.person, 'Jugadores'),
          _buildNavItem(5, Icons.menu, 'Más'),
        ],
      ),
    );
  }

  BottomNavigationBarItem _buildNavItem(int index, IconData icon, String label) {
    final isSelected = _selectedIndex == index;

    return BottomNavigationBarItem(
      label: label,
      icon: AnimatedContainer(
        duration: const Duration(milliseconds: 300),
        curve: Curves.easeOutBack,
        margin: const EdgeInsets.only(bottom: 4),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              height: 3,
              width: 24,
              decoration: BoxDecoration(
                color: isSelected ? Colors.white : Colors.transparent,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
            AnimatedScale(
              scale: isSelected ? 1.2 : 1.0,
              duration: const Duration(milliseconds: 300),
              curve: Curves.easeOutBack,
              child: Icon(icon),
            ),
          ],
        ),
      ),
    );
  }
}

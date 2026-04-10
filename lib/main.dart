import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';
import 'screens/players_screen.dart';
import 'screens/standings_screen.dart';
import 'screens/teams_screen.dart';
import 'screens/more_screen.dart';
import 'providers/service_providers.dart';
import 'providers/temporadas_provider.dart';
import 'providers/config_provider.dart';
import 'screens/matches_screen.dart';
import 'services/config_service.dart';


  void main() async {
    WidgetsFlutterBinding.ensureInitialized();

    // Crear container temporal para operaciones de startup antes de runApp
    final container = ProviderContainer();
    await container.read(cacheServiceProvider).clearCacheOncePerWeekWindow();

    // Aplicar configuración remota: invalida cachés cuyas versiones cambiaron
    final config = await ConfigService.fetchConfig();
    if (config != null) {
      await container.read(cacheServiceProvider).applyRemoteConfig(config);
    }

    container.dispose();

    runApp(
      const ProviderScope(
        child: EntreRedesApp(),
      ),
    );
  }

  class EntreRedesApp extends StatelessWidget {
    const EntreRedesApp({super.key});

    @override
    Widget build(BuildContext context) {
      return MaterialApp(
        title: 'Entre Redes',
        debugShowCheckedModeBanner: false,
        theme: ThemeData(
          useMaterial3: true,
          colorScheme: ColorScheme.fromSeed(seedColor: Colors.cyan).copyWith(
            primary: const Color(0xFF005BBB),
          ),
          appBarTheme: const AppBarTheme(
            backgroundColor: Color(0xFF005BBB),
            foregroundColor: Colors.white,
            iconTheme: IconThemeData(color: Colors.white),
            titleTextStyle: TextStyle(
              color: Colors.white,
              fontSize: 20,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
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
      return const Scaffold(
        backgroundColor: Color(0xFF005BBB),
        body: Center(
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
    int _selectedIndex = 0;
    List<Widget>? _screens;
    String? _maintenanceMessage;

    @override
    void initState() {
      super.initState();
      _initScreens();
    }

    Future<void> _initScreens() async {
      final temporada = await ref.read(temporadaActualProvider.future);
      if (!mounted) return;

      // Config ya está en memoria (sin HTTP adicional)
      final config = await ref.read(appConfigProvider.future);

      if (!mounted) return;

      setState(() {
        _screens = [
          MatchesScreen(temporadaId: temporada.id),
          const StandingsScreen(),
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

    /// Muestra un dialog no-dismissable si la versión instalada es menor a la mínima requerida.
    Future<void> _checkMinAppVersion(String? minVersion) async {
      if (minVersion == null) return;
      final info = await PackageInfo.fromPlatform();
      if (_isVersionSufficient(info.version, minVersion)) return;
      if (!mounted) return;
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
                final uri = Uri.parse(
                  isIos
                      ? 'https://apps.apple.com/app/entre-redes/id6743369159'
                      : 'https://play.google.com/store/apps/details?id=com.entreredes.app',
                );
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

    /// Muestra un dialog one-time cuando hay un anuncio de temporada nuevo.
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

    /// Compara versiones semánticas (major.minor.patch).
    /// Retorna true si [current] >= [minimum].
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
      if (_screens == null) {
        return const Scaffold(
          backgroundColor: Color(0xFF005BBB),
          body: Center(child: CircularProgressIndicator(color: Colors.white)),
        );
      }

      return Scaffold(
        body: Column(
          children: [
            // Banner de mantenimiento — visible en todas las tabs mientras esté activo
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
          backgroundColor: const Color(0xFF005BBB),
          selectedItemColor: Colors.white,
          unselectedItemColor: Colors.white70,
          items: [
            _buildNavItem(0, Icons.sports_soccer, 'Partidos'),
            _buildNavItem(1, Icons.bar_chart, 'Posiciones'),
            _buildNavItem(2, Icons.group, 'Equipos'),
            _buildNavItem(3, Icons.person, 'Jugadores'),
            _buildNavItem(4, Icons.menu, 'Más'),
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

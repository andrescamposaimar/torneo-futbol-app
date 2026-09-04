import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:firebase_crashlytics/firebase_crashlytics.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';
import 'config/tenant_config.dart';
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
  // Default to 0 (Partidos); adjusted after feature flags are resolved in _initScreens.
  int _selectedIndex = 0;
  List<Widget>? _screens;
  String? _maintenanceMessage;

  // Startup-data failure state (e.g. temporadaActualProvider timing out on
  // a network that silently drops packets). When set, build() renders the
  // error screen below instead of the endless spinner.
  Object? _startupError;
  bool _retrying = false;

  @override
  void initState() {
    super.initState();
    _initScreens();
    ref.read(notificationServiceProvider).init();
  }

  Future<void> _initScreens() async {
    try {
      final temporada = await ref.read(temporadaActualProvider.future);
      if (!mounted) return;

      final config = await ref.read(appConfigProvider.future);

      if (!mounted) return;

      final features = ref.read(tenantConfigProvider).features;

      setState(() {
        _screens = [
          MatchesScreen(temporadaId: temporada.id),
          const StandingsScreen(),
          if (features.newsTab) const NoticiasScreen(),
          const TeamsScreen(),
          const PlayersScreen(),
          const MoreScreen(),
        ];
        _maintenanceMessage = config?.maintenanceMessage;
        _startupError = null;
      });

      // Advisory checks: these run once the app is already on screen, so a
      // failure here must be reported but must NOT set _startupError —
      // build() renders the error scaffold ahead of _screens, which would
      // hide an app that loaded perfectly well.
      if (config != null && mounted) {
        try {
          await _checkMinAppVersion(config.minAppVersion);
          if (mounted) {
            await _checkSeasonAnnouncement(config.seasonAnnouncement);
          }
        } catch (e, st) {
          await _reportNonFatal(
            e,
            st,
            'MainNavigation advisory startup checks failed',
          );
        }
      }
    } catch (e, st) {
      await _reportNonFatal(
        e,
        st,
        'MainNavigation._initScreens failed to load startup data',
      );
      if (!mounted) return;
      setState(() => _startupError = e);
    }
  }

  /// Reports [error] to Crashlytics without ever throwing.
  ///
  /// Crashlytics may be unavailable (Firebase.initializeApp failed during
  /// bootstrap, or this is a test environment with no Firebase app at all),
  /// so reporting an error must never raise a new, unhandled one.
  Future<void> _reportNonFatal(
    Object error,
    StackTrace stack,
    String reason,
  ) async {
    try {
      await FirebaseCrashlytics.instance.recordError(
        error,
        stack,
        reason: reason,
        fatal: false,
      );
    } catch (reportingError) {
      debugPrint('❌ $reason ($error) — not reported: $reportingError');
    }
  }

  /// Retries loading the startup data after a failure.
  ///
  /// [temporadasProvider] and [temporadaActualProvider] are plain
  /// `FutureProvider`s: once resolved (including resolved-with-error) they
  /// cache that result. Reading `.future` again without invalidating first
  /// would just replay the same cached error instead of re-fetching, so
  /// both must be invalidated before `_initScreens` reads
  /// `temporadaActualProvider.future` again. `appConfigProvider` is
  /// invalidated too for consistency, even though `ConfigService.fetchConfig`
  /// swallows its own errors (returns null) and so never leaves this
  /// provider in an error state.
  Future<void> _retry() async {
    setState(() => _retrying = true);
    ref.invalidate(temporadasProvider);
    ref.invalidate(temporadaActualProvider);
    ref.invalidate(appConfigProvider);
    await _initScreens();
    if (!mounted) return;
    setState(() => _retrying = false);
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
    final features = ref.watch(tenantConfigProvider).features;

    if (_startupError != null) {
      return _buildStartupErrorScaffold(primary);
    }

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
        items: _buildNavItems(features),
      ),
    );
  }

  Widget _buildStartupErrorScaffold(Color primary) {
    return Scaffold(
      backgroundColor: primary,
      body: Center(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.cloud_off, color: Colors.white, size: 48),
              const SizedBox(height: 16),
              const Text(
                'Tuvimos un problema de conexión y no pudimos cargar la '
                'información.',
                textAlign: TextAlign.center,
                style: TextStyle(color: Colors.white, fontSize: 16),
              ),
              const SizedBox(height: 24),
              ElevatedButton(
                onPressed: _retrying ? null : _retry,
                style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.white,
                  foregroundColor: primary,
                ),
                child: _retrying
                    ? SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: primary,
                        ),
                      )
                    : const Text('Reintentar'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  List<BottomNavigationBarItem> _buildNavItems(TenantFeatures features) {
    int i = 0;
    return [
      _buildNavItem(i++, Icons.sports_soccer, 'Partidos'),
      _buildNavItem(i++, Icons.bar_chart, 'Posiciones'),
      if (features.newsTab) _buildNavItem(i++, Icons.newspaper, 'Noticias'),
      _buildNavItem(i++, Icons.group, 'Equipos'),
      _buildNavItem(i++, Icons.person, 'Jugadores'),
      _buildNavItem(i++, Icons.menu, 'Más'),
    ];
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

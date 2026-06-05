import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:firebase_messaging/firebase_messaging.dart';

import '../config/tenant_provider.dart';
import '../providers/repository_providers.dart';
import '../providers/service_providers.dart';
import '../utils/url_launcher_helper.dart';
import '../widgets/entre_redes_app_bar.dart';
import '../widgets/prode_identity_card.dart';
import 'anuarios_screen.dart';
import 'listas_screen.dart';
import 'prode/prode_auth_gate.dart';
import 'prode/prode_ranking_screen.dart';
import 'scorers_screen.dart';
import 'imbatibles_screen.dart';
import 'solicitud_cambio_webview.dart';

// ---------------------------------------------------------------------------
// Private helpers
// ---------------------------------------------------------------------------

/// A white rounded card that groups navigation tiles under an optional header
/// label — matches the iOS-settings idiom and the fixtures card precedent
/// (white / radius 12 / grey.shade200 border / elevation 1).
class _SectionCard extends StatelessWidget {
  final String title;
  final List<Widget> children;

  const _SectionCard({required this.title, required this.children});

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 6),
          child: Text(
            title,
            style: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: Colors.grey.shade600,
              letterSpacing: 0.5,
            ),
          ),
        ),
        Card(
          color: Colors.white,
          elevation: 1,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
            side: BorderSide(color: Colors.grey.shade200),
          ),
          margin: const EdgeInsets.symmetric(horizontal: 16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: _intersperse(children),
          ),
        ),
      ],
    );
  }

  /// Inserts a [Divider] between each child (not after the last one).
  List<Widget> _intersperse(List<Widget> items) {
    if (items.isEmpty) return items;
    final result = <Widget>[];
    for (var i = 0; i < items.length; i++) {
      result.add(items[i]);
      if (i < items.length - 1) {
        result.add(
          const Divider(height: 1, indent: 56, endIndent: 0),
        );
      }
    }
    return result;
  }
}

/// A styled navigation tile with a tinted icon container, a text label, and a
/// trailing chevron — shared across all [_SectionCard] instances.
Widget _tile(
  BuildContext context,
  String label,
  IconData icon,
  VoidCallback onTap,
) {
  final primary = Theme.of(context).colorScheme.primary;
  return ListTile(
    leading: Container(
      width: 36,
      height: 36,
      decoration: BoxDecoration(
        color: primary.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Icon(icon, color: primary, size: 20),
    ),
    title: Text(label),
    trailing: const Icon(Icons.arrow_forward_ios, size: 16),
    onTap: onTap,
  );
}

// ---------------------------------------------------------------------------
// Screen
// ---------------------------------------------------------------------------

class MoreScreen extends ConsumerStatefulWidget {
  const MoreScreen({super.key});

  @override
  ConsumerState<MoreScreen> createState() => _MoreScreenState();
}

class _MoreScreenState extends ConsumerState<MoreScreen> {
  bool _notificacionesHabilitadas = true;
  String? _fcmToken;

  @override
  void initState() {
    super.initState();
    _cargarEstadoNotificaciones();
    if (kDebugMode) _cargarFcmToken();
  }

  Future<void> _cargarEstadoNotificaciones() async {
    final habilitadas =
        await ref.read(notificationServiceProvider).isEnabled();
    if (mounted) setState(() => _notificacionesHabilitadas = habilitadas);
  }

  Future<void> _cargarFcmToken() async {
    try {
      final token = await FirebaseMessaging.instance.getToken();
      if (mounted) setState(() => _fcmToken = token ?? 'Esperando token...');
    } catch (_) {
      FirebaseMessaging.instance.onTokenRefresh.listen((token) {
        if (mounted) setState(() => _fcmToken = token);
      });
      if (mounted) setState(() => _fcmToken = 'Esperando token...');
    }
  }

  @override
  Widget build(BuildContext context) {
    final cfg = ref.watch(tenantConfigProvider);
    final docs = cfg.documents;
    final features = cfg.features;

    return Scaffold(
      backgroundColor: Colors.grey.shade100,
      appBar: EntreRedesAppBar(title: 'Otras Opciones'),
      body: ListView(
        padding: const EdgeInsets.symmetric(vertical: 16),
        children: [
          // 1. Identity card (prode guard is inside the widget)
          if (features.prode) ...[
            const Padding(
              padding: EdgeInsets.symmetric(horizontal: 16),
              child: ProdeIdentityCard(),
            ),
            const SizedBox(height: 12),
          ],

          // 2. Prode section
          if (features.prode) ...[
            _SectionCard(
              title: 'Prode',
              children: [
                _tile(
                  context,
                  'Mis pronósticos',
                  Icons.scoreboard,
                  () => Navigator.push(
                    context,
                    MaterialPageRoute<void>(
                      builder: (_) => const ProdeAuthGate(),
                    ),
                  ),
                ),
                _tile(
                  context,
                  'Ranking',
                  Icons.military_tech,
                  () => Navigator.push(
                    context,
                    MaterialPageRoute<void>(
                      builder: (_) => const ProdeRankingScreen(),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
          ],

          // 3. Estadísticas (always shown)
          _SectionCard(
            title: 'Estadísticas',
            children: [
              _tile(
                context,
                'Goleadores',
                Icons.sports_soccer,
                () => Navigator.push(
                  context,
                  MaterialPageRoute<void>(
                    builder: (_) => const ScorersScreen(),
                  ),
                ),
              ),
              _tile(
                context,
                'Imbatibles',
                Icons.sports_handball,
                () => Navigator.push(
                  context,
                  MaterialPageRoute<void>(
                    builder: (_) => const ImbatiblesScreen(),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),

          // 4. Gestión Torneo (card hidden when no tiles are visible — AC-28, AC-29)
          if (docs.solicitudCambioUrl != null || features.waitingLists) ...[
          _SectionCard(
            title: 'Gestión Torneo',
            children: [
              if (docs.solicitudCambioUrl != null)
                _tile(
                  context,
                  'Solicitud de cambio de jugador',
                  Icons.swap_horiz,
                  () => Navigator.push(
                    context,
                    MaterialPageRoute<void>(
                      builder: (_) => SolicitudCambioWebViewScreen(
                        url: docs.solicitudCambioUrl!,
                      ),
                    ),
                  ),
                ),
              if (features.waitingLists)
                _tile(
                  context,
                  'Lista de Espera',
                  Icons.people_alt,
                  () => Navigator.push(
                    context,
                    MaterialPageRoute<void>(
                      builder: (_) => const ListasScreen(),
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 12),
          ],

          // 5. Información (shown only when at least one URL is present — AC-30)
          if (docs.reglamentoUrl != null || docs.modalidadUrl != null) ...[
            _SectionCard(
              title: 'Información',
              children: [
                if (docs.reglamentoUrl != null)
                  _tile(
                    context,
                    'Reglamento',
                    Icons.rule,
                    () => abrirPdf(docs.reglamentoUrl!),
                  ),
                if (docs.modalidadUrl != null)
                  _tile(
                    context,
                    'Modalidad Torneo',
                    Icons.description,
                    () => abrirPdf(docs.modalidadUrl!),
                  ),
              ],
            ),
            const SizedBox(height: 12),
          ],

          // 6. Anuarios — single tile → AnuariosScreen (AC-32, AC-33)
          if (docs.anuarios.isNotEmpty) ...[
            _SectionCard(
              title: 'Anuarios',
              children: [
                _tile(
                  context,
                  'Anuarios',
                  Icons.menu_book,
                  () => Navigator.push(
                    context,
                    MaterialPageRoute<void>(
                      builder: (_) =>
                          AnuariosScreen(anuarios: docs.anuarios),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
          ],

          // 7. Notificaciones (ALWAYS last before debug — AC-36, AC-37)
          _SectionCard(
            title: 'Notificaciones',
            children: [
              SwitchListTile(
                secondary: Icon(
                  Icons.notifications,
                  color: Theme.of(context).colorScheme.primary,
                ),
                title: const Text('Avisos del torneo'),
                subtitle: const Text(
                    'Recibir alertas sobre novedades y anuncios'),
                value: _notificacionesHabilitadas,
                activeTrackColor: Theme.of(context).colorScheme.primary,
                onChanged: (value) async {
                  setState(() => _notificacionesHabilitadas = value);
                  await ref
                      .read(notificationServiceProvider)
                      .setEnabled(value);
                },
              ),
            ],
          ),

          // 8. Debug section (kDebugMode only — AC-39, AC-40)
          if (kDebugMode) ...[
            const Divider(),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16.0),
              child: _fcmToken == null
                  ? const Text('Cargando FCM Token...',
                      style: TextStyle(fontSize: 10, color: Colors.grey))
                  : GestureDetector(
                      onTap: () {
                        Clipboard.setData(
                            ClipboardData(text: _fcmToken!));
                        ScaffoldMessenger.of(context).showSnackBar(
                          const SnackBar(
                              content: Text('FCM Token copiado')),
                        );
                      },
                      child: Text(
                        'FCM Token (tap para copiar):\n$_fcmToken',
                        style: const TextStyle(
                            fontSize: 10, color: Colors.grey),
                      ),
                    ),
            ),
            const SizedBox(height: 12),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16.0),
              child: ElevatedButton.icon(
                icon: const Icon(Icons.delete_forever),
                label: const Text('Limpiar toda la caché'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.red,
                  foregroundColor: Colors.white,
                ),
                onPressed: () async {
                  await ref
                      .read(cacheRepositoryProvider)
                      .clearAll();
                  if (context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                          content: Text('Caché eliminada correctamente')),
                    );
                  }
                },
              ),
            ),
            const SizedBox(height: 20),
          ],
        ],
      ),
    );
  }
}

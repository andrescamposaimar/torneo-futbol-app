import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'listas_screen.dart';
import 'scorers_screen.dart';
import 'imbatibles_screen.dart';
import 'package:flutter/foundation.dart';
import '../config/tenant_provider.dart';
import '../providers/repository_providers.dart';
import '../providers/service_providers.dart';
import 'package:url_launcher/url_launcher.dart';
import 'solicitud_cambio_webview.dart';
import '../widgets/entre_redes_app_bar.dart';


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
      // Escuchar cuando el token esté disponible
      FirebaseMessaging.instance.onTokenRefresh.listen((token) {
        if (mounted) setState(() => _fcmToken = token);
      });
      if (mounted) setState(() => _fcmToken = 'Esperando token...');
    }
  }

  Widget _sectionTitle(String title) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 8.0, horizontal: 16),
        child: Text(
          title,
          style: const TextStyle(
            fontSize: 16,
            color: Colors.black,
            fontWeight: FontWeight.bold,
          ),
        ),
      );

  Widget _menuItem(String label, IconData icon, VoidCallback onTap) => ListTile(
        leading: Icon(icon, color: Colors.black),
        title: Text(label),
        trailing: const Icon(Icons.arrow_forward_ios, size: 16),
        onTap: onTap,
      );

  @override
  Widget build(BuildContext context) {
    final docs = ref.watch(tenantConfigProvider).documents;

    void abrirPdf(String url) async {
      final uri = Uri.parse(url);
      if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
        throw Exception('No se pudo abrir $url');
      }
    }

    return Scaffold(
      appBar: EntreRedesAppBar(title: 'Otras Opciones'),
      body: ListView(
        children: [
          _sectionTitle('Notificaciones'),
          SwitchListTile(
            secondary: const Icon(Icons.notifications, color: Colors.black),
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
          _sectionTitle('Estadísticas'),
          _menuItem(
            'Goleadores',
            Icons.sports_soccer,
            () {
              Navigator.push(
                context,
                MaterialPageRoute(builder: (_) => const ScorersScreen()),
              );
            },
          ),
          _menuItem(
            'Imbatibles',
            Icons.sports_handball,
            () {
              Navigator.push(
                context,
                MaterialPageRoute(builder: (_) => const ImbatiblesScreen()),
              );
            },
          ),

          _sectionTitle('Gestión Torneo'),
          if (docs.solicitudCambioUrl != null)
            _menuItem(
              'Solicitud de cambio de jugador',
              Icons.swap_horiz,
              () {
              Navigator.push(
                context,
                MaterialPageRoute(
                  builder: (_) => SolicitudCambioWebViewScreen(url: docs.solicitudCambioUrl!),
                ),
              );
              },
            ),
          _menuItem(
            'Lista de Espera',
            Icons.people_alt,
            () {
              Navigator.push(
                context,
                MaterialPageRoute(builder: (_) => const ListasScreen()),
              );
            },
          ),

          if (docs.reglamentoUrl != null || docs.modalidadUrl != null)
            _sectionTitle('Información'),
          if (docs.reglamentoUrl != null)
            _menuItem(
              'Reglamento',
              Icons.rule,
              () => abrirPdf(docs.reglamentoUrl!),
            ),
          if (docs.modalidadUrl != null)
            _menuItem(
              'Modalidad Torneo',
              Icons.description,
              () => abrirPdf(docs.modalidadUrl!),
            ),

          if (docs.anuarios.isNotEmpty)
            _sectionTitle('Anuarios'),
          ...docs.anuarios.map((a) => _menuItem(
            a.label,
            Icons.menu_book,
            () => abrirPdf(a.url),
          )),

          if (kDebugMode) ...[
            const Divider(),
            Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16.0),
                child: _fcmToken == null
                    ? const Text('Cargando FCM Token...',
                        style: TextStyle(fontSize: 10, color: Colors.grey))
                    : GestureDetector(
                        onTap: () {
                          Clipboard.setData(ClipboardData(text: _fcmToken!));
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(content: Text('FCM Token copiado')),
                          );
                        },
                        child: Text(
                          'FCM Token (tap para copiar):\n$_fcmToken',
                          style: const TextStyle(fontSize: 10, color: Colors.grey),
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
                  await ref.read(cacheRepositoryProvider).clearAll();
                  if (context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('Caché eliminada correctamente')),
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
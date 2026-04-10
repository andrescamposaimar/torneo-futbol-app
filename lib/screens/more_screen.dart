import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'listas_screen.dart';
import 'scorers_screen.dart';
import 'imbatibles_screen.dart';
import 'package:flutter/foundation.dart';
import '../providers/repository_providers.dart';
import 'package:url_launcher/url_launcher.dart';
import 'solicitud_cambio_webview.dart';
import '../widgets/entre_redes_app_bar.dart';


class MoreScreen extends ConsumerWidget {
  const MoreScreen({super.key});

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
  Widget build(BuildContext context, WidgetRef ref) {
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
          _menuItem(
            'Solicitud de cambio de jugador',
            Icons.swap_horiz,
            () {
            Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const SolicitudCambioWebViewScreen()),
            );
            },
          ),
          _menuItem(
            'Lista de Espera y Reserva',
            Icons.people_alt,
            () {
              Navigator.push(
                context,
                MaterialPageRoute(builder: (_) => const ListasScreen()),
              );
            },
          ),

          _sectionTitle('Información'),
          _menuItem(
            'Reglamento',
            Icons.rule,
            () => abrirPdf('https://entreredespadres.com.ar/wp-content/uploads/2026/REGLAMENTO-CHAMI-2026.pdf'),
          ),
          _menuItem(
            'Modalidad Torneo',
            Icons.description,
            () => abrirPdf('https://entreredespadres.com.ar/wp-content/uploads/2026/modalidad_torneo_2026.pdf'),
          ),

          _sectionTitle('Anuarios'),
          _menuItem(
            'Anuario 2022',
            Icons.menu_book,
            () => abrirPdf('https://entreredespadres.com.ar/wp-content/uploads/anuarios/Entreredes2022-Anuario.pdf'),
          ),
          _menuItem(
            'Anuario 2023',
            Icons.menu_book,
            () => abrirPdf('https://entreredespadres.com.ar/wp-content/uploads/anuarios/Anuario-2023-OK.pdf'),
          ),
          _menuItem(
            'Anuario 2024',
            Icons.menu_book,
            () => abrirPdf('https://entreredespadres.com.ar/wp-content/uploads/anuarios/Anuario-2024.pdf'),
          ),
          _menuItem(
            'Anuario 2025',
            Icons.menu_book,
            () => abrirPdf('https://entreredespadres.com.ar/wp-content/uploads/anuarios/Anuario-2025.pdf'),
          ),

          if (kDebugMode) ...[
            const Divider(),
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
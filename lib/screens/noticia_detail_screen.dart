import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:flutter_widget_from_html_core/flutter_widget_from_html_core.dart';
import 'package:url_launcher/url_launcher.dart';

class NoticiaDetailScreen extends StatelessWidget {
  final Map<String, dynamic> noticia;

  const NoticiaDetailScreen({super.key, required this.noticia});

  String get _titulo {
    final title = noticia['title'];
    if (title is Map) return title['rendered']?.toString() ?? '';
    return title?.toString() ?? '';
  }

  String get _contenido {
    final content = noticia['content'];
    if (content is Map) return content['rendered']?.toString() ?? '';
    return content?.toString() ?? '';
  }

  String? get _imagenUrl {
    try {
      final embedded = noticia['_embedded'];
      if (embedded is Map) {
        final media = embedded['wp:featuredmedia'];
        if (media is List && media.isNotEmpty) {
          return media[0]['source_url']?.toString();
        }
      }
    } catch (_) {}
    return null;
  }

  String get _fecha {
    final dateStr = noticia['date']?.toString();
    if (dateStr == null) return '';
    final date = DateTime.tryParse(dateStr);
    if (date == null) return '';
    return DateFormat("d 'de' MMMM, yyyy", 'es').format(date);
  }

  @override
  Widget build(BuildContext context) {
    final imagen = _imagenUrl;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Noticia'),
      ),
      body: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (imagen != null)
              AspectRatio(
                aspectRatio: 16 / 9,
                child: Image.network(
                  imagen,
                  fit: BoxFit.cover,
                  width: double.infinity,
                  errorBuilder: (_, __, ___) => const SizedBox.shrink(),
                ),
              ),
            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    _fecha,
                    style: TextStyle(fontSize: 13, color: Colors.grey[600]),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    _titulo,
                    style: const TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  const SizedBox(height: 16),
                  HtmlWidget(
                    _contenido,
                    onTapUrl: (url) {
                      final uri = Uri.tryParse(url);
                      if (uri != null) {
                        launchUrl(uri, mode: LaunchMode.externalApplication);
                      }
                      return true;
                    },
                    textStyle: const TextStyle(fontSize: 15, height: 1.6),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

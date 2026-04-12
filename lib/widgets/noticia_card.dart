import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart'; // Share.share(text)

class NoticiaCard extends StatelessWidget {
  final Map<String, dynamic> noticia;
  final VoidCallback onTap;

  const NoticiaCard({super.key, required this.noticia, required this.onTap});

  String get _titulo {
    final title = noticia['title'];
    if (title is Map) return title['rendered']?.toString() ?? '';
    return title?.toString() ?? '';
  }

  String get _extracto {
    final excerpt = noticia['excerpt'];
    String html;
    if (excerpt is Map) {
      html = excerpt['rendered']?.toString() ?? '';
    } else {
      html = excerpt?.toString() ?? '';
    }
    return html
        .replaceAll(RegExp(r'<[^>]*>'), '')
        .replaceAll('&nbsp;', ' ')
        .replaceAll('&amp;', '&')
        .replaceAll('&lt;', '<')
        .replaceAll('&gt;', '>')
        .replaceAll('&quot;', '"')
        .replaceAll('&#8217;', "'")
        .replaceAll('&#8230;', '...')
        .replaceAll(RegExp(r'&#\d+;'), '')
        .replaceAll(RegExp(r'\s+'), ' ')
        .trim();
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

  String get _fechaRelativa {
    final dateStr = noticia['date']?.toString();
    if (dateStr == null) return '';
    final date = DateTime.tryParse(dateStr);
    if (date == null) return '';
    final diff = DateTime.now().difference(date);

    if (diff.inMinutes < 1) return 'Ahora';
    if (diff.inMinutes < 60) return 'Hace ${diff.inMinutes} Minutos';
    if (diff.inHours < 24) return 'Hace ${diff.inHours} Horas';
    if (diff.inDays < 7) return 'Hace ${diff.inDays} Dias';
    if (diff.inDays < 30) return 'Hace ${(diff.inDays / 7).floor()} Semanas';
    return 'Hace ${(diff.inDays / 30).floor()} Meses';
  }

  String? get _linkUrl => noticia['link']?.toString();

  void _compartir() {
    final url = _linkUrl;
    if (url != null) {
      Share.share('$_titulo\n$url');
    }
  }

  @override
  Widget build(BuildContext context) {
    final imagen = _imagenUrl;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      child: Material(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        elevation: 2,
        shadowColor: Colors.black26,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Imagen grande
              ClipRRect(
                borderRadius: const BorderRadius.vertical(top: Radius.circular(16)),
                child: imagen != null
                    ? AspectRatio(
                        aspectRatio: 4 / 3,
                        child: Image.network(
                          imagen,
                          fit: BoxFit.cover,
                          width: double.infinity,
                          errorBuilder: (_, __, ___) => AspectRatio(
                            aspectRatio: 4 / 3,
                            child: Container(
                              color: Colors.grey[200],
                              child: const Center(
                                child: Icon(Icons.image_not_supported,
                                    size: 48, color: Colors.grey),
                              ),
                            ),
                          ),
                        ),
                      )
                    : AspectRatio(
                        aspectRatio: 4 / 3,
                        child: Container(
                          color: Colors.grey[200],
                          child: const Center(
                            child:
                                Icon(Icons.article, size: 48, color: Colors.grey),
                          ),
                        ),
                      ),
              ),

              // Titulo
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 14, 14, 0),
                child: Text(
                  _titulo,
                  style: const TextStyle(
                    fontSize: 17,
                    fontWeight: FontWeight.w700,
                    height: 1.3,
                  ),
                  maxLines: 3,
                  overflow: TextOverflow.ellipsis,
                ),
              ),

              // Extracto
              if (_extracto.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.fromLTRB(14, 6, 14, 0),
                  child: Text(
                    _extracto,
                    style: TextStyle(
                      fontSize: 14,
                      color: Colors.grey[600],
                      height: 1.4,
                    ),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),

              // Fecha relativa
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 8, 14, 0),
                child: Text(
                  _fechaRelativa,
                  style: TextStyle(
                    fontSize: 13,
                    color: Colors.grey[500],
                  ),
                ),
              ),

              // Barra de acciones
              Padding(
                padding: const EdgeInsets.fromLTRB(6, 4, 6, 4),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    TextButton.icon(
                      onPressed: _compartir,
                      icon: Icon(Icons.ios_share, size: 18, color: Colors.grey[600]),
                      label: Text(
                        'COMPARTIR',
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          color: Colors.grey[600],
                          letterSpacing: 0.5,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

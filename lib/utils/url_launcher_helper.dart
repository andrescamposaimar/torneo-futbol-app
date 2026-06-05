import 'package:url_launcher/url_launcher.dart';

/// Opens a PDF (or any URL) in an external application.
///
/// Uses [LaunchMode.externalApplication] so the OS browser or PDF viewer
/// handles the document — same semantics as the previous inline [launchUrl]
/// call in MoreScreen.
///
/// Throws an [Exception] if the URL cannot be launched (e.g. malformed URI or
/// no handler installed).
Future<void> abrirPdf(String url) async {
  final uri = Uri.parse(url);
  if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
    throw Exception('No se pudo abrir $url');
  }
}

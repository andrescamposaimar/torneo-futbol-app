import 'package:flutter/material.dart';
import 'package:webview_flutter/webview_flutter.dart';

class SolicitudCambioWebViewScreen extends StatefulWidget {
  const SolicitudCambioWebViewScreen({super.key});

  @override
  State<SolicitudCambioWebViewScreen> createState() => _SolicitudCambioWebViewScreenState();
}

class _SolicitudCambioWebViewScreenState extends State<SolicitudCambioWebViewScreen> {
  late final WebViewController _controller;

  @override
  void initState() {
    super.initState();
    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setNavigationDelegate(
        NavigationDelegate(
          onPageFinished: (url) async {
            try {
              await _controller.runJavaScript("""
                // Ocultar header
                var header = document.getElementById('cm-masthead');
                if (header) header.style.display = 'none';

                // Ocultar footer
                var footer = document.getElementById('cm-footer');
                if (footer) footer.style.display = 'none';

                // Ocultar ads
                var ad = document.querySelector('.advertisement_above_footer');
                if (ad) ad.style.display = 'none';
              """);
            } catch (e) {
              debugPrint('❌ Error al ejecutar JS: \$e');
            }
          },
        ),
      )
      ..loadRequest(Uri.parse('https://entreredespadres.com.ar/jugadores/solicitud-de-cambios'));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Solicitud de Cambio')),
      body: WebViewWidget(controller: _controller),
    );
  }
}
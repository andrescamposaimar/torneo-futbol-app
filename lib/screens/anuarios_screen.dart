import 'package:flutter/material.dart';

import '../config/tenant_config.dart';
import '../utils/url_launcher_helper.dart';
import '../widgets/entre_redes_app_bar.dart';

/// Displays the list of yearbooks (anuarios) for the league.
///
/// Receives the list from its parent ([MoreScreen]) so it does NOT need to
/// read [tenantConfigProvider] directly (AC-35). Each tile launches its PDF
/// via [abrirPdf] (AC-34, AC-35).
class AnuariosScreen extends StatelessWidget {
  final List<TenantAnuario> anuarios;

  const AnuariosScreen({super.key, required this.anuarios});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: EntreRedesAppBar(title: 'Anuarios'),
      body: ListView(
        children: anuarios
            .map(
              (a) => ListTile(
                leading: const Icon(Icons.menu_book),
                title: Text(a.label),
                trailing: const Icon(Icons.arrow_forward_ios, size: 16),
                onTap: () => abrirPdf(a.url),
              ),
            )
            .toList(),
      ),
    );
  }
}

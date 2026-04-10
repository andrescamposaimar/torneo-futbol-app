import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../providers/config_provider.dart';

/// AppBar compartido de Entre Redes.
/// Muestra el logo configurado remotamente (app_bar_logo_url en configuraciones.json)
/// alineado a la izquierda con 5px de margen. Si no hay logo o falla la carga,
/// el espacio de leading queda vacío y el título de texto se muestra normalmente.
class EntreRedesAppBar extends ConsumerWidget implements PreferredSizeWidget {
  final String title;
  final List<Widget>? actions;
  final bool? centerTitle;
  final PreferredSizeWidget? bottom;

  const EntreRedesAppBar({
    super.key,
    required this.title,
    this.actions,
    this.centerTitle,
    this.bottom,
  });

  @override
  Size get preferredSize => Size.fromHeight(
        kToolbarHeight + (bottom?.preferredSize.height ?? 0),
      );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final config = ref.watch(appConfigProvider).valueOrNull;
    final logoUrl = config?.appBarLogoUrl;
    final hasLogo = logoUrl != null && logoUrl.isNotEmpty;
    final canPop = Navigator.of(context).canPop();

    return AppBar(
      leading: hasLogo && !canPop ? _buildLogoLeading(logoUrl) : null,
      leadingWidth: hasLogo && !canPop ? 95.0 : null,
      title: Text(title),
      centerTitle: centerTitle,
      actions: actions,
      bottom: bottom,
    );
  }

  Widget _buildLogoLeading(String url) {
    return Padding(
      padding: const EdgeInsets.only(left: 5),
      child: Image.network(
        url,
        fit: BoxFit.contain,
        width: 90,
        height: 90,
        errorBuilder: (_, __, ___) => const SizedBox.shrink(),
      ),
    );
  }
}

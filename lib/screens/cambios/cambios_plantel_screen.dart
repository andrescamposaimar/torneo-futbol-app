import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../models/cambios_plaza.dart';
import '../../models/cambios_solicitud.dart';
import '../../providers/cambios_providers.dart';
import '../../services/cambios_plantel_controller.dart';
import '../../widgets/loading_seccion.dart';
import 'cambios_solicitar_screen.dart';
import 'cambios_solicitudes_screen.dart';

/// "Mi Plantel" — the captain's own plazas: who each belongs to (titular),
/// who occupies it today, and whether that's the titular. From a row the
/// captain can start a cambio (any open plaza) or ask for the titular's
/// return (only when [CambiosPlaza.regresoElegible]).
///
/// Container: watches [cambiosPlantelControllerProvider] scoped to
/// ([seasonId], [teamId]) — the provider itself triggers the initial
/// [CambiosPlantelController.load] (see `cambios_providers.dart`), so no
/// initState bootstrap is needed here.
class CambiosPlantelScreen extends ConsumerWidget {
  final int seasonId;
  final int teamId;

  const CambiosPlantelScreen({
    super.key,
    required this.seasonId,
    required this.teamId,
  });

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final scope = (seasonId: seasonId, teamId: teamId);
    final state = ref.watch(cambiosPlantelControllerProvider(scope));
    final notifier = ref.read(cambiosPlantelControllerProvider(scope).notifier);

    return CambiosPlantelView(
      state: state,
      onRetry: () => notifier.load(seasonId: seasonId, teamId: teamId),
      onRefresh: () => notifier.refresh(seasonId: seasonId, teamId: teamId),
      // fechaId: null — see CambiosSolicitarScreen's own docblock,
      // "[fechaId] — A CONFIRMED BACKEND GAP": no endpoint exists yet to
      // discover which fecha a solicitud should target.
      onPedirCambio: (plaza) => Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => CambiosSolicitarScreen(
            seasonId: seasonId,
            teamId: teamId,
            plaza: plaza,
            tipo: CambiosSolicitudTipo.sustitucion,
            fechaId: null,
          ),
        ),
      ),
      onPedirRegreso: (plaza) => Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => CambiosSolicitarScreen(
            seasonId: seasonId,
            teamId: teamId,
            plaza: plaza,
            tipo: CambiosSolicitudTipo.regreso,
            fechaId: null,
          ),
        ),
      ),
      onVerSolicitudes: () => Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => CambiosSolicitudesScreen(
            seasonId: seasonId,
            teamId: teamId,
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Presentational view (Riverpod-free)
// ---------------------------------------------------------------------------

class CambiosPlantelView extends StatelessWidget {
  final CambiosPlantelState state;
  final VoidCallback onRetry;
  final Future<void> Function() onRefresh;
  final void Function(CambiosPlaza plaza) onPedirCambio;
  final void Function(CambiosPlaza plaza) onPedirRegreso;
  final VoidCallback onVerSolicitudes;

  const CambiosPlantelView({
    super.key,
    required this.state,
    required this.onRetry,
    required this.onRefresh,
    required this.onPedirCambio,
    required this.onPedirRegreso,
    required this.onVerSolicitudes,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'Mi Plantel',
                style: Theme.of(context)
                    .textTheme
                    .titleLarge
                    ?.copyWith(fontWeight: FontWeight.bold),
              ),
              TextButton.icon(
                key: const Key('ver_solicitudes_button'),
                onPressed: onVerSolicitudes,
                icon: const Icon(Icons.list_alt),
                label: const Text('Mis pedidos'),
              ),
            ],
          ),
        ),
        Expanded(
          child: switch (state) {
            CambiosPlantelLoading() =>
              const LoadingSeccion(texto: 'Cargando tu plantel...'),
            CambiosPlantelError() => _ErrorView(onRetry: onRetry),
            CambiosPlantelLoaded(:final plazas) => plazas.isEmpty
                ? const _EmptyView()
                : RefreshIndicator(
                    onRefresh: onRefresh,
                    child: ListView.builder(
                      key: const Key('plantel_list'),
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: const EdgeInsets.symmetric(vertical: 8),
                      itemCount: plazas.length,
                      itemBuilder: (context, i) => _PlazaCard(
                        plaza: plazas[i],
                        onPedirCambio: () => onPedirCambio(plazas[i]),
                        onPedirRegreso: () => onPedirRegreso(plazas[i]),
                      ),
                    ),
                  ),
          },
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Per-state views
// ---------------------------------------------------------------------------

class _ErrorView extends StatelessWidget {
  final VoidCallback onRetry;
  const _ErrorView({required this.onRetry});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.error_outline, size: 64, color: theme.colorScheme.primary),
            const SizedBox(height: 16),
            Text('Algo salió mal', style: theme.textTheme.headlineSmall),
            const SizedBox(height: 8),
            const Text(
              'No pudimos cargar tu plantel. Revisá tu conexión y reintentá '
              'en unos minutos.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 24),
            ElevatedButton(onPressed: onRetry, child: const Text('Reintentar')),
          ],
        ),
      ),
    );
  }
}

/// The plazas table is empty in production until a backfill that has not
/// happened yet — this is the FIRST thing real captains will see, so it must
/// explain itself rather than look broken.
class _EmptyView extends StatelessWidget {
  const _EmptyView();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.inbox_outlined, size: 64, color: theme.colorScheme.primary),
            const SizedBox(height: 16),
            Text('Todavía no hay plazas cargadas',
                style: theme.textTheme.headlineSmall, textAlign: TextAlign.center),
            const SizedBox(height: 8),
            const Text(
              'Tu equipo todavía no tiene el plantel cargado en el sistema '
              'de cambios. Ni bien esté disponible vas a poder pedir cambios '
              'y regresos desde acá.',
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Plaza card
// ---------------------------------------------------------------------------

class _PlazaCard extends StatelessWidget {
  final CambiosPlaza plaza;
  final VoidCallback onPedirCambio;
  final VoidCallback onPedirRegreso;

  const _PlazaCard({
    required this.plaza,
    required this.onPedirCambio,
    required this.onPedirRegreso,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      child: Card(
        color: Colors.white,
        elevation: 1,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: BorderSide(color: Colors.grey.shade200),
        ),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    plaza.tipoLabel,
                    style: theme.textTheme.labelLarge
                        ?.copyWith(color: theme.colorScheme.primary, fontWeight: FontWeight.bold),
                  ),
                  if (plaza.cerrada)
                    Container(
                      key: const Key('plaza_cerrada_badge'),
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: Colors.grey.shade200,
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: const Text('Cerrada', style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600)),
                    ),
                ],
              ),
              const SizedBox(height: 6),
              Text('Titular: ${plaza.titularNombre}', style: theme.textTheme.bodyMedium),
              const SizedBox(height: 2),
              Text(
                plaza.esTitularElOcupante
                    ? 'El titular está jugando esta plaza.'
                    : plaza.ocupanteNombre != null
                        ? 'Ocupa la plaza: ${plaza.ocupanteNombre}'
                        : 'Nadie está ocupando esta plaza actualmente.',
                style: theme.textTheme.bodyMedium?.copyWith(color: Colors.grey.shade700),
              ),
              if (!plaza.esTitularElOcupante && plaza.ocupanteNombre != null) ...[
                const SizedBox(height: 2),
                Text(
                  plaza.fechasFaltantesLiberacionIndeterminado
                      ? 'No pudimos calcular cuántas fechas faltan para que '
                          'el titular pueda volver.'
                      : plaza.fechasFaltantesLiberacion == 0
                          ? 'El titular ya puede volver.'
                          : 'Faltan ${plaza.fechasFaltantesLiberacion} fecha(s) '
                              'para que el titular pueda volver.',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: Colors.grey.shade600,
                    fontStyle: FontStyle.italic,
                  ),
                ),
              ],
              if (!plaza.cerrada) ...[
                const SizedBox(height: 10),
                Row(
                  children: [
                    OutlinedButton(
                      key: Key('pedir_cambio_${plaza.plazaId}'),
                      onPressed: onPedirCambio,
                      child: const Text('Pedir cambio'),
                    ),
                    if (plaza.regresoElegible) ...[
                      const SizedBox(width: 8),
                      TextButton(
                        key: Key('pedir_regreso_${plaza.plazaId}'),
                        onPressed: onPedirRegreso,
                        child: const Text('Pedir regreso'),
                      ),
                    ],
                  ],
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

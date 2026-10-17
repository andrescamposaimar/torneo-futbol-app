import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../models/cambios_vigente.dart';
import '../../providers/cambios_providers.dart';
import '../../services/cambios_vigentes_controller.dart';
import '../../widgets/entre_redes_app_bar.dart';
import '../../widgets/loading_seccion.dart';

/// "Cambios activos" — every live substitution of the current season, grouped
/// by team. Public: needs no sign-in.
class CambiosActivosScreen extends ConsumerWidget {
  const CambiosActivosScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(cambiosVigentesControllerProvider);
    final notifier = ref.read(cambiosVigentesControllerProvider.notifier);

    return Scaffold(
      appBar: const EntreRedesAppBar(title: 'Cambios activos'),
      body: CambiosActivosView(
        state: state,
        onRetry: notifier.load,
        onRefresh: () async {
          final actualizado = await notifier.refresh();
          if (!actualizado && context.mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(
                content: Text('No se pudo actualizar. Revisá tu conexión.'),
              ),
            );
          }
        },
      ),
    );
  }
}

/// Presentational view (Riverpod-free).
class CambiosActivosView extends StatelessWidget {
  final CambiosVigentesState state;
  final VoidCallback onRetry;
  final Future<void> Function() onRefresh;

  const CambiosActivosView({
    super.key,
    required this.state,
    required this.onRetry,
    required this.onRefresh,
  });

  @override
  Widget build(BuildContext context) {
    return switch (state) {
      CambiosVigentesLoading() => const LoadingSeccion(texto: 'Cargando cambios activos...'),
      CambiosVigentesError() => _ErrorView(onRetry: onRetry),
      CambiosVigentesLoaded(:final vigentes) => RefreshIndicator(
          onRefresh: onRefresh,
          child: vigentes.isEmpty ? const _EmptyView() : _GroupedList(vigentes: vigentes),
        ),
    };
  }
}

/// Splits [vigentes] into consecutive runs per team, keeping the server order
/// (already sorted by team then plaza).
@visibleForTesting
List<({int teamId, String teamNombre, List<CambiosVigente> rows})> agruparPorEquipo(
  List<CambiosVigente> vigentes,
) {
  final grupos = <({int teamId, String teamNombre, List<CambiosVigente> rows})>[];
  for (final v in vigentes) {
    if (grupos.isEmpty || grupos.last.teamId != v.teamId) {
      grupos.add((teamId: v.teamId, teamNombre: v.teamNombre, rows: [v]));
    } else {
      grupos.last.rows.add(v);
    }
  }
  return grupos;
}

class _GroupedList extends StatelessWidget {
  final List<CambiosVigente> vigentes;
  const _GroupedList({required this.vigentes});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final grupos = agruparPorEquipo(vigentes);

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(16),
      children: [
        for (final g in grupos) ...[
          Padding(
            padding: const EdgeInsets.only(top: 8, bottom: 8),
            child: Text(
              g.teamNombre,
              key: Key('cambios_activos_equipo_${g.teamId}'),
              style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold),
            ),
          ),
          for (final row in g.rows) _VigenteCard(row: row),
        ],
      ],
    );
  }
}

class _VigenteCard extends StatelessWidget {
  final CambiosVigente row;
  const _VigenteCard({required this.row});

  @override
  Widget build(BuildContext context) {
    return Card(
      key: Key('cambios_activos_card_${row.plazaId}'),
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (row.esArco)
              const Padding(
                padding: EdgeInsets.only(bottom: 8),
                child: Chip(
                  label: Text('Arco'),
                  visualDensity: VisualDensity.compact,
                ),
              ),
            _Line(label: 'Baja', value: row.baja.etiqueta),
            _Line(label: 'Alta', value: row.alta.etiqueta),
            _Line(label: 'Desde', value: row.desdeLabel),
            _Line(label: 'Situación', value: row.situacion),
          ],
        ),
      ),
    );
  }
}

class _Line extends StatelessWidget {
  final String label;
  final String value;
  const _Line({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Text.rich(
        TextSpan(
          children: [
            TextSpan(
              text: '$label: ',
              style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.bold),
            ),
            TextSpan(text: value, style: theme.textTheme.bodyMedium),
          ],
        ),
      ),
    );
  }
}

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
              'No pudimos cargar los cambios activos. Revisá tu conexión y '
              'reintentá en unos minutos.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 16),
            FilledButton(onPressed: onRetry, child: const Text('Reintentar')),
          ],
        ),
      ),
    );
  }
}

class _EmptyView extends StatelessWidget {
  const _EmptyView();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    // Scrollable so pull-to-refresh also works on the empty state.
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        SizedBox(
          height: 360,
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.inbox_outlined, size: 64, color: theme.colorScheme.primary),
                  const SizedBox(height: 16),
                  Text(
                    'No hay cambios activos en este momento.',
                    style: theme.textTheme.titleMedium,
                    textAlign: TextAlign.center,
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }
}

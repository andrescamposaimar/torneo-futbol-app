import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../models/cambios_solicitud.dart';
import '../../providers/cambios_providers.dart';
import '../../services/cambios_solicitudes_controller.dart';
import '../../utils/puntaje_utils.dart';
import '../../widgets/entre_redes_app_bar.dart';
import '../../widgets/loading_seccion.dart';
import 'cambios_motivo_mensajes.dart';

/// "Mis Solicitudes" — every solicitud the captain's team has ever made, in
/// any estado, with when it was made/resolved and the committee's nota when
/// there is one. When a solicitud did not proceed, the dictamen's motivos are
/// mapped to plain Spanish sentences (see `cambios_motivo_mensajes.dart`) —
/// never the backend's raw rule codes.
class CambiosSolicitudesScreen extends ConsumerWidget {
  final int seasonId;
  final int teamId;

  const CambiosSolicitudesScreen({
    super.key,
    required this.seasonId,
    required this.teamId,
  });

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final scope = (seasonId: seasonId, teamId: teamId);
    final state = ref.watch(cambiosSolicitudesControllerProvider(scope));
    final notifier = ref.read(cambiosSolicitudesControllerProvider(scope).notifier);

    return Scaffold(
      appBar: const EntreRedesAppBar(title: 'Mis Solicitudes'),
      body: CambiosSolicitudesView(
        state: state,
        onRetry: () => notifier.load(seasonId: seasonId, teamId: teamId),
        onRefresh: () => notifier.refresh(seasonId: seasonId, teamId: teamId),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Presentational view (Riverpod-free)
// ---------------------------------------------------------------------------

class CambiosSolicitudesView extends StatelessWidget {
  final CambiosSolicitudesState state;
  final VoidCallback onRetry;
  final Future<void> Function() onRefresh;

  const CambiosSolicitudesView({
    super.key,
    required this.state,
    required this.onRetry,
    required this.onRefresh,
  });

  @override
  Widget build(BuildContext context) {
    return switch (state) {
      CambiosSolicitudesLoading() =>
        const LoadingSeccion(texto: 'Cargando tus pedidos...'),
      CambiosSolicitudesError() => _ErrorView(onRetry: onRetry),
      CambiosSolicitudesLoaded(:final solicitudes) => solicitudes.isEmpty
          ? const _EmptyView()
          : RefreshIndicator(
              onRefresh: onRefresh,
              child: ListView.builder(
                key: const Key('solicitudes_list'),
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.symmetric(vertical: 8),
                itemCount: solicitudes.length,
                itemBuilder: (context, i) => _SolicitudCard(solicitud: solicitudes[i]),
              ),
            ),
    };
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
              'No pudimos cargar tus pedidos. Revisá tu conexión y reintentá '
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
            Text('Todavía no hiciste ningún pedido',
                style: theme.textTheme.headlineSmall, textAlign: TextAlign.center),
            const SizedBox(height: 8),
            const Text(
              'Cuando pidas un cambio o un regreso desde "Mi Plantel", vas a '
              'poder seguirlo acá.',
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Solicitud card
// ---------------------------------------------------------------------------

class _SolicitudCard extends StatelessWidget {
  final CambiosSolicitud solicitud;
  const _SolicitudCard({required this.solicitud});

  Color _estadoColor(BuildContext context) {
    final theme = Theme.of(context);
    switch (solicitud.estado) {
      case CambiosSolicitudEstado.publicada:
      case CambiosSolicitudEstado.aprobada:
        return Colors.green.shade700;
      case CambiosSolicitudEstado.rechazada:
      case CambiosSolicitudEstado.anulada:
        return theme.colorScheme.error;
      case CambiosSolicitudEstado.pendiente:
      case CambiosSolicitudEstado.desconocido:
        return Colors.orange.shade800;
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dateFormat = DateFormat('dd/MM/yyyy HH:mm');
    final motivos = solicitud.dictamen.motivos;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      child: Card(
        key: Key('solicitud_card_${solicitud.id}'),
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
                  // Expanded + ellipsis — not an unbounded Text — because
                  // every tipo label used to be short enough ('Cambio',
                  // 'Regreso', 'Pedido') that this never mattered. The
                  // grouped tipo's own label ('Reasignación de arquero') is
                  // long enough to overflow this row at a narrow width,
                  // which this feature's own narrow-width test caught.
                  Expanded(
                    child: Text(
                      solicitud.tipo.label,
                      style: theme.textTheme.labelLarge?.copyWith(fontWeight: FontWeight.bold),
                      overflow: TextOverflow.ellipsis,
                      maxLines: 1,
                    ),
                  ),
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: _estadoColor(context).withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Text(
                      solicitud.estado.label,
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                        color: _estadoColor(context),
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 6),
              if (solicitud.solicitadaAt != null)
                Text(
                  'Pedido el ${dateFormat.format(solicitud.solicitadaAt!)}',
                  style: theme.textTheme.bodySmall?.copyWith(color: Colors.grey.shade600),
                ),
              if (solicitud.resueltaAt != null)
                Text(
                  'Resuelto el ${dateFormat.format(solicitud.resueltaAt!)}',
                  style: theme.textTheme.bodySmall?.copyWith(color: Colors.grey.shade600),
                ),
              const SizedBox(height: 6),
              if (solicitud.movimientos.isEmpty) ...[
                _LadoRow(etiqueta: 'Sale', lado: solicitud.sale),
                const SizedBox(height: 2),
                _LadoRow(etiqueta: 'Entra', lado: solicitud.entra),
              ] else ...[
                if (solicitud.movimientos.arco != null)
                  _MovimientoSeccion(
                    key: Key('movimiento_arco_${solicitud.id}'),
                    titulo: 'Arco',
                    movimiento: solicitud.movimientos.arco!,
                  ),
                if (solicitud.movimientos.campo != null) ...[
                  const SizedBox(height: 6),
                  _MovimientoSeccion(
                    key: Key('movimiento_campo_${solicitud.id}'),
                    titulo: 'Campo',
                    movimiento: solicitud.movimientos.campo!,
                  ),
                ],
              ],
              if (solicitud.nota != null && solicitud.nota!.isNotEmpty) ...[
                const SizedBox(height: 6),
                Text('Nota de la comisión: ${solicitud.nota}',
                    style: theme.textTheme.bodyMedium),
              ],
              if (!solicitud.dictamen.procede && motivos.isNotEmpty) ...[
                const SizedBox(height: 8),
                const Divider(height: 1),
                const SizedBox(height: 8),
                Text('Por qué no procedió:', style: theme.textTheme.labelMedium),
                const SizedBox(height: 4),
                ...motivos.map(
                  (m) => Padding(
                    padding: const EdgeInsets.only(bottom: 4),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Icon(Icons.circle, size: 6, color: Colors.grey.shade500),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            cambiosMotivoMensaje(m.codigo),
                            style: theme.textTheme.bodySmall,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Grouped (reasignacion_arquero) movement section
// ---------------------------------------------------------------------------

/// One movement heading ("Arco" or "Campo") + its own Sale/Entra pair, for a
/// grouped (`reasignacion_arquero`) solicitud — see
/// `CambiosSolicitudMovimientos`'s own docblock for why this replaces the
/// top-level `_LadoRow` pair for this tipo alone. Mirrors
/// `Admin\BandejaPage::renderFilaSolicitud()`'s own "Arco —.../Campo —..."
/// heading on the committee's own tray, so the captain sees the SAME two
/// movements the committee does.
class _MovimientoSeccion extends StatelessWidget {
  final String titulo;
  final CambiosSolicitudMovimiento movimiento;

  const _MovimientoSeccion({super.key, required this.titulo, required this.movimiento});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          titulo,
          style: theme.textTheme.labelSmall?.copyWith(fontWeight: FontWeight.bold),
        ),
        const SizedBox(height: 2),
        _LadoRow(etiqueta: 'Sale', lado: movimiento.sale),
        const SizedBox(height: 2),
        _LadoRow(etiqueta: 'Entra', lado: movimiento.entra),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Sale / Entra row
// ---------------------------------------------------------------------------

/// Renders one side ("Sale" or "Entra") of a solicitud's player pair —
/// `<etiqueta>: <Apellido, Nombre> [<puntaje>]`. See
/// `CambiosSolicitudLado`'s own docblock for what each field means and when
/// it degrades.
///
/// *** NEVER `[]` OR `[-]` *** `formatearPuntaje()` already collapses an
/// unknown puntaje to `'-'`, but that sentinel belongs INSIDE a bracket pair
/// everywhere else this app renders a puntaje (see `cambios_solicitar_screen
/// .dart`'s own `_PlazaHeader`) — never here: a `null` puntaje omits the
/// bracket entirely rather than printing an empty or placeholder pair, so a
/// reader never mistakes "we don't know" for "rated at the sentinel".
///
/// *** OVERFLOW ***
/// `Row` + `Expanded(Text(..., overflow: ellipsis, maxLines: 1))` for the
/// name, with the fixed-width puntaje chip OUTSIDE the `Expanded` — the same
/// shape `cambios_solicitar_screen.dart`'s own `_PlazaHeader` uses, chosen
/// for the same reason: an unbounded `Text` inside a `Row` with no
/// `Expanded` overflows the moment a name is long enough, and this screen is
/// exactly as narrow as that one.
class _LadoRow extends StatelessWidget {
  final String etiqueta;
  final CambiosSolicitudLado lado;

  const _LadoRow({required this.etiqueta, required this.lado});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final estilo = theme.textTheme.bodyMedium;

    if (lado.playerId == null) {
      return Text(
        '$etiqueta: Sin registrar',
        style: estilo?.copyWith(color: Colors.grey.shade600, fontStyle: FontStyle.italic),
      );
    }

    final puntajeTexto = formatearPuntaje(lado.puntaje);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        Text('$etiqueta: ', style: estilo?.copyWith(fontWeight: FontWeight.w600)),
        Expanded(
          child: Text(
            lado.nombre ?? 'Jugador #${lado.playerId}',
            style: estilo,
            overflow: TextOverflow.ellipsis,
            maxLines: 1,
          ),
        ),
        // formatearPuntaje() already collapses an unknown puntaje to '-' —
        // that sentinel is deliberately NOT shown here (see class docblock):
        // no brackets at all when the puntaje could not be resolved.
        if (puntajeTexto != '-')
          Padding(
            padding: const EdgeInsets.only(left: 4),
            child: Text('[$puntajeTexto]', style: estilo),
          ),
      ],
    );
  }
}

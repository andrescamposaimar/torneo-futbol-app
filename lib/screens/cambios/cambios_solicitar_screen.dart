import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../models/cambios_plaza.dart';
import '../../models/cambios_solicitud.dart';
import '../../providers/cambios_providers.dart';
import '../../services/cambios_api_service.dart';
import '../../services/cambios_candidatos_controller.dart';
import '../../widgets/entre_redes_app_bar.dart';
import '../../widgets/loading_seccion.dart';

/// "Pedir cambio" — for one [plaza], either:
///   - [CambiosSolicitudTipo.sustitucion]: a searchable list of viable
///     candidates, pick one, confirm; or
///   - [CambiosSolicitudTipo.regreso]: a direct confirm (no candidate list —
///     "who returns is never a choice this request makes", see
///     `Dictamen\SolicitudDeCambio`'s own docblock).
///
/// On success, pops back to "Mi Plantel" WITHOUT a Navigator result — the
/// caller refreshes `cambiosPlantelControllerProvider` for this team/season
/// so the roster reflects the change through shared Riverpod state, exactly
/// like `_PredictionSheet` never threads a result back through `pop()`.
///
/// *** [fechaId] — A CONFIRMED BACKEND GAP, NOT A TODO ***
/// `POST /cambios/solicitudes` requires a `fecha_id` (the season fecha this
/// request targets — see `Dictamen\Reglas\SolicitudEnPlazo`, which uses it
/// to look up that fecha's Tue/Thu/Fri deadline window). No endpoint in
/// `wordpress_plugins/entre-redes-cambios/src/Rest/` exposes the season's
/// fechas to a client — `Calendario\FechaRepository::listBySeason()` exists
/// server-side but is never registered as a route. There is therefore no
/// value this app can honestly send here. Every call site in this app passes
/// `fechaId: null`; when null, this screen disables submission and shows
/// [_FechaGapBanner] instead of guessing an id the backend would either
/// reject (`fecha_id <= 0` → 400) or, worse, silently accept against the
/// wrong week. Once a fechas-listing endpoint exists, wire the real id
/// through this same parameter — the rest of the submit plumbing (search,
/// selection, POST, error handling, shared-state refresh) is already correct
/// and already tested end-to-end via a non-null [fechaId] in
/// `cambios_solicitar_screen_test.dart`.
class CambiosSolicitarScreen extends ConsumerStatefulWidget {
  final int seasonId;
  final int teamId;
  final CambiosPlaza plaza;
  final CambiosSolicitudTipo tipo;
  final int? fechaId;

  const CambiosSolicitarScreen({
    super.key,
    required this.seasonId,
    required this.teamId,
    required this.plaza,
    required this.tipo,
    this.fechaId,
  });

  @override
  ConsumerState<CambiosSolicitarScreen> createState() =>
      _CambiosSolicitarScreenState();
}

class _CambiosSolicitarScreenState extends ConsumerState<CambiosSolicitarScreen> {
  final _searchController = TextEditingController();
  Timer? _debounce;
  int? _selectedPlayerId;
  bool _submitting = false;
  String? _error;

  CambiosCandidatosParams get _candidatosParams => (
        seasonId: widget.seasonId,
        teamId: widget.teamId,
        plazaId: widget.plaza.plazaId,
      );

  @override
  void dispose() {
    _debounce?.cancel();
    _searchController.dispose();
    super.dispose();
  }

  void _onSearchChanged(String query) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () {
      ref.read(cambiosCandidatosControllerProvider(_candidatosParams).notifier).load(query: query);
    });
  }

  Future<void> _onConfirmar() async {
    if (_submitting) return;
    if (widget.tipo == CambiosSolicitudTipo.sustitucion && _selectedPlayerId == null) {
      return;
    }

    // See this class's own docblock, "[fechaId] — A CONFIRMED BACKEND GAP".
    final fechaId = widget.fechaId;
    if (fechaId == null) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      await ref.read(cambiosApiServiceProvider).crearSolicitud(
            seasonId: widget.seasonId,
            teamId: widget.teamId,
            plazaId: widget.plaza.plazaId,
            tipo: widget.tipo,
            fechaId: fechaId,
            entrantePlayerId: widget.tipo == CambiosSolicitudTipo.sustitucion
                ? _selectedPlayerId
                : null,
          );

      if (!mounted) return;
      // Shared Riverpod state refresh — the roster behind this screen
      // re-renders because it watches the same family instance.
      ref
          .read(cambiosPlantelControllerProvider(
            (seasonId: widget.seasonId, teamId: widget.teamId),
          ).notifier)
          .refresh(seasonId: widget.seasonId, teamId: widget.teamId);
      Navigator.of(context).pop();
    } on CambiosApiException {
      if (!mounted) return;
      setState(() {
        _submitting = false;
        _error = 'No se pudo enviar el pedido. Probá de nuevo en unos minutos.';
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _submitting = false;
        _error = 'No se pudo enviar el pedido. Probá de nuevo en unos minutos.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final isSustitucion = widget.tipo == CambiosSolicitudTipo.sustitucion;
    final fechaId = widget.fechaId;
    final canSubmit = !_submitting &&
        fechaId != null &&
        (!isSustitucion || _selectedPlayerId != null);

    return Scaffold(
      appBar: EntreRedesAppBar(
        title: isSustitucion ? 'Pedir cambio' : 'Pedir regreso',
      ),
      body: Column(
        children: [
          _PlazaHeader(plaza: widget.plaza),
          if (fechaId == null) const _FechaGapBanner(),
          if (isSustitucion) ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
              child: TextField(
                key: const Key('candidato_search_field'),
                controller: _searchController,
                onChanged: _onSearchChanged,
                decoration: const InputDecoration(
                  labelText: 'Buscar jugador',
                  prefixIcon: Icon(Icons.search),
                  border: OutlineInputBorder(),
                ),
              ),
            ),
            Expanded(
              child: Consumer(
                builder: (context, ref, _) {
                  final state = ref.watch(cambiosCandidatosControllerProvider(_candidatosParams));
                  return switch (state) {
                    CambiosCandidatosLoading() =>
                      const LoadingSeccion(texto: 'Buscando candidatos...'),
                    CambiosCandidatosError() => _CandidatosErrorView(
                        onRetry: () => ref
                            .read(cambiosCandidatosControllerProvider(_candidatosParams).notifier)
                            .load(query: _searchController.text),
                      ),
                    CambiosCandidatosLoaded(:final candidatos) => candidatos.isEmpty
                        ? const _CandidatosEmptyView()
                        : ListView.builder(
                            key: const Key('candidatos_list'),
                            itemCount: candidatos.length,
                            itemBuilder: (context, i) {
                              final c = candidatos[i];
                              final isSelected = _selectedPlayerId == c.playerId;
                              return ListTile(
                                key: Key('candidato_${c.playerId}'),
                                selected: isSelected,
                                onTap: () => setState(() => _selectedPlayerId = c.playerId),
                                trailing: isSelected
                                    ? Icon(Icons.check_circle,
                                        color: Theme.of(context).colorScheme.primary)
                                    : const Icon(Icons.radio_button_unchecked),
                                title: Text(c.nombre),
                                subtitle: Text(
                                  [
                                    if (c.esPadre) 'Padre',
                                    c.puntaje != null
                                        ? 'Puntaje: ${c.puntaje}'
                                        : 'Puntaje: sin datos',
                                  ].join(' · '),
                                ),
                              );
                            },
                          ),
                  };
                },
              ),
            ),
          ] else
            Expanded(
              child: Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Text(
                    '¿Confirmás pedir el regreso de ${widget.plaza.titularNombre} '
                    'a esta plaza?',
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.bodyLarge,
                  ),
                ),
              ),
            ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: Text(
                _error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error, fontSize: 13),
                textAlign: TextAlign.center,
              ),
            ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
            child: SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                key: const Key('confirmar_solicitud_button'),
                onPressed: canSubmit ? _onConfirmar : null,
                child: _submitting
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('Confirmar'),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Header + auxiliary views
// ---------------------------------------------------------------------------

class _PlazaHeader extends StatelessWidget {
  final CambiosPlaza plaza;
  const _PlazaHeader({required this.plaza});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      color: theme.colorScheme.primary.withValues(alpha: 0.06),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(plaza.tipoLabel,
              style: theme.textTheme.labelLarge
                  ?.copyWith(color: theme.colorScheme.primary, fontWeight: FontWeight.bold)),
          const SizedBox(height: 4),
          Text('Titular: ${plaza.titularNombre}', style: theme.textTheme.bodyMedium),
        ],
      ),
    );
  }
}

class _CandidatosErrorView extends StatelessWidget {
  final VoidCallback onRetry;
  const _CandidatosErrorView({required this.onRetry});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'No pudimos cargar los candidatos. Revisá tu conexión y '
              'reintentá en unos minutos.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 12),
            ElevatedButton(onPressed: onRetry, child: const Text('Reintentar')),
          ],
        ),
      ),
    );
  }
}

class _CandidatosEmptyView extends StatelessWidget {
  const _CandidatosEmptyView();

  @override
  Widget build(BuildContext context) {
    return const Center(
      child: Padding(
        padding: EdgeInsets.all(24),
        child: Text(
          'No encontramos candidatos disponibles para esta plaza.',
          textAlign: TextAlign.center,
        ),
      ),
    );
  }
}

class _FechaGapBanner extends StatelessWidget {
  const _FechaGapBanner();

  @override
  Widget build(BuildContext context) {
    return MaterialBanner(
      key: const Key('fecha_gap_banner'),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      leading: const Icon(Icons.info_outline, color: Colors.orange),
      backgroundColor: Colors.amber.shade100,
      content: const Text(
        'Todavía no podemos enviar pedidos desde la app: falta terminar una '
        'parte del sistema. Probá de nuevo más adelante.',
      ),
      actions: const [SizedBox.shrink()],
    );
  }
}

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../models/cambios_fecha_abierta.dart';
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
/// *** WHERE `fechaId` COMES FROM ***
/// `POST /cambios/solicitudes` requires a `fecha_id` (the season fecha this
/// request targets — see `Dictamen\Reglas\SolicitudEnPlazo`, which uses it
/// to look up that fecha's Tue/Thu/Fri deadline window). This screen fetches
/// it itself via [cambiosFechaAbiertaProvider] (`GET /cambios/fecha-abierta`)
/// rather than taking it as a constructor parameter — there is no honest
/// value a caller could pass in advance, and the fetch also carries
/// `ventanas`, which the screen needs anyway (see below). While the fetch is
/// in flight, or when it resolves to `null` (the season has no unresolved
/// fecha right now) or fails, submission stays disabled behind
/// [_FechaGapBanner] — never a guess.
///
/// *** WHY THE WINDOW CHECK HAPPENS HERE, NOT ONLY ON THE BACKEND ***
/// `Dictamen\Reglas\SolicitudEnPlazo` already rejects a solicitud submitted
/// past its deadline — but that is a REJECTION AFTER SUBMITTING, and a
/// captain who picked a candidate, waited for the search, and confirmed
/// deserves to learn the window already closed BEFORE doing any of that.
/// [CambiosFechaAbierta.ventanaAbiertaPara] answers exactly the question this
/// screen's own tipo cares about — `regreso_abierta` for
/// [CambiosSolicitudTipo.regreso], `sustitucion_abierta` for
/// [CambiosSolicitudTipo.sustitucion] — and [_VentanaCerradaBanner] shows
/// that BEFORE the candidate list or the confirm button ever becomes usable.
class CambiosSolicitarScreen extends ConsumerStatefulWidget {
  final int seasonId;
  final int teamId;
  final CambiosPlaza plaza;
  final CambiosSolicitudTipo tipo;

  const CambiosSolicitarScreen({
    super.key,
    required this.seasonId,
    required this.teamId,
    required this.plaza,
    required this.tipo,
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

    // See this class's own docblock, "WHERE `fechaId` COMES FROM". `read`,
    // not `watch` — this is a one-shot action, not something that should
    // re-run this method on every rebuild.
    final fecha = ref.read(cambiosFechaAbiertaProvider(widget.seasonId)).valueOrNull;
    if (fecha == null) return;
    final isSustitucion = widget.tipo == CambiosSolicitudTipo.sustitucion;
    if (!fecha.ventanaAbiertaPara(esSustitucion: isSustitucion)) return;
    final fechaId = fecha.fechaId;

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
    final fechaAsync = ref.watch(cambiosFechaAbiertaProvider(widget.seasonId));
    final fecha = fechaAsync.valueOrNull;
    final fechaId = fecha?.fechaId;
    final ventanaAbierta = fecha != null && fecha.ventanaAbiertaPara(esSustitucion: isSustitucion);
    final canSubmit = !_submitting &&
        fechaId != null &&
        ventanaAbierta &&
        (!isSustitucion || _selectedPlayerId != null);

    return Scaffold(
      appBar: EntreRedesAppBar(
        title: isSustitucion ? 'Pedir cambio' : 'Pedir regreso',
      ),
      body: Column(
        children: [
          _PlazaHeader(plaza: widget.plaza),
          if (fechaAsync.isLoading) const _FechaLoadingBanner(),
          if (!fechaAsync.isLoading && fecha == null) const _FechaGapBanner(),
          if (fecha != null && !ventanaAbierta)
            _VentanaCerradaBanner(esSustitucion: isSustitucion),
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
              child: _CandidatosList(
                params: _candidatosParams,
                searchQuery: _searchController.text,
                selectedPlayerId: _selectedPlayerId,
                onSelect: (playerId) => setState(() => _selectedPlayerId = playerId),
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

/// The candidate list for a `sustitucion` — loading/error/empty/loaded states
/// of [cambiosCandidatosControllerProvider], plus per-row selection state and
/// the trailing icon/subtitle. Its own widget (rather than inline in
/// [CambiosSolicitarScreen]'s `Column`) for the same reason every other
/// state in this file already got one: [_PlazaHeader], [_FechaGapBanner],
/// [_VentanaCerradaBanner], [_CandidatosErrorView], [_CandidatosEmptyView].
class _CandidatosList extends ConsumerWidget {
  final CambiosCandidatosParams params;
  final String searchQuery;
  final int? selectedPlayerId;
  final ValueChanged<int> onSelect;

  const _CandidatosList({
    required this.params,
    required this.searchQuery,
    required this.selectedPlayerId,
    required this.onSelect,
  });

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(cambiosCandidatosControllerProvider(params));

    return switch (state) {
      CambiosCandidatosLoading() => const LoadingSeccion(texto: 'Buscando candidatos...'),
      CambiosCandidatosError() => _CandidatosErrorView(
          onRetry: () =>
              ref.read(cambiosCandidatosControllerProvider(params).notifier).load(query: searchQuery),
        ),
      CambiosCandidatosLoaded(:final candidatos) => candidatos.isEmpty
          ? const _CandidatosEmptyView()
          : ListView.builder(
              key: const Key('candidatos_list'),
              itemCount: candidatos.length,
              itemBuilder: (context, i) {
                final c = candidatos[i];
                final isSelected = selectedPlayerId == c.playerId;
                return ListTile(
                  key: Key('candidato_${c.playerId}'),
                  selected: isSelected,
                  onTap: () => onSelect(c.playerId),
                  trailing: isSelected
                      ? Icon(Icons.check_circle, color: Theme.of(context).colorScheme.primary)
                      : const Icon(Icons.radio_button_unchecked),
                  title: Text(c.nombre),
                  subtitle: Text(
                    [
                      if (c.esPadre) 'Padre',
                      c.puntaje != null ? 'Puntaje: ${c.puntaje}' : 'Puntaje: sin datos',
                    ].join(' · '),
                  ),
                );
              },
            ),
    };
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

/// Shown when [cambiosFechaAbiertaProvider] resolved to `null` (the season
/// has no unresolved fecha right now) OR the fetch itself failed — both
/// cases fall back to the SAME honest, generic banner: there is no fecha to
/// request a change against, so submission stays disabled. See
/// `CambiosSolicitarScreen`'s own docblock, "WHERE `fechaId` COMES FROM".
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
        'No hay una fecha abierta para pedidos en este momento. '
        'Probá de nuevo más adelante.',
      ),
      actions: const [SizedBox.shrink()],
    );
  }
}

/// Shown briefly while [cambiosFechaAbiertaProvider] is still resolving —
/// keeps the confirm button disabled without prematurely claiming there is
/// no open fecha.
class _FechaLoadingBanner extends StatelessWidget {
  const _FechaLoadingBanner();

  @override
  Widget build(BuildContext context) {
    return MaterialBanner(
      key: const Key('fecha_loading_banner'),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      leading: const SizedBox(
        height: 16,
        width: 16,
        child: CircularProgressIndicator(strokeWidth: 2),
      ),
      content: const Text('Buscando la fecha para este pedido...'),
      actions: const [SizedBox.shrink()],
    );
  }
}

/// Shown when there IS an open fecha, but the deadline window for THIS
/// tipo of request (regreso or sustitucion) has already closed — see
/// `CambiosSolicitarScreen`'s own docblock, "WHY THE WINDOW CHECK HAPPENS
/// HERE, NOT ONLY ON THE BACKEND": a captain must learn this before picking
/// a candidate, never from a rejected submit.
class _VentanaCerradaBanner extends StatelessWidget {
  final bool esSustitucion;
  const _VentanaCerradaBanner({required this.esSustitucion});

  @override
  Widget build(BuildContext context) {
    return MaterialBanner(
      key: const Key('ventana_cerrada_banner'),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      leading: const Icon(Icons.lock_clock_outlined, color: Colors.orange),
      backgroundColor: Colors.amber.shade100,
      content: Text(
        esSustitucion
            ? 'El plazo para pedir un cambio en esta fecha ya cerró.'
            : 'El plazo para pedir un regreso en esta fecha ya cerró.',
      ),
      actions: const [SizedBox.shrink()],
    );
  }
}

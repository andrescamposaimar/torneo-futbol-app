import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../models/cambios_candidato.dart';
import '../../models/cambios_fecha_abierta.dart';
import '../../models/cambios_plaza.dart';
import '../../models/cambios_solicitud.dart';
import '../../providers/cambios_providers.dart';
import '../../services/cambios_api_service.dart';
import '../../services/cambios_candidatos_controller.dart';
import '../../services/player_filter_service.dart';
import '../../utils/date_utils.dart';
import '../../widgets/cambios_candidato_card.dart';
import '../../widgets/entre_redes_app_bar.dart';
import '../../widgets/loading_seccion.dart';
import '../../widgets/prode_segmented_toggle.dart';

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
/// *** THE TWO CANDIDATE SECTIONS ***
/// A `sustitucion` candidate step has TWO populations the captain chooses
/// between via a segmented toggle — [CambiosCandidatosSeccion.listaEspera]
/// (who actually signed up to come in as a cambio) and
/// [CambiosCandidatosSeccion.padronCompleto] (everyone else in the whole
/// padrón, widened on purpose — see that enum's own docblock). Each section
/// is backed by its OWN [cambiosCandidatosControllerProvider] instance
/// (keyed by `seccion`, see `cambios_providers.dart`): `listaEspera` loads
/// the instant this screen opens (the common case, one request), while
/// `padronCompleto` stays [CambiosCandidatosIdle] — fetching NOTHING — until
/// [_onSeccionChanged] asks for it the first time the captain switches to
/// it. Neither section ever computes its OWN viability or puntaje-ceiling
/// logic: both render exactly what the backend returned, nothing more (see
/// `Rest\PlazasController::listarCandidatos()`'s own docblock on the
/// backend, "FILTERING HAPPENS HERE, NEVER IN CandidatosResolver" — the
/// mobile client follows the identical discipline for the same reason: a
/// screen that disagrees with the dictamen engine is worse than no screen
/// at all).
///
/// *** SEARCH + PUNTAJE CHIPS ARE LOCAL, NOT A SECOND SERVER ROUND TRIP ***
/// Once a section has loaded, typing in the search field or toggling a
/// puntaje chip narrows the ALREADY-fetched list client-side, via
/// [PlayerFilterService.filtrar] — see `_candidatosFiltrados()`. This is
/// what keeps "the common case must cost one request" true even while the
/// captain is actively narrowing a long "Padrón Completo" list down.
///
/// *** THE PUNTAJE CHIPS TEACH THE CEILING, THEY NEVER HIDE IT ***
/// Every one of the 9 valid puntajes is always shown — the ones ABOVE
/// `plaza.puntajeTecho` render disabled, visibly greyed, and noticeably
/// BIGGER than the enabled ones (the process owner's own request: a captain
/// hits this constraint constantly, so the screen should make it impossible
/// to miss, not hide the excluded values). This is a pure presentation
/// choice over the puntaje VALUE already known client-side
/// (`plaza.puntajeTecho`, itself only ever DISPLAYED, never computed) — it
/// changes nothing about which candidates the backend already decided are
/// viable.
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
/// [CambiosSolicitudTipo.sustitucion] — and [_VentanaEstadoBanner] shows
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

/// Every valid puntaje the reglamento recognizes — same 9 discrete values
/// `players_screen.dart`'s own puntaje filter uses (`Plazas\Puntaje` on the
/// backend). Kept as its own small literal here rather than imported from
/// that screen: `players_screen.dart` is explicitly out of scope for this
/// change (see this slice's own task brief), and this is the only other
/// place in the app that needs the same list.
const List<double> _valoresPuntaje = [5, 4.5, 4, 3.5, 3, 2.5, 2, 1.5, 1];

class _CambiosSolicitarScreenState extends ConsumerState<CambiosSolicitarScreen> {
  final _searchController = TextEditingController();
  int? _selectedPlayerId;
  bool _submitting = false;
  String? _error;

  CambiosCandidatosSeccion _seccion = CambiosCandidatosSeccion.listaEspera;
  String _searchQuery = '';
  final List<double> _puntajesFiltro = [];

  CambiosCandidatosParams _paramsFor(CambiosCandidatosSeccion seccion) => (
        seasonId: widget.seasonId,
        teamId: widget.teamId,
        plazaId: widget.plaza.plazaId,
        seccion: seccion,
      );

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  void _onSearchChanged(String query) {
    setState(() => _searchQuery = query);
  }

  void _onPuntajeToggled(double valor) {
    setState(() {
      if (_puntajesFiltro.contains(valor)) {
        _puntajesFiltro.remove(valor);
      } else {
        _puntajesFiltro.add(valor);
      }
    });
  }

  /// Switches the visible section and, the FIRST time `padronCompleto` is
  /// opened, triggers its (until-now-idle) fetch — see this class's own
  /// docblock, "THE TWO CANDIDATE SECTIONS". Switching back and forth
  /// afterward never re-fetches: once a section's controller has left
  /// [CambiosCandidatosIdle], this is a no-op beyond the local [setState].
  ///
  /// Reading (never watching) `cambiosCandidatosControllerProvider` here is
  /// safe against Riverpod's `autoDispose` teardown ONLY because [build]
  /// below `watch`es BOTH sections' providers UNCONDITIONALLY, for as long
  /// as this screen is mounted — see that method's own comment. Without
  /// that, switching sections would momentarily leave the just-selected
  /// section's provider with zero watchers (between this `setState` and the
  /// next frame's rebuild), and `autoDispose` would tear it down right as
  /// this method tries to use it.
  void _onSeccionChanged(CambiosCandidatosSeccion seccion) {
    setState(() => _seccion = seccion);

    final params = _paramsFor(seccion);
    final current = ref.read(cambiosCandidatosControllerProvider(params));
    if (current is CambiosCandidatosIdle) {
      ref.read(cambiosCandidatosControllerProvider(params).notifier).load();
    }
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

    // Both sections' providers are `watch`ed HERE, UNCONDITIONALLY, for as
    // long as this screen is built — never only the currently visible one.
    // This is what keeps `autoDispose` from tearing either controller down
    // the instant the OTHER section becomes the one actually rendered (see
    // `_onSeccionChanged`'s own docblock for the race this avoids). Only
    // the state matching `_seccion` is ever handed to `_CandidatosList`
    // below — the other one is kept alive, never displayed.
    final listaEsperaState =
        ref.watch(cambiosCandidatosControllerProvider(_paramsFor(CambiosCandidatosSeccion.listaEspera)));
    final padronCompletoState =
        ref.watch(cambiosCandidatosControllerProvider(_paramsFor(CambiosCandidatosSeccion.padronCompleto)));
    final candidatosState =
        _seccion == CambiosCandidatosSeccion.listaEspera ? listaEsperaState : padronCompletoState;

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
            _VentanaEstadoBanner(
              esSustitucion: isSustitucion,
              fase: fecha.faseFor(esSustitucion: isSustitucion),
              aperturaSolicitudesUtc: fecha.aperturaSolicitudesUtc,
            ),
          if (isSustitucion) ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
              child: ProdeSegmentedToggle(
                labels: const ['Lista de Espera', 'Padrón Completo'],
                selectedIndex: _seccion == CambiosCandidatosSeccion.listaEspera ? 0 : 1,
                onChanged: (i) => _onSeccionChanged(
                  i == 0 ? CambiosCandidatosSeccion.listaEspera : CambiosCandidatosSeccion.padronCompleto,
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
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
            _PuntajeChips(
              techo: widget.plaza.puntajeTecho,
              seleccionados: _puntajesFiltro,
              onToggle: _onPuntajeToggled,
            ),
            Expanded(
              child: _CandidatosList(
                state: candidatosState,
                onRetry: () =>
                    ref.read(cambiosCandidatosControllerProvider(_paramsFor(_seccion)).notifier).load(),
                searchQuery: _searchQuery,
                puntajesFiltro: _puntajesFiltro,
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
          Text(plaza.titularNombre,
              style: theme.textTheme.labelLarge
                  ?.copyWith(color: theme.colorScheme.primary, fontWeight: FontWeight.bold)),
        ],
      ),
    );
  }
}

/// Every valid puntaje, as a chip — enabled ones toggle a LOCAL filter over
/// the already-loaded candidate list; the ones ABOVE [techo] render
/// disabled, greyed, and noticeably bigger — see
/// `CambiosSolicitarScreen`'s own docblock, "THE PUNTAJE CHIPS TEACH THE
/// CEILING, THEY NEVER HIDE IT".
class _PuntajeChips extends StatelessWidget {
  final double techo;
  final List<double> seleccionados;
  final ValueChanged<double> onToggle;

  const _PuntajeChips({
    required this.techo,
    required this.seleccionados,
    required this.onToggle,
  });

  /// Half-point integer comparison — avoids a direct `double >` on values
  /// that are each exact halves (1, 1.5, 2, ... 5) but still originate from
  /// two independent sources (this literal list vs. a JSON-decoded
  /// `puntaje_techo`), mirroring `Plazas\Puntaje`'s own ×2 encoding on the
  /// backend (see that class's docblock for why a raw float compare is the
  /// wrong tool for this exact kind of boundary check).
  bool _excedeTecho(double valor) => (valor * 2).round() > (techo * 2).round();

  @override
  Widget build(BuildContext context) {
    final label = techo == techo.truncateToDouble() ? techo.toInt().toString() : techo.toString();

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Techo de esta plaza: $label pts.',
            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Colors.black54),
          ),
          const SizedBox(height: 6),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: _valoresPuntaje.map((valor) {
                final disabled = _excedeTecho(valor);
                final isSelected = !disabled && seleccionados.contains(valor);
                final texto = valor == valor.truncateToDouble()
                    ? valor.toInt().toString()
                    : valor.toString();

                return Padding(
                  padding: const EdgeInsets.only(right: 6),
                  child: GestureDetector(
                    key: Key('puntaje_chip_$valor'),
                    onTap: disabled ? null : () => onToggle(valor),
                    child: AnimatedContainer(
                      duration: const Duration(milliseconds: 150),
                      padding: EdgeInsets.symmetric(
                        horizontal: disabled ? 16 : 12,
                        vertical: disabled ? 10 : 6,
                      ),
                      decoration: BoxDecoration(
                        color: disabled
                            ? Colors.grey.shade200
                            : (isSelected ? Theme.of(context).colorScheme.primary : Colors.grey[200]),
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Text(
                        texto,
                        style: TextStyle(
                          fontSize: disabled ? 16 : 13,
                          fontWeight: FontWeight.w600,
                          color: disabled
                              ? Colors.grey.shade400
                              : (isSelected ? Colors.white : Colors.black87),
                        ),
                      ),
                    ),
                  ),
                );
              }).toList(),
            ),
          ),
        ],
      ),
    );
  }
}

/// Narrows [candidatos] by [query] and [puntajes] via
/// [PlayerFilterService.filtrar] (reused, not reimplemented — see
/// `CambiosSolicitarScreen`'s own docblock) and sorts by puntaje descending
/// via [PlayerFilterService.comparadorPuntaje]. Both operate on a plain
/// `Map` shape (`title.rendered` / `metrics.puntaje`) because that service
/// is shared with `players_screen.dart`'s raw WordPress jugador maps — the
/// thin wrapper here (and the `_candidato` back-reference) is the ADAPTER
/// that lets a typed [CambiosCandidato] go through that SAME logic without
/// reimplementing its string/puntaje matching.
List<CambiosCandidato> _candidatosFiltrados(
  List<CambiosCandidato> candidatos, {
  required String query,
  required List<double> puntajes,
}) {
  final envueltos = candidatos
      .map((c) => {
            'title': {'rendered': c.nombre},
            'metrics': {'puntaje': c.puntaje},
            '_candidato': c,
          })
      .toList();

  final filtrados = PlayerFilterService.filtrar(
    envueltos,
    query: query,
    puntajes: puntajes,
  )..sort(PlayerFilterService.comparadorPuntaje);

  return filtrados.map((m) => (m as Map)['_candidato'] as CambiosCandidato).toList(growable: false);
}

/// The candidate list for a `sustitucion` — loading/error/empty/loaded views
/// for ONE section's already-resolved [state] (the caller, [CambiosSolicitarScreen],
/// `watch`es BOTH sections' providers itself and hands down only the
/// currently selected one's state — see that class's own `build()` comment
/// for why this widget never watches the provider itself), plus per-row
/// selection state and the trailing icon/subtitle. Its own widget (rather
/// than inline in [CambiosSolicitarScreen]'s `Column`) for the same reason
/// every other state in this file already got one: [_PlazaHeader],
/// [_FechaGapBanner], [_VentanaEstadoBanner], [_CandidatosErrorView],
/// [_CandidatosEmptyView].
class _CandidatosList extends StatelessWidget {
  final CambiosCandidatosState state;
  final VoidCallback onRetry;
  final String searchQuery;
  final List<double> puntajesFiltro;
  final int? selectedPlayerId;
  final ValueChanged<int> onSelect;

  const _CandidatosList({
    required this.state,
    required this.onRetry,
    required this.searchQuery,
    required this.puntajesFiltro,
    required this.selectedPlayerId,
    required this.onSelect,
  });

  @override
  Widget build(BuildContext context) {
    return switch (state) {
      CambiosCandidatosIdle() => const _CandidatosIdleView(),
      CambiosCandidatosLoading() => const LoadingSeccion(texto: 'Buscando candidatos...'),
      CambiosCandidatosError() => _CandidatosErrorView(onRetry: onRetry),
      CambiosCandidatosLoaded(:final candidatos) => _buildLoaded(context, candidatos),
    };
  }

  Widget _buildLoaded(BuildContext context, List<CambiosCandidato> candidatos) {
    final filtrados = _candidatosFiltrados(
      candidatos,
      query: searchQuery,
      puntajes: puntajesFiltro,
    );

    if (filtrados.isEmpty) {
      return const _CandidatosEmptyView();
    }

    return ListView.builder(
      key: const Key('candidatos_list'),
      itemCount: filtrados.length,
      itemBuilder: (context, i) {
        final c = filtrados[i];
        return CambiosCandidatoCard(
          key: Key('candidato_${c.playerId}'),
          playerId: c.playerId,
          nombre: c.nombre,
          esPadre: c.esPadre,
          puntaje: c.puntaje,
          selected: selectedPlayerId == c.playerId,
          onTap: () => onSelect(c.playerId),
        );
      },
    );
  }
}

/// Shown for the LAZY section ("Padrón Completo") before it has ever been
/// opened — distinct from [LoadingSeccion] (which implies a fetch is
/// already in flight): this is "nothing requested yet", not "waiting on the
/// network". Should only ever be visible for one frame in practice —
/// `_onSeccionChanged` requests the load in the SAME event that makes this
/// section visible — but it is still the honest state to render if that
/// ever briefly shows.
class _CandidatosIdleView extends StatelessWidget {
  const _CandidatosIdleView();

  @override
  Widget build(BuildContext context) {
    return const LoadingSeccion(texto: 'Buscando candidatos...');
  }
}

class _CandidatosErrorView extends StatelessWidget {
  final VoidCallback onRetry;
  const _CandidatosErrorView({required this.onRetry});

  @override
  Widget build(BuildContext context) {
    // SingleChildScrollView, not a bare Center/Column: this view now sits
    // BELOW the segmented toggle + puntaje chips row this slice added, which
    // leaves less vertical room than before — a narrow viewport (or this
    // suite's default test window) can otherwise overflow a fixed Column.
    return SingleChildScrollView(
      child: Center(
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
/// tipo of request (regreso or sustitucion) is not currently open — either
/// [CambiosVentanaFase.antes] (it has not opened yet, so this banner also
/// tells the captain WHEN) or [CambiosVentanaFase.cerrada] (its own deadline
/// already passed). See `CambiosFechaAbierta`'s own docblock, "THREE
/// STATES, NOT TWO", for why a plain boolean could not tell these two apart
/// — both used to read as the SAME "closed" banner, even though "come back
/// Sunday" and "this fecha is done" are very different things to tell a
/// captain. See also `CambiosSolicitarScreen`'s own docblock, "WHY THE
/// WINDOW CHECK HAPPENS HERE, NOT ONLY ON THE BACKEND": a captain must learn
/// this before picking a candidate, never from a rejected submit.
///
/// Renamed from `_VentanaCerradaBanner` (one state, one message) to
/// `_VentanaEstadoBanner` (two possible non-open states, two messages) when
/// this slice added the `antes` phase.
class _VentanaEstadoBanner extends StatelessWidget {
  final bool esSustitucion;
  final CambiosVentanaFase fase;
  final DateTime? aperturaSolicitudesUtc;

  const _VentanaEstadoBanner({
    required this.esSustitucion,
    required this.fase,
    required this.aperturaSolicitudesUtc,
  });

  /// "a cambio" / "un cambio" vs. "a regreso" / "un regreso" — the SAME
  /// `esSustitucion`-keyed vocabulary the `cerrada` copy below already used,
  /// kept consistent rather than introducing a third phrasing.
  String get _accion => esSustitucion ? 'un cambio' : 'un regreso';

  String get _mensaje {
    if (fase == CambiosVentanaFase.antes) {
      final apertura = aperturaSolicitudesUtc;
      if (apertura == null) {
        return 'Todavía no se abrió el plazo para pedir $_accion en esta fecha.';
      }
      return 'Vas a poder pedir $_accion a partir del ${formatDiaYFechaCorta(apertura)}.';
    }

    return esSustitucion
        ? 'El plazo para pedir un cambio en esta fecha ya cerró.'
        : 'El plazo para pedir un regreso en esta fecha ya cerró.';
  }

  @override
  Widget build(BuildContext context) {
    final esAntes = fase == CambiosVentanaFase.antes;

    return MaterialBanner(
      key: Key(esAntes ? 'ventana_antes_banner' : 'ventana_cerrada_banner'),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      leading: const Icon(Icons.lock_clock_outlined, color: Colors.orange),
      backgroundColor: Colors.amber.shade100,
      content: Text(_mensaje),
      actions: const [SizedBox.shrink()],
    );
  }
}

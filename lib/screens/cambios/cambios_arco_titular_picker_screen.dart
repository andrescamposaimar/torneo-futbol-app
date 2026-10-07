import 'package:flutter/material.dart';

import '../../models/cambios_plaza.dart';
import '../../models/cambios_solicitud.dart';
import '../../models/jugador.dart';
import '../../utils/cambios_arco_utils.dart';
import '../../widgets/cambios_jugador_card.dart';
import '../../widgets/entre_redes_app_bar.dart';
import 'cambios_solicitar_screen.dart';

/// "Cambiar por Titular" — step 1 of the grouped goalkeeper-reassignment
/// flow: pick WHICH of the team's own field titulares moves into the goal
/// plaza [plazaArco]. Reached only from the arco plaza's own card in "Mi
/// Plantel" (`cambios_plantel_screen.dart`'s `_TitularCard`).
///
/// Purely presentational + navigation — no provider of its own. [plazas]
/// and [jugadoresById] are the SAME data "Mi Plantel" already holds (see
/// `CambiosPlantelScreen`'s own docblock), handed down rather than
/// re-fetched: no new endpoint exists, or is needed, for this step (see
/// this slice's own task brief).
///
/// *** ELIGIBILITY ***
/// A titular is listed here when his OWN plaza is:
///   - a FIELD plaza (`!esPosicionArqueroTitular(...)` on his own resolved
///     position) — this is what excludes the goalkeeper himself, since his
///     plaza is the one [plazaArco] already names;
///   - currently occupied by HIMSELF (`esTitularElOcupante`) — a titular
///     who is NOT his own plaza's occupant is already out on a cambio
///     elsewhere, so he is not "available" to be moved anywhere;
///   - not `cerrada` — the same gate "Mi Plantel"'s own "Pedir cambio"
///     button already applies before offering any action on a plaza.
class CambiosArcoTitularPickerScreen extends StatelessWidget {
  final int seasonId;
  final int teamId;
  final CambiosPlaza plazaArco;
  final List<CambiosPlaza> plazas;
  final Map<int, Jugador> jugadoresById;

  const CambiosArcoTitularPickerScreen({
    super.key,
    required this.seasonId,
    required this.teamId,
    required this.plazaArco,
    required this.plazas,
    required this.jugadoresById,
  });

  List<CambiosPlaza> get _elegibles => plazas.where((p) {
        if (p.cerrada) return false;
        if (!p.esTitularElOcupante) return false;
        return !esPosicionArqueroTitular(jugadoresById[p.titularPlayerId]?.posicion);
      }).toList(growable: false);

  @override
  Widget build(BuildContext context) {
    final elegibles = _elegibles;

    return Scaffold(
      appBar: const EntreRedesAppBar(title: 'Cambiar por Titular'),
      body: elegibles.isEmpty
          ? const _EmptyView()
          : ListView(
              key: const Key('arco_titular_picker_list'),
              padding: const EdgeInsets.symmetric(vertical: 8),
              children: [
                for (final plaza in elegibles)
                  _TitularSeleccionableCard(
                    key: Key('arco_titular_card_${plaza.plazaId}'),
                    plaza: plaza,
                    jugador: jugadoresById[plaza.titularPlayerId],
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => CambiosSolicitarScreen(
                          seasonId: seasonId,
                          teamId: teamId,
                          plaza: plaza,
                          tipo: CambiosSolicitudTipo.sustitucion,
                          puntaje: jugadoresById[plaza.titularPlayerId]?.puntaje,
                          grupoArco: CambiosGrupoArcoContext(plazaArco: plazaArco),
                        ),
                      ),
                    ),
                  ),
              ],
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
            Text(
              'No hay titulares disponibles',
              style: theme.textTheme.headlineSmall,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            const Text(
              'No encontramos titulares de campo disponibles para pasar al '
              'arco en este momento.',
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }
}

/// A selectable row for one eligible titular — [CambiosJugadorCard]'s own
/// treatment (position badge, photo, name, puntaje), wrapped in an
/// [InkWell] rather than forked into its own card shape, so this step reads
/// as the same feature as "Mi Plantel".
class _TitularSeleccionableCard extends StatelessWidget {
  final CambiosPlaza plaza;
  final Jugador? jugador;
  final VoidCallback onTap;

  const _TitularSeleccionableCard({
    super.key,
    required this.plaza,
    required this.jugador,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: CambiosJugadorCard(
        playerId: plaza.titularPlayerId,
        nombre: plaza.titularNombre,
        jugador: jugador,
      ),
    );
  }
}

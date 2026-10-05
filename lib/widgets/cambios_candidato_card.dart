import 'package:flutter/material.dart';

import '../utils/puntaje_utils.dart';
import 'cambios_jugador_card.dart';

/// Presentational, SELECTABLE row for one candidate on the "Pedir cambio"
/// candidate step (both "Lista de Espera" and "Padrón Completo" sections).
///
/// Modelled on [CambiosJugadorCard]'s container shape (rounded card,
/// shadow, circular avatar, name, "Pts." trailing column, and now the SAME
/// coloured position strip via [CambiosPosicionBadge]) for visual
/// consistency with "Mi Plantel" — but NOT a reuse of that widget directly:
/// resolving a [Jugador] per candidate (the way "Mi Plantel" resolves an
/// off-roster occupant) would cost one extra HTTP request PER ROW, which
/// breaks this screen's own "the common case must cost one request" rule
/// for a list that can run into the hundreds (`CandidatosResolver`'s own
/// docblock, "COST"). [posicion] below is already part of the
/// `/cambios/plazas/candidatos` payload instead — resolved server-side, for
/// the whole page, in one batched call (see `Rest\PlazasController::
/// shapeCandidato()` and `Plazas\PosicionResolver` on the backend).
///
/// [fotoUrl] IS now part of the payload (`Rest\PlazasController::
/// fotoJugador()`, batched per PAGE the same way the name already is — see
/// that method's own docblock on the backend) — rendered via the SAME
/// [CambiosAvatar] widget [CambiosJugadorCard] renders its own
/// `jugador?.imagen` through: a missing URL falls back to the person icon,
/// and so does a URL that resolves but fails to LOAD (a 404, a deleted
/// attachment) — see that widget's own docblock for why a plain ternary on
/// [fotoUrl] cannot catch the second case.
class CambiosCandidatoCard extends StatelessWidget {
  final int playerId;
  final String nombre;
  final bool esPadre;
  final double? puntaje;
  final String? fotoUrl;

  /// The candidate's RAW posición (e.g. `'Mediocampista'`, `''`) — passed
  /// straight to [CambiosPosicionBadge], same discipline as
  /// [CambiosJugadorCard] passing `jugador?.posicion` through unabbreviated.
  /// Defaults to `''` (renders as "Sin Cargar") for any existing call site
  /// that predates this field.
  final String posicion;

  final bool selected;
  final VoidCallback onTap;

  const CambiosCandidatoCard({
    super.key,
    required this.playerId,
    required this.nombre,
    required this.esPadre,
    required this.puntaje,
    this.fotoUrl,
    this.posicion = '',
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Container(
        margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
        child: Stack(
          children: [
            Container(
              padding: const EdgeInsets.only(left: 32, right: 8, top: 12, bottom: 12),
              decoration: BoxDecoration(
                color: selected ? theme.colorScheme.primary.withValues(alpha: 0.08) : Colors.grey[100],
                borderRadius: BorderRadius.circular(12),
                border: Border.all(
                  color: selected ? theme.colorScheme.primary : Colors.transparent,
                  width: 1.5,
                ),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: 0.05),
                    blurRadius: 4,
                    offset: const Offset(0, 2),
                  ),
                ],
              ),
              child: Row(
                children: [
                  CambiosAvatar(imageUrl: fotoUrl),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                nombre,
                                style: const TextStyle(fontWeight: FontWeight.w600),
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                            const SizedBox(width: 6),
                            // Every candidate carries one of these two — the
                            // tournament's own two-category model (see this
                            // widget's own docblock): `esPadre` is `caracter`
                            // starting with "padre"; everyone else is "Invitado".
                            // Not `Expanded`/`Flexible`: the badge keeps its own
                            // intrinsic width so it is never squeezed or wrapped —
                            // the name above gives up room first via its own
                            // `Expanded` + ellipsis.
                            esPadre
                                ? const CambiosBadge(text: 'Padre')
                                : const CambiosBadge(
                                    text: 'Invitado',
                                    variant: CambiosBadgeVariant.accent,
                                  ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 6),
                  Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const Text('Pts.', style: TextStyle(fontSize: 11)),
                      Text(
                        formatearPuntaje(puntaje),
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                      ),
                    ],
                  ),
                  const SizedBox(width: 6),
                  Icon(
                    selected ? Icons.check_circle : Icons.radio_button_unchecked,
                    color: selected ? theme.colorScheme.primary : Colors.grey.shade400,
                  ),
                ],
              ),
            ),
            Positioned(
              left: 0,
              top: 10,
              bottom: 10,
              child: CambiosPosicionBadge(posicion: posicion),
            ),
          ],
        ),
      ),
    );
  }
}

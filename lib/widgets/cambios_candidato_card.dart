import 'package:flutter/material.dart';

import '../utils/puntaje_utils.dart';
import 'cambios_jugador_card.dart';

/// Presentational, SELECTABLE row for one candidate on the "Pedir cambio"
/// candidate step (both "Lista de Espera" and "Padrón Completo" sections).
///
/// Modelled on [CambiosJugadorCard]'s container shape (rounded card,
/// shadow, circular avatar, name, "Pts." trailing column) for visual
/// consistency with "Mi Plantel" — but NOT a reuse of that widget directly:
/// `GET /cambios/plazas/candidatos` returns no posición for a candidate
/// (only `nombre`, `es_padre`, `puntaje`, `viable`, `motivo`, `foto_url`),
/// so [CambiosJugadorCard]'s colored position strip would have NOTHING real
/// to show on every single row here — not an occasional missing-data case
/// that widget's placeholders were designed for, but a structural one for
/// this endpoint. Resolving a [Jugador] per candidate (the way "Mi Plantel"
/// resolves an off-roster occupant) would also cost one extra HTTP request
/// PER ROW, which breaks this screen's own "the common case must cost one
/// request" rule for a list that can run into the hundreds
/// (`CandidatosResolver`'s own docblock, "COST").
///
/// [fotoUrl] IS now part of the payload (`Rest\PlazasController::
/// fotoJugador()`, batched per PAGE the same way the name already is — see
/// that method's own docblock on the backend) — rendered exactly the way
/// [CambiosJugadorCard] already renders its own `jugador?.imagen`:
/// `NetworkImage` when present, the person icon otherwise. A missing photo
/// must fall back to the icon, never a broken image.
class CambiosCandidatoCard extends StatelessWidget {
  final int playerId;
  final String nombre;
  final bool esPadre;
  final double? puntaje;
  final String? fotoUrl;
  final bool selected;
  final VoidCallback onTap;

  const CambiosCandidatoCard({
    super.key,
    required this.playerId,
    required this.nombre,
    required this.esPadre,
    required this.puntaje,
    this.fotoUrl,
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
        padding: const EdgeInsets.all(12),
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
            fotoUrl != null && fotoUrl!.isNotEmpty
                ? CircleAvatar(backgroundImage: NetworkImage(fotoUrl!))
                : const CircleAvatar(child: Icon(Icons.person)),
            const SizedBox(width: 12),
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
            const SizedBox(width: 8),
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
            const SizedBox(width: 8),
            Icon(
              selected ? Icons.check_circle : Icons.radio_button_unchecked,
              color: selected ? theme.colorScheme.primary : Colors.grey.shade400,
            ),
          ],
        ),
      ),
    );
  }
}

import 'package:flutter/material.dart';

import '../models/jugador.dart';
import '../utils/posicion_utils.dart';
import '../utils/puntaje_utils.dart';

/// Presentational player card for the Cambios feature ("Mi Plantel" and
/// "Cambios activos"), modelled on `TeamDetailScreen._buildPlayerCard()`:
/// same coloured vertical position badge, circular photo, name and "Pts."
/// trailing column.
///
/// Two differences from that card, both intentional:
///   - No age line — it tells a captain nothing when deciding a cambio.
///   - Badges, an optional subtitle line, and an actions row, all supplied
///     by the caller as already-built widgets (this card owns layout only,
///     never plaza/cambio business logic or navigation).
///
/// This is its OWN widget, not a reuse of `_buildPlayerCard` — that one is
/// private to `team_detail_screen.dart`, which this change does not touch.
/// It is now the FOURTH copy of this same card shape (already duplicated
/// across `team_detail_screen.dart`, `players_screen.dart` and
/// `listas_screen.dart`); extracting all four into one shared widget is a
/// separate, behaviour-preserving change left for its own PR.
class CambiosJugadorCard extends StatelessWidget {
  /// The player this card represents — used by the caller to look up
  /// [jugador] in whatever map it resolved photos/posiciones/puntajes into.
  /// Not used by this widget directly; kept for callers/tests that want to
  /// assert which player a given card instance is for.
  final int playerId;

  /// The player's name. Taken straight from `CambiosPlaza`, so it is always
  /// available even while [jugador] is still being fetched.
  final String nombre;

  /// Photo/posicion/puntaje. `null` while the fetch is pending or failed —
  /// the card then falls back to placeholder looks (grey "Sin Cargar"
  /// badge, person icon, "-" puntaje) rather than a fabricated value.
  final Jugador? jugador;

  /// Greys the whole card out — the "plaza occupied by someone else, from
  /// the titular's point of view" case.
  final bool greyedOut;

  /// Badge widgets shown next to the name (e.g. "Cerrada", "Baja por
  /// cambio"). Empty renders none. Keys, if any, are the caller's concern.
  final List<Widget> badges;

  /// Extra line below the name — Cambios activos' "en la plaza de X".
  final String? subtitleExtra;

  /// Action widgets rendered below the card body, wrapped with spacing.
  /// Empty renders no actions row at all.
  final List<Widget> actions;

  const CambiosJugadorCard({
    super.key,
    required this.playerId,
    required this.nombre,
    this.jugador,
    this.greyedOut = false,
    this.badges = const [],
    this.subtitleExtra,
    this.actions = const [],
  });

  @override
  Widget build(BuildContext context) {
    final posicion = posicionAbreviada(jugador?.posicion ?? '');
    final bgColor = posicionColor(posicion);
    final imagen = jugador?.imagen;

    final card = Container(
      margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      child: Stack(
        children: [
          Container(
            decoration: BoxDecoration(
              color: Colors.grey[100],
              borderRadius: BorderRadius.circular(12),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.05),
                  blurRadius: 4,
                  offset: const Offset(0, 2),
                ),
              ],
            ),
            child: Padding(
              padding: const EdgeInsets.only(left: 36, right: 16, top: 12, bottom: 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      imagen != null && imagen.isNotEmpty
                          ? CircleAvatar(backgroundImage: NetworkImage(imagen))
                          : const CircleAvatar(child: Icon(Icons.person)),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Expanded(
                                  child: Text(
                                    nombre,
                                    style: const TextStyle(fontWeight: FontWeight.w600),
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ),
                                if (badges.isNotEmpty)
                                  Wrap(
                                    spacing: 6,
                                    runSpacing: 4,
                                    children: badges,
                                  ),
                              ],
                            ),
                            if (subtitleExtra != null) ...[
                              const SizedBox(height: 2),
                              Text(
                                subtitleExtra!,
                                style: TextStyle(
                                  fontSize: 12,
                                  color: Colors.grey.shade700,
                                  fontStyle: FontStyle.italic,
                                ),
                              ),
                            ],
                          ],
                        ),
                      ),
                      const SizedBox(width: 8),
                      Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          const Text('Pts.', style: TextStyle(fontSize: 11)),
                          Text(
                            formatearPuntaje(jugador?.puntaje),
                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                          ),
                        ],
                      ),
                    ],
                  ),
                  if (actions.isNotEmpty) ...[
                    const SizedBox(height: 10),
                    Wrap(
                      spacing: 8,
                      runSpacing: 6,
                      children: actions,
                    ),
                  ],
                ],
              ),
            ),
          ),
          Positioned(
            left: 0,
            top: 10,
            bottom: 10,
            child: FractionallySizedBox(
              heightFactor: 0.9,
              child: Container(
                width: 28,
                decoration: BoxDecoration(
                  color: bgColor,
                  borderRadius: const BorderRadius.only(
                    topRight: Radius.circular(6),
                    bottomRight: Radius.circular(6),
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.2),
                      blurRadius: 6,
                      offset: const Offset(2, 2),
                    ),
                  ],
                ),
                child: Center(
                  child: RotatedBox(
                    quarterTurns: -1,
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 4.0),
                      child: Text(
                        posicion,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );

    // Greyed-out is a visual-only signal (no actions are ever passed in by
    // the caller for this case) — Opacity keeps the badge/name still legible
    // while unmistakably muted.
    return greyedOut ? Opacity(opacity: 0.55, child: card) : card;
  }
}

/// [CambiosBadge]'s visual variant — [neutral] is the original grey pill
/// ("Cerrada", "Baja por cambio"); [accent] is a brand-tinted pill for a
/// badge that should read as distinguishable at a glance (e.g. "Invitado"
/// vs. "Padre" on `CambiosCandidatoCard`). Extending the SAME widget with a
/// variant — rather than forking it — keeps every existing call site's look
/// untouched: [neutral] is the default.
enum CambiosBadgeVariant { neutral, accent }

/// Small pill badge used for "Cerrada" / "Baja por cambio" markers, and for
/// "Padre" / "Invitado" on a candidate row.
/// Public (not `_Badge`) so callers in `cambios_plantel_screen.dart` can
/// attach their own per-plaza [Key] to each instance.
class CambiosBadge extends StatelessWidget {
  final String text;
  final CambiosBadgeVariant variant;

  const CambiosBadge({
    super.key,
    required this.text,
    this.variant = CambiosBadgeVariant.neutral,
  });

  @override
  Widget build(BuildContext context) {
    final isAccent = variant == CambiosBadgeVariant.accent;
    // A light tint of the tenant's own primary color, same pairing already
    // used for `_PlazaHeader`'s tinted strip and the selected puntaje chip
    // (`cambios_solicitar_screen.dart`) — a solid `primary` text on a very
    // light `primary` background keeps contrast comfortably accessible
    // without hard-coding a color literal that would fight the tenant theme.
    final primary = Theme.of(context).colorScheme.primary;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: isAccent ? primary.withValues(alpha: 0.12) : Colors.grey.shade300,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Text(
        text,
        style: TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w600,
          color: isAccent ? primary : null,
        ),
      ),
    );
  }
}

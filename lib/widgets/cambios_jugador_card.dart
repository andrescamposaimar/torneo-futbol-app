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
                      CambiosAvatar(imageUrl: imagen),
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
            child: CambiosPosicionBadge(posicion: jugador?.posicion ?? ''),
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

/// The coloured vertical "main position" strip with rotated text, shared by
/// [CambiosJugadorCard] and `CambiosCandidatoCard` — the SAME widget, so a
/// fix to one card's position badge can never silently miss the other (same
/// reasoning as [CambiosAvatar]'s own docblock, applied here instead of to
/// the photo). A caller wraps this in its own `Positioned` inside its own
/// `Stack` — this widget only ever renders the strip's box + text, never its
/// placement, since the two cards position it at slightly different offsets.
class CambiosPosicionBadge extends StatelessWidget {
  /// The RAW posición as the backend/model carries it (e.g.
  /// `'Mediocampista'`, `''`) — never pre-abbreviated by the caller.
  /// [posicionAbreviada] and [posicionColor] are applied HERE, the one place
  /// both cards now share that mapping through.
  final String posicion;

  const CambiosPosicionBadge({super.key, required this.posicion});

  @override
  Widget build(BuildContext context) {
    final abreviada = posicionAbreviada(posicion);
    final bgColor = posicionColor(abreviada);

    return FractionallySizedBox(
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
                abreviada,
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
    );
  }
}

/// Circular player-photo avatar, shared by [CambiosJugadorCard] and
/// `CambiosCandidatoCard` — the SAME widget, so a fix to one card's avatar
/// fallback can never silently miss the other (both cards used to build
/// their own `imagen != null && imagen.isNotEmpty ? CircleAvatar(
/// backgroundImage: ...) : CircleAvatar(child: Icon(Icons.person))`
/// ternary inline, independently, and neither handled a load FAILURE).
///
/// *** `foregroundImage` + `child`, NEVER `backgroundImage` ALONE ***
/// [NetworkImage] failing to LOAD (a 404, a deleted WordPress attachment, a
/// transient network error) does NOT by itself make [CircleAvatar] fall
/// back to its `child` — `backgroundImage` simply never paints anything on
/// failure, rendering an empty circle forever even though a URL was
/// supplied. The fix is the SAME idiom this codebase already uses for
/// `CampeonAvatar` and `ProdeIdentityCard`'s own avatar (see either's
/// docblock): `foregroundImage` layers the photo OVER a `child` that is
/// ALWAYS rendered underneath, and `onForegroundImageError` is a no-op that
/// merely swallows the failure — the person icon was already visible the
/// whole time, nothing to switch to.
class CambiosAvatar extends StatelessWidget {
  /// The candidate/player's photo URL, or `null`/empty when they have none —
  /// both render the same person-icon fallback as a load failure does.
  final String? imageUrl;

  const CambiosAvatar({super.key, this.imageUrl});

  @override
  Widget build(BuildContext context) {
    final tieneFoto = imageUrl != null && imageUrl!.isNotEmpty;

    return CircleAvatar(
      key: tieneFoto
          ? const ValueKey('cambios_avatar_photo')
          : const ValueKey('cambios_avatar_person'),
      foregroundImage: tieneFoto ? NetworkImage(imageUrl!) : null,
      onForegroundImageError: tieneFoto ? (_, __) {} : null,
      child: const Icon(Icons.person),
    );
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

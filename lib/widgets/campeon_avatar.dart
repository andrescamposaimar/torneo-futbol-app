import 'package:flutter/material.dart';

import '../utils/campeones_iniciales.dart';

/// Avatar for one Copa Chaminade squad row (ADR-C2).
///
/// Renders the player's photo when [fotoUrl] is supplied; otherwise renders
/// a tinted initials avatar derived from [nombre]. There is exactly ONE
/// fallback, never a grey `Icons.person` silhouette: on this screen a
/// missing photo usually means the matcher did not link the row, which is a
/// fact about our plumbing, not about the champion (APP-5) — an unlinked
/// row must never render as visually lesser than a linked one.
///
/// [CampeonAvatar] is never told whether the row is linked at all — it only
/// knows whether a URL was supplied. That is the structural guarantee: this
/// widget *cannot* mark an unlinked row as lesser, because the information
/// needed to do so was never given to it.
///
/// Structurally follows `lib/widgets/prode_identity_card.dart:286-297`:
/// `foregroundImage` layers the photo OVER the initials, so they stay
/// visible while the image loads (no grey flash), and
/// `onForegroundImageError` swallows a failed load, leaving the initials
/// visible — a 404 photo and a missing photo land on the SAME rendering
/// through ONE code path.
class CampeonAvatar extends StatelessWidget {
  /// Raw stored name, e.g. `'BASSO, A.'` — fed to [inicialesDeNombre].
  final String nombre;

  /// `CampeonPlantelEntry.fotoUrl`. Null or empty renders the initials
  /// avatar.
  final String? fotoUrl;

  const CampeonAvatar({super.key, required this.nombre, this.fotoUrl});

  @override
  Widget build(BuildContext context) {
    final iniciales = inicialesDeNombre(nombre);
    final scheme = Theme.of(context).colorScheme;
    // The palette lives in the theme, never as hand-picked hex, so avatars
    // stay on-brand for every tenant automatically — see lib/theme.dart.
    final paleta = <Color>[scheme.primary, scheme.secondary, scheme.tertiary];
    final color =
        paleta[indiceColorAvatar(iniciales, cantidadColores: paleta.length)];
    final tieneFoto = fotoUrl != null && fotoUrl!.isNotEmpty;

    return ExcludeSemantics(
      // The name is already read beside this avatar by the row's own Text.
      child: CircleAvatar(
        key: tieneFoto
            ? ValueKey('campeon_avatar_photo_$nombre')
            : ValueKey('campeon_avatar_initials_$nombre'),
        radius: 18,
        foregroundImage: tieneFoto ? NetworkImage(fotoUrl!) : null,
        onForegroundImageError: tieneFoto ? (_, __) {} : null,
        backgroundColor: color.withValues(alpha: 0.15),
        child: Text(
          iniciales,
          style: TextStyle(
            color: color,
            fontWeight: FontWeight.bold,
            fontSize: 13,
          ),
        ),
      ),
    );
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../providers/credencial_providers.dart';
import '../../services/credencial_controller.dart';
import '../../services/credencial_state.dart';
import '../../widgets/credencial_card.dart';
import '../../widgets/entre_redes_app_bar.dart';

/// Container for "Mi Credencial": owns the [credencialControllerProvider]
/// lifecycle and delegates the actual card rendering to [CredencialCard].
///
/// Unlike [ProdeAuthGate] (which only bootstraps from the resting
/// Unauthenticated state so re-entering the tab doesn't repeat a network
/// round-trip), this screen calls [CredencialController.open] on EVERY
/// mount — design D6 is explicit: "on every app open with connectivity
/// re-GET" means every time the player opens this screen, not only once per
/// app session.
///
/// Must only be reached from a tenant where `features.credencial` is true —
/// the "Mi Credencial" tile in `more_screen.dart` enforces that gate.
class CredencialScreen extends ConsumerStatefulWidget {
  const CredencialScreen({super.key});

  @override
  ConsumerState<CredencialScreen> createState() => _CredencialScreenState();
}

class _CredencialScreenState extends ConsumerState<CredencialScreen> {
  @override
  void initState() {
    super.initState();
    // Defer with a microtask: Riverpod forbids mutating a provider during
    // the build phase (initState/first build), same pattern as
    // ProdeAuthGate/ProdeIdentityCard.
    Future.microtask(() {
      if (mounted) {
        ref.read(credencialControllerProvider.notifier).open();
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(credencialControllerProvider);
    final notifier = ref.read(credencialControllerProvider.notifier);

    return Scaffold(
      // No `backgroundColor` override: `buildAppTheme` already sets
      // `scaffoldBackgroundColor` from the tenant's own colors (design D-UI:
      // "Background scaffoldBackgroundColor" — colors only from the theme).
      appBar: const EntreRedesAppBar(title: 'Mi Credencial'),
      // No blanket padding here — [CredencialCard] already carries its own
      // internal inset, and each branch below applies exactly one layer of
      // padding. A screen-level Padding(16) stacked on top of the card's own
      // Padding(16) was silently eating 32px of usable width per side
      // instead of 16, which caused a real (non-flaky) RenderFlex overflow
      // on a 375pt-wide phone at a 1.3x text scale — caught only by a widget
      // test built at that exact narrow size, not by the isolated
      // CredencialCard test (which never had a second padding layer).
      body: _buildBody(context, state, notifier),
    );
  }

  Widget _buildBody(
    BuildContext context,
    CredencialUiState state,
    CredencialController notifier,
  ) {
    return switch (state) {
      CredencialLoading() => const Center(child: CircularProgressIndicator()),
      CredencialNotSignedIn() => const _Message(
          icon: Icons.lock_outline,
          title: 'Sesión cerrada',
          body: 'Volvé a ingresar para ver tu credencial.',
        ),
      CredencialActive(
        :final credential,
        :final replacement,
        :final stale,
        :final photoBytes,
      ) =>
        // Decision 1523: [photoBytes] is already verified bytes carried by
        // the state itself (see CredencialActive's docblock) — this screen
        // must never re-read the photo file asynchronously at render time,
        // which previously left a first-frame window where the valid-styled
        // card rendered with a silhouette placeholder (verify-report 1575,
        // slice 3b, NEW WARNING 1).
        //
        // No wrapping Padding/SingleChildScrollView here: [CredencialCard]
        // owns its own SafeArea, scrolling and single 16px inset (design
        // D-UI) — stacking a second layer here previously ate more width
        // per side than CredencialCard's own narrow-viewport test covered,
        // causing a real (non-flaky) RenderFlex overflow on a 375pt-wide
        // phone at 1.3x text scale that only a screen-level widget test at
        // that exact size caught.
        CredencialCard(
          credential: credential,
          replacement: replacement,
          stale: stale,
          photoBytes: photoBytes,
        ),
      // There is no in-app photo upload yet (slice 4), so this state must
      // never promise an in-app action: the comisión loads the photo.
      CredencialNoPhoto() => const _Message(
          icon: Icons.badge_outlined,
          title: 'Sin foto cargada',
          body: 'Todavía no tenés una foto cargada. Pedile a la comisión '
              'que la cargue para poder usar tu credencial.',
        ),
      CredencialPendingPhoto() => const _Message(
          icon: Icons.hourglass_top,
          title: 'Foto en revisión',
          body: 'Tu foto está en revisión.',
        ),
      CredencialRejectedPhoto() => const _Message(
          icon: Icons.error_outline,
          title: 'Foto rechazada',
          body: 'Tu foto nueva fue rechazada. La comisión se pondrá en '
              'contacto.',
        ),
      CredencialBlocked() => const _Message(
          icon: Icons.block,
          title: 'Credencial no disponible',
          body: 'Tu credencial no está disponible.',
        ),
      CredencialNotAPlayer() => const _Message(
          icon: Icons.info_outline,
          title: 'Sin jugador asociado',
          body: 'No encontramos un jugador asociado a tu cuenta.',
        ),
      CredencialExpired() => _Message(
          icon: Icons.event_busy,
          title: 'Credencial vencida',
          body: 'Conectate a internet para renovarla.',
          onRetry: notifier.open,
        ),
      CredencialOfflineNoCache() => _Message(
          icon: Icons.wifi_off,
          title: 'Sin conexión',
          body: 'Conectate a internet para ver tu credencial.',
          onRetry: notifier.open,
        ),
      CredencialPhotoUnavailable() => _Message(
          icon: Icons.image_not_supported_outlined,
          title: 'No pudimos descargar tu foto',
          body: 'Conectate a internet y volvé a intentar.',
          onRetry: notifier.open,
        ),
      CredencialError(:final message) => _Message(
          icon: Icons.error_outline,
          title: 'Algo salió mal',
          body: message,
          onRetry: notifier.open,
        ),
    };
  }
}

/// Shared layout for every non-Active/non-Loading state (design D-UI): a 72pt
/// icon in a tinted circle, a title, a short body and an optional retry
/// action — one visual language across all 10 message states instead of
/// each screen inventing its own.
class _Message extends StatelessWidget {
  final IconData icon;
  final String title;
  final String body;
  final VoidCallback? onRetry;

  const _Message({
    required this.icon,
    required this.title,
    required this.body,
    this.onRetry,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final primary = theme.colorScheme.primary;
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 72,
              height: 72,
              decoration: BoxDecoration(
                color: primary.withValues(alpha: 0.1),
                shape: BoxShape.circle,
              ),
              child: Icon(icon, size: 36, color: primary),
            ),
            const SizedBox(height: 16),
            Text(title, style: theme.textTheme.titleLarge, textAlign: TextAlign.center),
            const SizedBox(height: 8),
            Text(body, style: theme.textTheme.bodyMedium, textAlign: TextAlign.center),
            if (onRetry != null) ...[
              const SizedBox(height: 16),
              FilledButton(
                  onPressed: onRetry, child: const Text('Reintentar')),
            ],
          ],
        ),
      ),
    );
  }
}

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
      backgroundColor: Colors.grey.shade100,
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
      CredencialNotSignedIn() => _Message(
          icon: Icons.lock_outline,
          text: 'Tu sesión se cerró. Volvé a ingresar para ver tu '
              'credencial.',
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
        SingleChildScrollView(
          // No padding here: [CredencialCard] already carries its own
          // internal 16px inset (a `Card` + `Padding(16)`), matching exactly
          // the layout budget validated by CredencialCard's own narrow-
          // viewport test. Stacking a second 16px layer here previously ate
          // 32px more width per side than that test covered, causing a real
          // (non-flaky) RenderFlex overflow on a 375pt-wide phone at 1.3x
          // text scale that only a screen-level widget test at that exact
          // size caught.
          child: CredencialCard(
            credential: credential,
            replacement: replacement,
            stale: stale,
            photoBytes: photoBytes,
          ),
        ),
      CredencialNoPhoto() => const _Message(
          icon: Icons.badge_outlined,
          text: 'Todavía no tenés una foto aprobada para tu credencial.',
        ),
      CredencialPendingPhoto() => const _Message(
          icon: Icons.hourglass_top,
          text: 'Tu foto está en revisión.',
        ),
      CredencialRejectedPhoto() => const _Message(
          icon: Icons.error_outline,
          text: 'Tu foto nueva fue rechazada. La comisión se pondrá en '
              'contacto.',
        ),
      CredencialBlocked() => const _Message(
          icon: Icons.block,
          text: 'Tu credencial no está disponible.',
        ),
      CredencialNotAPlayer() => const _Message(
          icon: Icons.info_outline,
          text: 'No encontramos un jugador asociado a tu cuenta.',
        ),
      CredencialExpired() => _Message(
          icon: Icons.wifi_off,
          text: 'Tu credencial venció. Conectate a internet para renovarla.',
          onRetry: notifier.open,
        ),
      CredencialOfflineNoCache() => _Message(
          icon: Icons.wifi_off,
          text: 'Sin conexión. Conectate a internet para ver tu credencial.',
          onRetry: notifier.open,
        ),
      CredencialPhotoUnavailable() => _Message(
          icon: Icons.wifi_off,
          text: 'No pudimos descargar tu foto. Conectate a internet y volvé '
              'a intentar.',
          onRetry: notifier.open,
        ),
      CredencialError(:final message) => _Message(
          icon: Icons.error_outline,
          text: message,
          onRetry: notifier.open,
        ),
    };
  }
}

/// Shared layout for every non-Active/non-Loading state: an icon, short
/// Spanish copy and an optional retry action.
class _Message extends StatelessWidget {
  final IconData icon;
  final String text;
  final VoidCallback? onRetry;

  const _Message({required this.icon, required this.text, this.onRetry});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 48, color: Colors.grey.shade500),
            const SizedBox(height: 16),
            Text(text, textAlign: TextAlign.center),
            if (onRetry != null) ...[
              const SizedBox(height: 16),
              ElevatedButton(
                  onPressed: onRetry, child: const Text('Reintentar')),
            ],
          ],
        ),
      ),
    );
  }
}

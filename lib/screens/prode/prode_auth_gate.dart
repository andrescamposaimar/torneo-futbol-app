import 'dart:io' show Platform;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../providers/prode_providers.dart';
import '../../services/prode_auth_state.dart';
import '../../widgets/entre_redes_app_bar.dart';
import 'prode_auth_view.dart';

/// Container for the Prode feature: owns the [prodeAuthControllerProvider]
/// wiring and lifecycle, and delegates rendering to the pure [ProdeAuthView].
///
/// [ProdeAuthController.bootstrap] is NOT run at app start (the whole feature
/// is gated behind `tenantConfig.features.prode`), so this gate kicks it off
/// when it first mounts. It must only be reached from a tenant where
/// `features.prode` is true — the entry point in the "Más" tab enforces that.
class ProdeAuthGate extends ConsumerStatefulWidget {
  const ProdeAuthGate({super.key});

  @override
  ConsumerState<ProdeAuthGate> createState() => _ProdeAuthGateState();
}

class _ProdeAuthGateState extends ConsumerState<ProdeAuthGate> {
  @override
  void initState() {
    super.initState();
    // The controller is app-scoped (non-autoDispose), so its state survives
    // navigating away and back. Only bootstrap from the resting/initial
    // Unauthenticated state: re-entering the gate while already Authenticated,
    // Revoked, or Error must NOT clobber that state with a fresh Hydrating +
    // redundant network refresh. (Error/Revoked expose their own retry CTA.)
    if (ref.read(prodeAuthControllerProvider) is ProdeAuthUnauthenticated) {
      // Defer with a microtask: bootstrap() mutates the provider synchronously
      // (sets Hydrating), and Riverpod forbids modifying a provider while the
      // widget tree is building (which is what initState/first-build is). The
      // microtask runs right after this frame's build completes — before paint
      // in practice — so there's no visible flash of the Unauthenticated view.
      Future.microtask(() {
        if (mounted) {
          ref.read(prodeAuthControllerProvider.notifier).bootstrap();
        }
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(prodeAuthControllerProvider);
    final controller = ref.read(prodeAuthControllerProvider.notifier);

    return Scaffold(
      appBar: const EntreRedesAppBar(title: 'Prode'),
      body: ProdeAuthView(
        state: state,
        onLogout: controller.logout,
        onRetry: controller.bootstrap,
        onGoogleSignIn: controller.signInWithGoogle,
        // Apple sign-in is iOS-only (backend supports only the native flow);
        // null elsewhere so the sign-in view hides the Apple button.
        onAppleSignIn: Platform.isIOS ? controller.signInWithApple : null,
        onConfirmDni: controller.confirmDni,
      ),
    );
  }
}

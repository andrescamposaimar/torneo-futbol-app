import 'dart:io' show Platform;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../providers/prode_providers.dart';
import '../../services/prode_auth_state.dart';
import '../../widgets/entre_redes_app_bar.dart';
import '../prode/prode_auth_view.dart';
import 'cambios_context_screen.dart';

/// Entry point for the captain-facing "Cambios" (player substitution)
/// feature — reachable from the "More" tab.
///
/// Cambios authenticates with the SAME Prode session as the rest of the app
/// (`Capitania\CapitanAuthorizer` validates the identical access token —
/// see `CambiosApiService`'s own docblock), so this gate mirrors
/// [ProdeAuthGate] exactly: same bootstrap-on-first-mount guard, same
/// [ProdeAuthView] for sign-in / DNI-confirmation / revoked / error. Only the
/// Authenticated destination differs — [ProdeAuthView.authenticatedBuilder]
/// is where that's swapped in, so none of the SSO/DNI UI is duplicated here.
class CambiosAuthGate extends ConsumerStatefulWidget {
  const CambiosAuthGate({super.key});

  @override
  ConsumerState<CambiosAuthGate> createState() => _CambiosAuthGateState();
}

class _CambiosAuthGateState extends ConsumerState<CambiosAuthGate> {
  @override
  void initState() {
    super.initState();
    // Same guard as ProdeAuthGate: the controller is app-scoped (shared with
    // the Prode feature), so re-entering while already Authenticated/Revoked/
    // Error must not clobber that state with a fresh bootstrap.
    if (ref.read(prodeAuthControllerProvider) is ProdeAuthUnauthenticated) {
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
      appBar: const EntreRedesAppBar(title: 'Cambios'),
      body: ProdeAuthView(
        state: state,
        onLogout: controller.logout,
        onRetry: controller.bootstrap,
        onGoogleSignIn: controller.signInWithGoogle,
        onAppleSignIn: Platform.isIOS ? controller.signInWithApple : null,
        onConfirmDni: controller.confirmDni,
        authenticatedBuilder: (stale, onLogout) =>
            CambiosContextScreen(stale: stale),
      ),
    );
  }
}

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
      appBar: EntreRedesAppBar(
        title: 'Cambios',
        // Prode's authenticated screens render a persistent "Cerrar sesión"
        // action of their own (see ProdeFixturesScreen); Cambios has no such
        // affordance in any of its screens, so without this a captain who
        // enters Cambios from the More tab could never sign out. Shown only
        // once authenticated — earlier states (sign-in, DNI confirmation,
        // revoked, error) have no session to close.
        actions: state is ProdeAuthAuthenticated
            ? [
                IconButton(
                  key: const Key('cambios_logout_button'),
                  icon: const Icon(Icons.logout),
                  tooltip: 'Cerrar sesión',
                  onPressed: controller.logout,
                ),
              ]
            : null,
      ),
      body: ProdeAuthView(
        state: state,
        onLogout: controller.logout,
        onRetry: controller.bootstrap,
        onGoogleSignIn: controller.signInWithGoogle,
        onAppleSignIn: Platform.isIOS ? controller.signInWithApple : null,
        onConfirmDni: controller.confirmDni,
        // `onLogout` is intentionally unused here: unlike ProdeFixturesScreen,
        // CambiosContextScreen (and everything under it) has no room for its
        // own persistent logout action, so the affordance lives on THIS
        // screen's AppBar instead (see `actions` above), wired to the exact
        // same `controller.logout` this builder would otherwise have
        // forwarded — not a dropped parameter, a different placement of the
        // same behavior.
        authenticatedBuilder: (stale, onLogout) =>
            CambiosContextScreen(stale: stale),
      ),
    );
  }
}

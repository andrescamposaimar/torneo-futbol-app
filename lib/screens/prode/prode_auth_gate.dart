import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../providers/prode_providers.dart';
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
    // bootstrap()'s first synchronous statement sets state to Hydrating, so
    // calling it here (before the first build) means the gate renders the
    // loading state immediately — no flash of the Unauthenticated view.
    ref.read(prodeAuthControllerProvider.notifier).bootstrap();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(prodeAuthControllerProvider);
    final controller = ref.read(prodeAuthControllerProvider.notifier);

    return Scaffold(
      appBar: EntreRedesAppBar(title: 'Prode'),
      body: ProdeAuthView(
        state: state,
        onLogout: controller.logout,
        onRetry: controller.bootstrap,
      ),
    );
  }
}

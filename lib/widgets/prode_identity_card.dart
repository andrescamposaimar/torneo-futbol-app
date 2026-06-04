import 'dart:io' show Platform;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/tenant_provider.dart';
import '../providers/prode_providers.dart';
import '../screens/prode/prode_auth_gate.dart';
import '../services/prode_auth_controller.dart';
import '../services/prode_auth_state.dart';
import 'prode_sign_in_buttons.dart';

/// Guarded identity header for the Prode feature.
///
/// When [TenantFeatures.prode] is false this widget returns [SizedBox.shrink]
/// BEFORE watching any Prode provider — preventing the [StateError] that
/// [prodeApiServiceProvider] throws when prode is disabled (AC-07, AC-48).
///
/// When prode is enabled, the card:
/// - Calls [ProdeAuthController.bootstrap] once on first mount if the current
///   state is [ProdeAuthUnauthenticated] (same guard as [ProdeAuthGate]).
/// - Renders a compact per-state UI driven by [prodeAuthControllerProvider].
///
/// Sign-in (Google/Apple) happens in-place; the card re-renders reactively.
/// DNI confirmation and error retry push [ProdeAuthGate] which owns those forms.
class ProdeIdentityCard extends ConsumerStatefulWidget {
  const ProdeIdentityCard({super.key});

  @override
  ConsumerState<ProdeIdentityCard> createState() => _ProdeIdentityCardState();
}

class _ProdeIdentityCardState extends ConsumerState<ProdeIdentityCard> {
  bool _bootstrapped = false;

  @override
  void initState() {
    super.initState();
    // Read tenant config synchronously — tenantConfigProvider is always safe.
    final cfg = ref.read(tenantConfigProvider);
    if (!cfg.features.prode) return;

    // Mirror the ProdeAuthGate microtask bootstrap pattern:
    // defer so we don't mutate a provider during the build phase.
    Future.microtask(() {
      if (!mounted) return;
      if (_bootstrapped) return;
      final current = ref.read(prodeAuthControllerProvider);
      if (current is ProdeAuthUnauthenticated) {
        _bootstrapped = true;
        ref.read(prodeAuthControllerProvider.notifier).bootstrap();
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    // ---- Guard: prode=false → invisible, no prode-provider touched ----
    final cfg = ref.watch(tenantConfigProvider);
    if (!cfg.features.prode) return const SizedBox.shrink();

    // ---- Prode enabled: watch auth state ----
    final state = ref.watch(prodeAuthControllerProvider);
    final notifier = ref.read(prodeAuthControllerProvider.notifier);

    return _buildCard(context, state, notifier);
  }

  Widget _buildCard(
    BuildContext context,
    ProdeAuthState state,
    ProdeAuthController notifier,
  ) {
    return Card(
      color: Colors.white,
      elevation: 1,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(color: Colors.grey.shade200),
      ),
      margin: const EdgeInsets.symmetric(horizontal: 16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: _buildContent(context, state, notifier),
      ),
    );
  }

  Widget _buildContent(
    BuildContext context,
    ProdeAuthState state,
    ProdeAuthController notifier,
  ) {
    return switch (state) {
      // Loading states
      ProdeAuthHydrating() || ProdeAuthAuthenticating() => const Center(
          child: Padding(
            padding: EdgeInsets.symmetric(vertical: 8),
            child: CircularProgressIndicator(),
          ),
        ),

      // Unauthenticated: compact sign-in prompt
      ProdeAuthUnauthenticated() => Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(
                  Icons.sports_soccer,
                  color: Theme.of(context).colorScheme.primary,
                ),
                const SizedBox(width: 8),
                const Expanded(
                  child: Text('Sumate al Prode — iniciá sesión'),
                ),
              ],
            ),
            const SizedBox(height: 16),
            ProdeSignInButtons(
              onGoogleSignIn: notifier.signInWithGoogle,
              onAppleSignIn:
                  Platform.isIOS ? notifier.signInWithApple : null,
            ),
          ],
        ),

      // Needs DNI: CTA tile that hands off to ProdeAuthGate
      ProdeAuthNeedsDniConfirmation() => ListTile(
          contentPadding: EdgeInsets.zero,
          leading: Icon(
            Icons.badge_outlined,
            color: Theme.of(context).colorScheme.primary,
          ),
          title: const Text('Completá tu registro'),
          trailing: const Icon(Icons.arrow_forward_ios, size: 16),
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute<void>(
              builder: (_) => const ProdeAuthGate(),
            ),
          ),
        ),

      // Authenticated with a real name
      ProdeAuthAuthenticated(:final user, :final stale)
          when user.name.isNotEmpty && !stale =>
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: CircleAvatar(
            backgroundColor: Theme.of(context)
                .colorScheme
                .primary
                .withValues(alpha: 0.15),
            child: Text(
              user.name[0].toUpperCase(),
              style: TextStyle(
                color: Theme.of(context).colorScheme.primary,
                fontWeight: FontWeight.bold,
              ),
            ),
          ),
          title: Text(user.name),
          trailing: const Icon(Icons.chevron_right),
        ),

      // Authenticated but stale or empty name (placeholder)
      ProdeAuthAuthenticated() => ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const CircleAvatar(
            child: Icon(Icons.person),
          ),
          title: const Text('Sincronizando...'),
          trailing: const Icon(Icons.chevron_right),
        ),

      // Revoked: lock + re-login CTA
      ProdeAuthRevoked() => Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(Icons.lock_outline,
                    color: Theme.of(context).colorScheme.error),
                const SizedBox(width: 8),
                const Expanded(child: Text('Tu sesión se cerró')),
              ],
            ),
            const SizedBox(height: 12),
            ElevatedButton(
              onPressed: notifier.logout,
              child: const Text('Volver a ingresar'),
            ),
          ],
        ),

      // Error: error icon + retry
      ProdeAuthError() => Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(Icons.error_outline,
                    color: Theme.of(context).colorScheme.error),
                const SizedBox(width: 8),
                const Expanded(
                    child: Text('Algo salió mal — revisá tu conexión')),
              ],
            ),
            const SizedBox(height: 12),
            ElevatedButton(
              onPressed: notifier.bootstrap,
              child: const Text('Reintentar'),
            ),
          ],
        ),
    };
  }
}

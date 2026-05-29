import 'package:flutter/material.dart';

import '../../services/prode_auth_state.dart';

/// Presentational view for the Prode auth feature.
///
/// Pure and Riverpod-free: it renders the right UI for a given
/// [ProdeAuthState] and delegates actions to the injected callbacks. The
/// stateful container ([ProdeAuthGate]) owns the provider wiring and lifecycle;
/// this widget owns only how each state looks, which keeps it trivially
/// widget-testable by pumping it with a concrete state.
///
/// PR-09 slice 9a: SSO sign-in (Google/Apple) and DNI confirmation are not
/// wired yet (slices 9b/9c). Until then the [ProdeAuthUnauthenticated] and
/// [ProdeAuthNeedsDniConfirmation] states render an honest "coming soon"
/// message rather than non-functional buttons.
class ProdeAuthView extends StatelessWidget {
  final ProdeAuthState state;

  /// Clears the session (used by the Authenticated and Revoked views).
  final VoidCallback onLogout;

  /// Re-runs bootstrap (used by the Error and Revoked retry actions).
  final VoidCallback onRetry;

  /// Starts the Google Sign-In flow (used by the Unauthenticated sign-in view).
  final VoidCallback onGoogleSignIn;

  const ProdeAuthView({
    super.key,
    required this.state,
    required this.onLogout,
    required this.onRetry,
    required this.onGoogleSignIn,
  });

  @override
  Widget build(BuildContext context) {
    return switch (state) {
      ProdeAuthHydrating() ||
      ProdeAuthAuthenticating() =>
        const _Centered(child: CircularProgressIndicator()),
      ProdeAuthAuthenticated(:final user, :final stale) =>
        _ProdeHome(user: user, stale: stale, onLogout: onLogout),
      ProdeAuthUnauthenticated() => _SignInView(onGoogleSignIn: onGoogleSignIn),
      ProdeAuthNeedsDniConfirmation() => const _ComingSoon(
          icon: Icons.badge_outlined,
          title: 'Confirmá tu identidad',
          message:
              'La confirmación de DNI estará disponible en la próxima '
              'actualización.',
        ),
      ProdeAuthRevoked() => _MessageAction(
          icon: Icons.lock_outline,
          title: 'Tu sesión se cerró',
          message:
              'Cerramos tu sesión por seguridad. Ingresá de nuevo para seguir '
              'jugando.',
          actionLabel: 'Volver a ingresar',
          onAction: onLogout,
        ),
      // The controller fills ProdeAuthError.message with e.toString() for
      // diagnostics; don't surface that developer-y text to league users —
      // show friendly generic copy and keep the technical detail for logging.
      ProdeAuthError() => _MessageAction(
          icon: Icons.error_outline,
          title: 'Algo salió mal',
          message:
              'No pudimos cargar el Prode. Revisá tu conexión y reintentá en '
              'unos minutos.',
          actionLabel: 'Reintentar',
          onAction: onRetry,
        ),
    };
  }
}

// ---------------------------------------------------------------------------
// Per-state views (private — presentational details)
// ---------------------------------------------------------------------------

class _Centered extends StatelessWidget {
  final Widget child;
  const _Centered({required this.child});

  @override
  Widget build(BuildContext context) => Center(child: child);
}

/// Placeholder home shown once the user has a session. The real Prode screens
/// (fixtures, predictions, ranking) land in later slices; for now this greets
/// the user and surfaces the [stale] "identity pending" state from PR-08.
class _ProdeHome extends StatelessWidget {
  final ProdeUser user;
  final bool stale;
  final VoidCallback onLogout;

  const _ProdeHome({
    required this.user,
    required this.stale,
    required this.onLogout,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    // While stale (degraded placeholder from an offline bootstrap) the name is
    // empty/placeholder, so greet generically until the server confirms it.
    final greeting = (stale || user.name.isEmpty)
        ? '¡Hola!'
        : '¡Hola, ${user.name}!';

    return Column(
      children: [
        if (stale)
          MaterialBanner(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            leading: const Icon(Icons.sync, color: Colors.orange),
            backgroundColor: Colors.amber.shade100,
            content: const Text(
              'Sincronizando tus datos…',
              style: TextStyle(color: Colors.black87),
            ),
            actions: const [SizedBox.shrink()],
          ),
        Expanded(
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.emoji_events_outlined,
                      size: 64, color: theme.colorScheme.primary),
                  const SizedBox(height: 16),
                  Text(greeting, style: theme.textTheme.headlineSmall),
                  const SizedBox(height: 8),
                  const Text(
                    'El Prode estará disponible muy pronto. ¡Preparate para '
                    'pronosticar!',
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 24),
                  TextButton.icon(
                    onPressed: onLogout,
                    icon: const Icon(Icons.logout),
                    label: const Text('Cerrar sesión'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }
}

/// Sign-in view for the Unauthenticated state. Google is wired; Apple is shown
/// disabled with a "próximamente" hint until its Team ID is provisioned.
class _SignInView extends StatelessWidget {
  final VoidCallback onGoogleSignIn;

  const _SignInView({required this.onGoogleSignIn});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.sports_soccer, size: 64, color: theme.colorScheme.primary),
            const SizedBox(height: 16),
            Text('Sumate al Prode', style: theme.textTheme.headlineSmall),
            const SizedBox(height: 8),
            const Text(
              'Iniciá sesión para pronosticar los partidos del torneo.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 24),
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                onPressed: onGoogleSignIn,
                icon: const Icon(Icons.account_circle),
                label: const Text('Continuar con Google'),
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
              ),
            ),
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                // Disabled until the Apple Team ID is provisioned (next slice).
                onPressed: null,
                icon: const Icon(Icons.apple),
                label: const Text('Continuar con Apple (próximamente)'),
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
              ),
            ),
            const SizedBox(height: 20),
            Text(
              'Al continuar aceptás los Términos del Servicio y la Política de '
              'Privacidad.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall?.copyWith(color: Colors.black54),
            ),
          ],
        ),
      ),
    );
  }
}

/// A centered icon + title + message, used for the not-yet-wired states.
class _ComingSoon extends StatelessWidget {
  final IconData icon;
  final String title;
  final String message;

  const _ComingSoon({
    required this.icon,
    required this.title,
    required this.message,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 64, color: theme.colorScheme.primary),
            const SizedBox(height: 16),
            Text(title, style: theme.textTheme.headlineSmall),
            const SizedBox(height: 8),
            Text(message, textAlign: TextAlign.center),
          ],
        ),
      ),
    );
  }
}

/// Icon + title + message + a single action button. Used for Revoked and Error.
class _MessageAction extends StatelessWidget {
  final IconData icon;
  final String title;
  final String message;
  final String actionLabel;
  final VoidCallback onAction;

  const _MessageAction({
    required this.icon,
    required this.title,
    required this.message,
    required this.actionLabel,
    required this.onAction,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 64, color: theme.colorScheme.primary),
            const SizedBox(height: 16),
            Text(title, style: theme.textTheme.headlineSmall),
            const SizedBox(height: 8),
            Text(message, textAlign: TextAlign.center),
            const SizedBox(height: 24),
            ElevatedButton(onPressed: onAction, child: Text(actionLabel)),
          ],
        ),
      ),
    );
  }
}

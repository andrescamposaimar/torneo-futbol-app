import 'package:flutter/material.dart';

import '../../services/prode_auth_state.dart';
import 'prode_fixtures_screen.dart';

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

  /// Starts the Apple Sign-In flow, or null when unavailable (non-iOS) — the
  /// sign-in view hides the Apple button in that case.
  final VoidCallback? onAppleSignIn;

  /// Submits the entered DNI (used by the NeedsDniConfirmation view).
  /// Returns null on success, or a user-facing error message to show inline.
  final Future<String?> Function(String dni) onConfirmDni;

  const ProdeAuthView({
    super.key,
    required this.state,
    required this.onLogout,
    required this.onRetry,
    required this.onGoogleSignIn,
    required this.onAppleSignIn,
    required this.onConfirmDni,
  });

  @override
  Widget build(BuildContext context) {
    return switch (state) {
      ProdeAuthHydrating() ||
      ProdeAuthAuthenticating() =>
        const _Centered(child: CircularProgressIndicator()),
      ProdeAuthAuthenticated(:final stale) =>
        ProdeFixturesScreen(stale: stale, onLogout: onLogout),
      ProdeAuthUnauthenticated() => _SignInView(
          onGoogleSignIn: onGoogleSignIn,
          onAppleSignIn: onAppleSignIn,
        ),
      ProdeAuthNeedsDniConfirmation(:final nameHint) => _DniConfirmView(
          nameHint: nameHint,
          onConfirmDni: onConfirmDni,
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

/// Sign-in view for the Unauthenticated state. Google is always shown; the
/// Apple button appears only when [onAppleSignIn] is non-null (iOS).
class _SignInView extends StatelessWidget {
  final VoidCallback onGoogleSignIn;
  final VoidCallback? onAppleSignIn;

  const _SignInView({required this.onGoogleSignIn, this.onAppleSignIn});

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
            if (onAppleSignIn != null) ...[
              const SizedBox(height: 12),
              SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  onPressed: onAppleSignIn,
                  icon: const Icon(Icons.apple),
                  label: const Text('Continuar con Apple'),
                  style: OutlinedButton.styleFrom(
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                ),
              ),
            ],
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

/// DNI confirmation form, shown for the NeedsDniConfirmation state (new user
/// after SSO). Owns its own submission UX (text field, submitting spinner,
/// inline error) and delegates the network call to [onConfirmDni], which
/// returns null on success or a user-facing error message.
class _DniConfirmView extends StatefulWidget {
  final String? nameHint;
  final Future<String?> Function(String dni) onConfirmDni;

  const _DniConfirmView({required this.nameHint, required this.onConfirmDni});

  @override
  State<_DniConfirmView> createState() => _DniConfirmViewState();
}

class _DniConfirmViewState extends State<_DniConfirmView> {
  final _controller = TextEditingController();
  bool _submitting = false;
  String? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final dni = _controller.text.trim();
    if (dni.isEmpty) {
      setState(() => _error = 'Ingresá tu DNI.');
      return;
    }
    setState(() {
      _submitting = true;
      _error = null;
    });
    final error = await widget.onConfirmDni(dni);
    if (!mounted) return;
    // On success the controller transitions to Authenticated and this view is
    // replaced, so we only need to handle the error case here.
    if (error != null) {
      setState(() {
        _submitting = false;
        _error = error;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final greeting = (widget.nameHint == null || widget.nameHint!.isEmpty)
        ? 'Confirmá tu identidad'
        : '¡Hola, ${widget.nameHint}!';

    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.badge_outlined,
                size: 64, color: theme.colorScheme.primary),
            const SizedBox(height: 16),
            Text(greeting, style: theme.textTheme.headlineSmall),
            const SizedBox(height: 8),
            const Text(
              'Ingresá tu DNI para vincular tu cuenta con tu jugador del '
              'torneo.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 24),
            TextField(
              controller: _controller,
              keyboardType: TextInputType.number,
              enabled: !_submitting,
              autofocus: true,
              decoration: InputDecoration(
                labelText: 'DNI',
                border: const OutlineInputBorder(),
                errorText: _error,
              ),
              onSubmitted: (_) => _submitting ? null : _submit(),
            ),
            const SizedBox(height: 16),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: _submitting ? null : _submit,
                style: ElevatedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                child: _submitting
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('Confirmar'),
              ),
            ),
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

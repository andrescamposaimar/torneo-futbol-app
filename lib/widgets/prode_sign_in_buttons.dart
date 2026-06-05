import 'package:flutter/material.dart';

/// Shared SSO button block for the Prode sign-in flow.
///
/// Renders the Google button always; the Apple button only when
/// [onAppleSignIn] is non-null (iOS). Both use [OutlinedButton.icon] with the
/// same padding as the original [_SignInView] implementation in prode_auth_view.dart.
///
/// When [compact] is false (the default) buttons are full-width and stacked —
/// used by [ProdeAuthGate].
/// When [compact] is true buttons appear side-by-side in a centered [Row] as
/// smaller pill-style outlined buttons — used by [ProdeIdentityCard].
///
/// This is a pure [StatelessWidget]: it holds no state and drives no providers.
/// Callers are responsible for wiring the callbacks to the appropriate
/// controller methods.
class ProdeSignInButtons extends StatelessWidget {
  /// Called when the user taps "Continuar con Google".
  final VoidCallback onGoogleSignIn;

  /// Called when the user taps "Continuar con Apple".
  /// When null the Apple button is hidden (non-iOS devices).
  final VoidCallback? onAppleSignIn;

  /// When true, renders a compact side-by-side row of pill buttons instead of
  /// full-width stacked buttons.
  final bool compact;

  const ProdeSignInButtons({
    super.key,
    required this.onGoogleSignIn,
    this.onAppleSignIn,
    this.compact = false,
  });

  @override
  Widget build(BuildContext context) {
    if (compact) {
      return _buildCompactRow(context);
    }
    return _buildFullColumn(context);
  }

  Widget _buildFullColumn(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        OutlinedButton.icon(
          onPressed: onGoogleSignIn,
          icon: const Icon(Icons.account_circle),
          label: const Text('Continuar con Google'),
          style: OutlinedButton.styleFrom(
            padding: const EdgeInsets.symmetric(vertical: 14),
          ),
        ),
        if (onAppleSignIn != null) ...[
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: onAppleSignIn,
            icon: const Icon(Icons.apple),
            label: const Text('Continuar con Apple'),
            style: OutlinedButton.styleFrom(
              padding: const EdgeInsets.symmetric(vertical: 14),
            ),
          ),
        ],
      ],
    );
  }

  Widget _buildCompactRow(BuildContext context) {
    final compactStyle = OutlinedButton.styleFrom(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      textStyle: const TextStyle(fontSize: 13),
    );

    // Expanded halves + short labels: the row can never overflow, regardless
    // of device width (full "Continuar con…" labels did not fit side by side).
    return Row(
      children: [
        Expanded(
          child: OutlinedButton.icon(
            onPressed: onGoogleSignIn,
            icon: const Icon(Icons.account_circle, size: 18),
            label: const Text('Google', overflow: TextOverflow.ellipsis),
            style: compactStyle,
          ),
        ),
        if (onAppleSignIn != null) ...[
          const SizedBox(width: 8),
          Expanded(
            child: OutlinedButton.icon(
              onPressed: onAppleSignIn,
              icon: const Icon(Icons.apple, size: 18),
              label: const Text('Apple', overflow: TextOverflow.ellipsis),
              style: compactStyle,
            ),
          ),
        ],
      ],
    );
  }
}

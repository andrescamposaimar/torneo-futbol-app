import 'package:flutter/material.dart';

/// Shared SSO button block for the Prode sign-in flow.
///
/// Renders the Google button always; the Apple button only when
/// [onAppleSignIn] is non-null (iOS). Both use [OutlinedButton.icon] with the
/// same padding as the original [_SignInView] implementation in prode_auth_view.dart.
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

  const ProdeSignInButtons({
    super.key,
    required this.onGoogleSignIn,
    this.onAppleSignIn,
  });

  @override
  Widget build(BuildContext context) {
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
}

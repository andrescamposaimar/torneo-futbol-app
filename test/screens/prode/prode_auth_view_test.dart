import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/screens/prode/prode_auth_view.dart';
import 'package:torneo_futbol_app/services/prode_auth_state.dart';

void main() {
  // Pumps ProdeAuthView with [state] and returns counters for the callbacks so
  // tests can assert which action a given state's button triggers.
  Future<({int logout, int retry})> pumpView(
    WidgetTester tester,
    ProdeAuthState state, {
    VoidCallback? onTapAction,
  }) async {
    var logout = 0;
    var retry = 0;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: ProdeAuthView(
            state: state,
            onLogout: () => logout++,
            onRetry: () => retry++,
            onGoogleSignIn: () {},
            onAppleSignIn: null,
            onConfirmDni: (_) async => null,
          ),
        ),
      ),
    );
    return (logout: logout, retry: retry);
  }

  group('ProdeAuthView state routing', () {
    testWidgets('Hydrating shows a loading indicator', (tester) async {
      await pumpView(tester, const ProdeAuthHydrating());
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('Authenticating shows a loading indicator', (tester) async {
      await pumpView(tester, const ProdeAuthAuthenticating(provider: 'google'));
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    // NOTE: The three Authenticated-arm tests that previously asserted on
    // _ProdeHome copy ('¡Hola, Ana!', 'Sincronizando tus datos…', 'Cerrar sesión')
    // have been removed here (B-6). Those scenarios are now fully covered by
    // the ProdeFixturesScreen widget tests in prode_fixtures_screen_test.dart
    // (B-4), which pump ProdeFixturesScreen directly with a scoped provider
    // and assert on the stale banner, logout button, and match list.
    // The Authenticated arm in ProdeAuthView now renders a ConsumerStatefulWidget
    // (ProdeFixturesScreen) that requires a ProviderScope — pumping it inside
    // a bare MaterialApp without one would cause a ProviderScope not found error.

    testWidgets('Unauthenticated shows Google; Apple hidden when unavailable',
        (tester) async {
      // pumpView passes onAppleSignIn: null → Apple button hidden.
      await pumpView(tester, const ProdeAuthUnauthenticated());
      expect(find.text('Sumate al Prode'), findsOneWidget);
      expect(find.text('Continuar con Google'), findsOneWidget);
      expect(find.text('Continuar con Apple'), findsNothing);
    });

    testWidgets('Apple button shows and triggers onAppleSignIn when available',
        (tester) async {
      var apple = 0;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProdeAuthView(
              state: const ProdeAuthUnauthenticated(),
              onLogout: () {},
              onRetry: () {},
              onGoogleSignIn: () {},
              onAppleSignIn: () => apple++,
              onConfirmDni: (_) async => null,
            ),
          ),
        ),
      );
      expect(find.text('Continuar con Apple'), findsOneWidget);
      await tester.tap(find.text('Continuar con Apple'));
      expect(apple, equals(1));
    });

    testWidgets('Continuar con Google triggers onGoogleSignIn', (tester) async {
      var google = 0;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProdeAuthView(
              state: const ProdeAuthUnauthenticated(),
              onLogout: () {},
              onRetry: () {},
              onGoogleSignIn: () => google++,
              onAppleSignIn: null,
              onConfirmDni: (_) async => null,
            ),
          ),
        ),
      );
      await tester.tap(find.text('Continuar con Google'));
      expect(google, equals(1));
    });

    testWidgets('NeedsDniConfirmation shows the DNI form (greeting + field + button)',
        (tester) async {
      await pumpView(
        tester,
        const ProdeAuthNeedsDniConfirmation(
          intentToken: 'tok',
          nameHint: 'Ana',
        ),
      );
      expect(find.text('¡Hola, Ana!'), findsOneWidget);
      expect(find.widgetWithText(TextField, 'DNI'), findsOneWidget);
      expect(find.widgetWithText(ElevatedButton, 'Confirmar'), findsOneWidget);
    });

    testWidgets('submitting a DNI calls onConfirmDni and shows the returned error inline',
        (tester) async {
      String? submitted;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProdeAuthView(
              state: const ProdeAuthNeedsDniConfirmation(intentToken: 'tok'),
              onLogout: () {},
              onRetry: () {},
              onGoogleSignIn: () {},
              onAppleSignIn: null,
              onConfirmDni: (dni) async {
                submitted = dni;
                return 'Ese DNI no figura en el padrón.';
              },
            ),
          ),
        ),
      );

      await tester.enterText(find.byType(TextField), '12345678');
      await tester.tap(find.widgetWithText(ElevatedButton, 'Confirmar'));
      await tester.pumpAndSettle();

      expect(submitted, equals('12345678'));
      expect(find.text('Ese DNI no figura en el padrón.'), findsOneWidget);
    });

    testWidgets('empty DNI shows a validation message and does not call onConfirmDni',
        (tester) async {
      var called = false;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProdeAuthView(
              state: const ProdeAuthNeedsDniConfirmation(intentToken: 'tok'),
              onLogout: () {},
              onRetry: () {},
              onGoogleSignIn: () {},
              onAppleSignIn: null,
              onConfirmDni: (dni) async {
                called = true;
                return null;
              },
            ),
          ),
        ),
      );

      await tester.tap(find.widgetWithText(ElevatedButton, 'Confirmar'));
      await tester.pumpAndSettle();

      expect(called, isFalse);
      expect(find.text('Ingresá tu DNI.'), findsOneWidget);
    });

    testWidgets('Revoked shows the session-closed message and re-login CTA',
        (tester) async {
      var logout = 0;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProdeAuthView(
              state: const ProdeAuthRevoked(reason: 'session_revoked'),
              onLogout: () => logout++,
              onRetry: () {},
              onGoogleSignIn: () {},
              onAppleSignIn: null,
              onConfirmDni: (_) async => null,
            ),
          ),
        ),
      );
      expect(find.text('Tu sesión se cerró'), findsOneWidget);
      await tester.tap(find.text('Volver a ingresar'));
      expect(logout, equals(1));
    });

    testWidgets('Error shows friendly copy (not the raw message) and Reintentar triggers onRetry',
        (tester) async {
      var retry = 0;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProdeAuthView(
              // message is a raw exception string — must NOT reach the UI.
              state: const ProdeAuthError(
                code: 'bootstrap_error',
                message: 'Bad state: PlatformException(...)',
              ),
              onLogout: () {},
              onRetry: () => retry++,
              onGoogleSignIn: () {},
              onAppleSignIn: null,
              onConfirmDni: (_) async => null,
            ),
          ),
        ),
      );
      expect(find.text('Algo salió mal'), findsOneWidget);
      expect(find.textContaining('PlatformException'), findsNothing);
      await tester.tap(find.text('Reintentar'));
      expect(retry, equals(1));
    });
  });
}

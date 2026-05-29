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

    testWidgets('Authenticated (fresh) greets the user by name, no stale banner',
        (tester) async {
      await pumpView(
        tester,
        const ProdeAuthAuthenticated(
          user: ProdeUser(
            userId: 1,
            playerId: 2,
            name: 'Ana',
            sessionVersion: 1,
          ),
        ),
      );
      expect(find.text('¡Hola, Ana!'), findsOneWidget);
      expect(find.text('Sincronizando tus datos…'), findsNothing);
      expect(find.text('Cerrar sesión'), findsOneWidget);
    });

    testWidgets('Authenticated (stale) greets generically and shows the sync banner',
        (tester) async {
      await pumpView(
        tester,
        const ProdeAuthAuthenticated(
          user: ProdeUser(userId: 0, playerId: 0, name: '', sessionVersion: 3),
          stale: true,
        ),
      );
      expect(find.text('¡Hola!'), findsOneWidget);
      expect(find.text('Sincronizando tus datos…'), findsOneWidget);
    });

    testWidgets('Cerrar sesión triggers onLogout', (tester) async {
      var logout = 0;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProdeAuthView(
              state: const ProdeAuthAuthenticated(
                user: ProdeUser(
                  userId: 1,
                  playerId: 2,
                  name: 'Ana',
                  sessionVersion: 1,
                ),
              ),
              onLogout: () => logout++,
              onRetry: () {},
            ),
          ),
        ),
      );
      await tester.tap(find.text('Cerrar sesión'));
      expect(logout, equals(1));
    });

    testWidgets('Unauthenticated shows the coming-soon sign-in message',
        (tester) async {
      await pumpView(tester, const ProdeAuthUnauthenticated());
      expect(find.text('Sumate al Prode'), findsOneWidget);
    });

    testWidgets('NeedsDniConfirmation shows the coming-soon DNI message',
        (tester) async {
      await pumpView(
        tester,
        const ProdeAuthNeedsDniConfirmation(intentToken: 'tok'),
      );
      expect(find.text('Confirmá tu identidad'), findsOneWidget);
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
            ),
          ),
        ),
      );
      expect(find.text('Tu sesión se cerró'), findsOneWidget);
      await tester.tap(find.text('Volver a ingresar'));
      expect(logout, equals(1));
    });

    testWidgets('Error shows the message and Reintentar triggers onRetry',
        (tester) async {
      var retry = 0;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProdeAuthView(
              state: const ProdeAuthError(
                code: 'network_error',
                message: 'No hay conexión',
              ),
              onLogout: () {},
              onRetry: () => retry++,
            ),
          ),
        ),
      );
      expect(find.text('No hay conexión'), findsOneWidget);
      await tester.tap(find.text('Reintentar'));
      expect(retry, equals(1));
    });
  });
}

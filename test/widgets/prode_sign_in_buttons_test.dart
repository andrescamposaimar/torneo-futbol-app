import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/widgets/prode_sign_in_buttons.dart';

Future<void> _pump(
  WidgetTester tester, {
  VoidCallback? onGoogleSignIn,
  VoidCallback? onAppleSignIn,
}) async {
  await tester.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: ProdeSignInButtons(
          onGoogleSignIn: onGoogleSignIn ?? () {},
          onAppleSignIn: onAppleSignIn,
        ),
      ),
    ),
  );
}

void main() {
  group('ProdeSignInButtons', () {
    testWidgets('Google button is always rendered', (tester) async {
      await _pump(tester);
      expect(find.text('Continuar con Google'), findsOneWidget);
    });

    testWidgets('Apple button shown when onAppleSignIn is non-null', (tester) async {
      await _pump(tester, onAppleSignIn: () {});
      expect(find.text('Continuar con Apple'), findsOneWidget);
    });

    testWidgets('Apple button hidden when onAppleSignIn is null', (tester) async {
      await _pump(tester);
      expect(find.text('Continuar con Apple'), findsNothing);
    });

    testWidgets('tapping Google fires onGoogleSignIn', (tester) async {
      var calls = 0;
      await _pump(tester, onGoogleSignIn: () => calls++);
      await tester.tap(find.text('Continuar con Google'));
      expect(calls, equals(1));
    });

    testWidgets('tapping Apple fires onAppleSignIn', (tester) async {
      var calls = 0;
      await _pump(tester, onAppleSignIn: () => calls++);
      await tester.tap(find.text('Continuar con Apple'));
      expect(calls, equals(1));
    });
  });
}

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/widgets/prode_sign_in_buttons.dart';

Future<void> _pump(
  WidgetTester tester, {
  VoidCallback? onGoogleSignIn,
  VoidCallback? onAppleSignIn,
  bool compact = false,
}) async {
  await tester.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: ProdeSignInButtons(
          onGoogleSignIn: onGoogleSignIn ?? () {},
          onAppleSignIn: onAppleSignIn,
          compact: compact,
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

    testWidgets('branded icons: Google asset image + black Apple mark',
        (tester) async {
      await _pump(tester, onAppleSignIn: () {});

      // Google button uses the official multicolor G asset
      final image = tester.widget<Image>(find.byType(Image));
      expect(
        (image.image as AssetImage).assetName,
        'assets/images/google_logo.png',
      );

      // Apple button uses the Apple mark in brand black
      final appleIcon = tester.widget<Icon>(find.byIcon(Icons.apple));
      expect(appleIcon.color, Colors.black);
    });

    group('compact mode', () {
      testWidgets('shows short labels Google/Apple side by side',
          (tester) async {
        await _pump(tester, onAppleSignIn: () {}, compact: true);
        expect(find.text('Google'), findsOneWidget);
        expect(find.text('Apple'), findsOneWidget);
        expect(find.text('Continuar con Google'), findsNothing);
      });

      testWidgets('compact callbacks fire on tap', (tester) async {
        var google = 0;
        var apple = 0;
        await _pump(
          tester,
          onGoogleSignIn: () => google++,
          onAppleSignIn: () => apple++,
          compact: true,
        );
        await tester.tap(find.text('Google'));
        await tester.tap(find.text('Apple'));
        expect(google, equals(1));
        expect(apple, equals(1));
      });

      // Regression: BOTH buttons at narrow phone width must not overflow.
      // The original compact row (full 'Continuar con…' labels, no Expanded)
      // overflowed 56px on a 390pt device. Overflow = exception in tests.
      testWidgets('no overflow with both buttons at 320pt width',
          (tester) async {
        tester.view.physicalSize = const Size(320, 690);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.reset);

        await _pump(tester, onAppleSignIn: () {}, compact: true);

        expect(tester.takeException(), isNull);
        expect(find.text('Google'), findsOneWidget);
        expect(find.text('Apple'), findsOneWidget);
      });
    });
  });
}

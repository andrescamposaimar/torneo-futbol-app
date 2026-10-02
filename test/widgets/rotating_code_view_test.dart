import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/services/rotating_code.dart';
import 'package:torneo_futbol_app/widgets/rotating_code_view.dart';

void main() {
  group('groupCode', () {
    test('groups a 6-digit code into two runs of three', () {
      expect(groupCode('572189'), '572 189');
    });

    test('leaves a shorter code without a trailing/leading space', () {
      expect(groupCode('12'), '12');
    });

    test('handles an empty string', () {
      expect(groupCode(''), '');
    });
  });

  group('RotatingCodeView', () {
    // A fixed seed lets every assertion compute the exact expected code via
    // the same RotatingCode.code() the widget itself must call — this test
    // does not hardcode a digit string, it cross-checks against the shared
    // algorithm (spec "Rotating Liveness Code").
    const seed = 'c2VlZA';

    testWidgets('renders the grouped code computed for the current tick',
        (tester) async {
      final fixedNow = DateTime.fromMillisecondsSinceEpoch(1700000000 * 1000);
      final expected = RotatingCode.code(
        seed,
        fixedNow.millisecondsSinceEpoch ~/ 1000,
      );

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: RotatingCodeView(seed: seed, now: () => fixedNow),
          ),
        ),
      );

      expect(find.text(groupCode(expected)), findsOneWidget);
    });

    testWidgets('code changes across a 30s step boundary as the clock ticks',
        (tester) async {
      var current = DateTime.fromMillisecondsSinceEpoch(1700000000 * 1000);

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: RotatingCodeView(seed: seed, now: () => current),
          ),
        ),
      );

      final firstCode = RotatingCode.code(
        seed,
        current.millisecondsSinceEpoch ~/ 1000,
      );
      expect(find.text(groupCode(firstCode)), findsOneWidget);

      // Advance past the next 30s boundary.
      current = current.add(const Duration(seconds: 31));
      await tester.pump(const Duration(seconds: 31));

      final secondCode = RotatingCode.code(
        seed,
        current.millisecondsSinceEpoch ~/ 1000,
      );
      expect(find.text(groupCode(secondCode)), findsOneWidget);
    });

    testWidgets(
        'shows a liveness cue that visibly moves every second (progress '
        'indicator value changes) — proves a screenshot would look frozen',
        (tester) async {
      var current = DateTime.fromMillisecondsSinceEpoch(1700000000 * 1000);

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: RotatingCodeView(seed: seed, now: () => current),
          ),
        ),
      );

      final firstIndicator =
          tester.widget<CircularProgressIndicator>(find.byType(CircularProgressIndicator));
      final firstValue = firstIndicator.value;

      current = current.add(const Duration(seconds: 5));
      await tester.pump(const Duration(seconds: 5));

      final secondIndicator =
          tester.widget<CircularProgressIndicator>(find.byType(CircularProgressIndicator));

      expect(secondIndicator.value, isNot(equals(firstValue)));
    });

    testWidgets('shows the current wall-clock time, formatted HH:mm:ss',
        (tester) async {
      final fixedNow = DateTime(2026, 9, 30, 8, 5, 3);

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: RotatingCodeView(seed: seed, now: () => fixedNow),
          ),
        ),
      );

      expect(find.text('08:05:03'), findsOneWidget);
    });

    testWidgets('disposes its internal timer cleanly (no pending-timer error)',
        (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: RotatingCodeView(seed: seed, now: DateTime.now),
          ),
        ),
      );

      await tester.pumpWidget(const MaterialApp(home: Scaffold()));
      // No pending timer exception thrown at test teardown == pass.
    });

    group('countdown text (design D-UI: "Se actualiza en N segundos")', () {
      testWidgets('plural for any N other than 1', (tester) async {
        // step=30, 3s into the step → 27s remaining.
        final fixedNow = DateTime.fromMillisecondsSinceEpoch(3 * 1000);

        await tester.pumpWidget(
          MaterialApp(
            home: Scaffold(
              body: RotatingCodeView(seed: seed, now: () => fixedNow),
            ),
          ),
        );

        expect(find.text('Se actualiza en 27 segundos'), findsOneWidget);
      });

      testWidgets('singular exactly at N=1', (tester) async {
        // step=30, 29s into the step → 1s remaining.
        final fixedNow = DateTime.fromMillisecondsSinceEpoch(29 * 1000);

        await tester.pumpWidget(
          MaterialApp(
            home: Scaffold(
              body: RotatingCodeView(seed: seed, now: () => fixedNow),
            ),
          ),
        );

        expect(find.text('Se actualiza en 1 segundo'), findsOneWidget);
        expect(find.text('Se actualiza en 1 segundos'), findsNothing);
      });

      testWidgets('full step right at a rotation boundary (N=step)',
          (tester) async {
        final fixedNow = DateTime.fromMillisecondsSinceEpoch(30 * 1000);

        await tester.pumpWidget(
          MaterialApp(
            home: Scaffold(
              body: RotatingCodeView(seed: seed, now: () => fixedNow),
            ),
          ),
        );

        expect(find.text('Se actualiza en 30 segundos'), findsOneWidget);
      });
    });

    testWidgets(
        'renders as a tinted panel with the "Código de verificación" header '
        'and a 44x44 ring', (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: RotatingCodeView(seed: seed, now: DateTime.now),
          ),
        ),
      );

      expect(find.text('Código de verificación'), findsOneWidget);
      expect(find.byIcon(Icons.lock_outline), findsOneWidget);

      final ring = tester.widget<CircularProgressIndicator>(
          find.byType(CircularProgressIndicator));
      final ringBox = tester.renderObject(find.ancestor(
        of: find.byType(CircularProgressIndicator),
        matching: find.byType(SizedBox),
      )) as RenderBox;
      expect(ringBox.size.width, 44);
      expect(ringBox.size.height, 44);
      expect(ring.strokeWidth, 4);
    });

    testWidgets(
        'the code Semantics label reads the digits one by one, not grouped',
        (tester) async {
      final fixedNow = DateTime.fromMillisecondsSinceEpoch(1700000000 * 1000);
      final code =
          RotatingCode.code(seed, fixedNow.millisecondsSinceEpoch ~/ 1000);
      final spoken = code.split('').join(' ');

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: RotatingCodeView(seed: seed, now: () => fixedNow),
          ),
        ),
      );

      expect(
        find.bySemanticsLabel('Código de verificación $spoken'),
        findsOneWidget,
      );
    });

    group('no overflow across the device/text-scale matrix', () {
      for (final size in [
        const Size(375, 667),
        const Size(390, 844),
        const Size(430, 932),
      ]) {
        for (final scale in [1.0, 1.3]) {
          testWidgets('$size @ textScale $scale', (tester) async {
            tester.view.physicalSize = size;
            tester.view.devicePixelRatio = 1.0;
            addTearDown(tester.view.reset);

            await tester.pumpWidget(
              MediaQuery(
                data: MediaQueryData(textScaler: TextScaler.linear(scale)),
                child: MaterialApp(
                  home: Scaffold(
                    body: Align(
                      alignment: Alignment.topCenter,
                      child: RotatingCodeView(seed: seed, now: DateTime.now),
                    ),
                  ),
                ),
              ),
            );

            expect(tester.takeException(), isNull);
          });
        }
      }
    });
  });
}

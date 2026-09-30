import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/services/rotating_code.dart';
import 'package:torneo_futbol_app/widgets/rotating_code_view.dart';

void main() {
  group('RotatingCodeView', () {
    // A fixed seed lets every assertion compute the exact expected code via
    // the same RotatingCode.code() the widget itself must call — this test
    // does not hardcode a digit string, it cross-checks against the shared
    // algorithm (spec "Rotating Liveness Code").
    const seed = 'c2VlZA';

    testWidgets('renders the code computed for the current tick', (tester) async {
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

      expect(find.text(expected), findsOneWidget);
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
      expect(find.text(firstCode), findsOneWidget);

      // Advance past the next 30s boundary.
      current = current.add(const Duration(seconds: 31));
      await tester.pump(const Duration(seconds: 31));

      final secondCode = RotatingCode.code(
        seed,
        current.millisecondsSinceEpoch ~/ 1000,
      );
      expect(find.text(secondCode), findsOneWidget);
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
  });
}

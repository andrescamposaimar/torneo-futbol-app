import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/screens/prode/prediction_result_style.dart';

void main() {
  // -------------------------------------------------------------------------
  // T-11: PredictionResultStyle — pure presentation helper
  // -------------------------------------------------------------------------

  group('resolvePredictionStyle', () {
    // ---- exact_score (green, +3) ----

    test('exact_score + 3 → green color', () {
      final style = resolvePredictionStyle(method: 'exact_score', points: 3);
      expect(style.color, equals(Colors.green.shade700));
    });

    test('exact_score + 3 → label contains "+3"', () {
      final style = resolvePredictionStyle(method: 'exact_score', points: 3);
      expect(style.label, contains('+3'));
    });

    test('exact_score + 3 → label contains "Exacto"', () {
      final style = resolvePredictionStyle(method: 'exact_score', points: 3);
      expect(style.label, contains('Exacto'));
    });

    test('exact_score + 3 → icon is check_circle or similar (non-null)', () {
      final style = resolvePredictionStyle(method: 'exact_score', points: 3);
      expect(style.icon, isNotNull);
    });

    // ---- result_only with points >= 1 (amber/yellow, +1) ----

    test('result_only + 1 → amber/yellow color', () {
      final style = resolvePredictionStyle(method: 'result_only', points: 1);
      // Accept amber or yellow family
      expect(
        style.color == Colors.amber.shade700 || style.color == Colors.orange.shade700,
        isTrue,
        reason: 'Expected amber/yellow for result_only+1, got ${style.color}',
      );
    });

    test('result_only + 1 → label contains "+1"', () {
      final style = resolvePredictionStyle(method: 'result_only', points: 1);
      expect(style.label, contains('+1'));
    });

    test('result_only + 1 → label contains "Ganador"', () {
      final style = resolvePredictionStyle(method: 'result_only', points: 1);
      expect(style.label, contains('Ganador'));
    });

    // ---- result_only with points == 0 (red, wrong) ----

    test('result_only + 0 → red color', () {
      final style = resolvePredictionStyle(method: 'result_only', points: 0);
      expect(style.color, equals(Colors.red.shade700));
    });

    test('result_only + 0 → label contains "0 pts"', () {
      final style = resolvePredictionStyle(method: 'result_only', points: 0);
      expect(style.label, contains('0 pts'));
    });

    // ---- no_prediction (red/grey, 0 pts) ----

    test('no_prediction → red color', () {
      final style = resolvePredictionStyle(method: 'no_prediction', points: 0);
      expect(style.color, equals(Colors.red.shade700));
    });

    test('no_prediction + null points → red color (no crash)', () {
      final style = resolvePredictionStyle(method: 'no_prediction', points: null);
      expect(style.color, equals(Colors.red.shade700));
    });

    test('no_prediction → label contains "0 pts"', () {
      final style = resolvePredictionStyle(method: 'no_prediction', points: 0);
      expect(style.label, contains('0 pts'));
    });

    // ---- no_match_score (neutral/grey) ----

    test('no_match_score → neutral grey color', () {
      final style = resolvePredictionStyle(method: 'no_match_score', points: 0);
      expect(
        style.color == Colors.grey.shade600 || style.color == Colors.grey.shade500,
        isTrue,
        reason: 'Expected grey for no_match_score, got ${style.color}',
      );
    });

    test('no_match_score → label does not crash or return empty', () {
      final style = resolvePredictionStyle(method: 'no_match_score', points: 0);
      expect(style.label, isNotEmpty);
    });

    // ---- null method + null points → neutral (no crash) ----

    test('null method + null points → neutral grey (no crash)', () {
      final style = resolvePredictionStyle(method: null, points: null);
      expect(style.color, isNotNull); // must not throw
      expect(style.label, isNotEmpty);
      expect(style.icon, isNotNull);
    });

    test('null method + 3 points → green (method absent, infer from points)', () {
      // Pre-change fechas: points known but method may be null.
      // If points = 3, should still color green (exact score scenario).
      final style = resolvePredictionStyle(method: null, points: 3);
      expect(style.color, equals(Colors.green.shade700));
    });

    test('null method + 1 point → amber (method absent, points>=1)', () {
      final style = resolvePredictionStyle(method: null, points: 1);
      expect(
        style.color == Colors.amber.shade700 || style.color == Colors.orange.shade700,
        isTrue,
      );
    });

    test('null method + 0 points → red (method absent, points==0)', () {
      final style = resolvePredictionStyle(method: null, points: 0);
      expect(style.color, equals(Colors.red.shade700));
    });

    // ---- PredictionResultStyle value object ----

    test('PredictionResultStyle has color, label, icon fields', () {
      final style = resolvePredictionStyle(method: 'exact_score', points: 3);
      expect(style.color, isA<Color>());
      expect(style.label, isA<String>());
      expect(style.icon, isA<IconData>());
    });

    // ---- All branches return a non-null, non-throwing result ----

    test('all known methods return without throwing', () {
      final inputs = [
        ('exact_score', 3),
        ('result_only', 1),
        ('result_only', 0),
        ('no_prediction', 0),
        ('no_match_score', 0),
      ];
      for (final (method, pts) in inputs) {
        expect(
          () => resolvePredictionStyle(method: method, points: pts),
          returnsNormally,
          reason: 'Should not throw for method=$method, points=$pts',
        );
      }
    });

    test('unknown future method → neutral (no crash)', () {
      final style = resolvePredictionStyle(method: 'future_method_v2', points: 0);
      expect(style.color, isNotNull);
      expect(style.label, isNotEmpty);
    });
  });
}

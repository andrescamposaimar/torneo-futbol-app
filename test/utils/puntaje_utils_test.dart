import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/utils/puntaje_utils.dart';

void main() {
  group('formatearPuntaje · real ratings', () {
    test('7.5 keeps its decimal', () {
      expect(formatearPuntaje(7.5), '7.5');
    });

    test("'7,5' (comma decimal, what WordPress sends) parses to 7.5", () {
      expect(formatearPuntaje('7,5'), '7.5');
    });

    test("'7.5' (dot decimal) parses to 7.5", () {
      expect(formatearPuntaje('7.5'), '7.5');
    });

    test('a whole-number double drops its decimal', () {
      expect(formatearPuntaje(3.0), '3');
    });

    test('an int drops its decimal', () {
      expect(formatearPuntaje(3), '3');
    });

    test("'3' (whole-number string) drops its decimal", () {
      expect(formatearPuntaje('3'), '3');
    });
  });

  group('formatearPuntaje · zero means unrated, not "rated zero"', () {
    test('int 0 is unrated', () {
      expect(formatearPuntaje(0), '-');
    });

    test('double 0.0 is unrated', () {
      expect(formatearPuntaje(0.0), '-');
    });

    test("string '0' is unrated", () {
      expect(formatearPuntaje('0'), '-');
    });

    test("string '0,0' (comma decimal) is unrated", () {
      expect(formatearPuntaje('0,0'), '-');
    });

    test("string '0.0' (dot decimal) is unrated", () {
      expect(formatearPuntaje('0.0'), '-');
    });
  });

  group('formatearPuntaje · existing behaviour, now pinned', () {
    test('null is unrated', () {
      expect(formatearPuntaje(null), '-');
    });

    test('a bool is not a rating', () {
      expect(formatearPuntaje(true), '-');
    });

    test('an unparseable string is not a rating', () {
      expect(formatearPuntaje('abc'), '-');
    });

    test('an empty string is not a rating', () {
      expect(formatearPuntaje(''), '-');
    });
  });
}

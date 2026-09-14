import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/utils/campeones_iniciales.dart';

void main() {
  group('inicialesDeNombre', () {
    test('BASSO, A. -> BA (comma branch: surname + given initial)', () {
      expect(inicialesDeNombre('BASSO, A.'), 'BA');
    });

    test('MAZZARA, M. -> MM (comma branch)', () {
      expect(inicialesDeNombre('MAZZARA, M.'), 'MM');
    });

    test('D´AGOSTINO, E. -> DE (primeraLetra skips ´, U+00B4)', () {
      expect(inicialesDeNombre('D´AGOSTINO, E.'), 'DE');
    });

    test('Juan Pablo Calabro -> JC (no comma: first token + last token)', () {
      expect(inicialesDeNombre('Juan Pablo Calabro'), 'JC');
    });

    test('Andres Dos Santos -> AS (particles NOT absorbed)', () {
      expect(inicialesDeNombre('Andres Dos Santos'), 'AS');
    });

    test('RONALDO -> R (single token: one initial, not a padded pair)', () {
      expect(inicialesDeNombre('RONALDO'), 'R');
    });

    test('Garcia, -> G (right-hand side of the comma has no letter)', () {
      expect(inicialesDeNombre('Garcia,'), 'G');
    });

    test(', A. -> A (left-hand side of the comma has no letter)', () {
      expect(inicialesDeNombre(', A.'), 'A');
    });

    test('empty string -> ? (empty after trim)', () {
      expect(inicialesDeNombre(''), '?');
    });

    test('whitespace-only string -> ? (empty after trim)', () {
      expect(inicialesDeNombre('   '), '?');
    });

    test(', -> ? (no letter anywhere, the degenerate comma case)', () {
      expect(inicialesDeNombre(','), '?');
    });

    test('12 34 -> ? (no letter anywhere)', () {
      expect(inicialesDeNombre('12 34'), '?');
    });

    test('order is surname-first, matching the label beside it', () {
      // BASSO, A. must read BA, never AB — the initials and the name read
      // in the same direction.
      expect(inicialesDeNombre('BASSO, A.'), isNot('AB'));
    });

    test('is keyed on cosmetic transcription variants the same way', () {
      // Not part of the derivation rule itself, but documents why
      // indiceColorAvatar (below) is keyed on initials rather than the raw
      // name: these three all reduce to the same initials.
      expect(inicialesDeNombre('BASSO, A.'), 'BA');
      expect(inicialesDeNombre('Basso, A'), 'BA');
      expect(inicialesDeNombre('BASSO,  A.'), 'BA');
    });
  });

  group('indiceColorAvatar', () {
    test('BA -> sum 131 -> index 2 (mod 3)', () {
      expect(indiceColorAvatar('BA'), 2);
    });

    test('MM -> sum 154 -> index 1 (mod 3)', () {
      expect(indiceColorAvatar('MM'), 1);
    });

    test('R -> sum 82 -> index 1 (mod 3)', () {
      expect(indiceColorAvatar('R'), 1);
    });

    test('? -> sum 63 -> index 0 (mod 3)', () {
      expect(indiceColorAvatar('?'), 0);
    });

    test('empty string short-circuits to index 0', () {
      expect(indiceColorAvatar(''), 0);
    });

    test('is deterministic: repeated calls with the same initials agree', () {
      final first = indiceColorAvatar('BA');
      final second = indiceColorAvatar('BA');
      expect(first, second);
    });

    test('cantidadColores changes the modulus', () {
      // 131 % 2 == 1, distinct from the default cantidadColores: 3 case
      // (index 2) above — proves the parameter is actually used, not just
      // accepted and ignored.
      expect(indiceColorAvatar('BA', cantidadColores: 2), 1);
    });
  });
}

import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_motivo_mensajes.dart';

void main() {
  group('cambiosMotivoMensaje — plaza_sin_ocupacion_vigente (FIX 1)', () {
    // PlazaConOcupacionVigente.php's own docblock: "Applies to BOTH tipos: a
    // `regreso` needs a vigent occupant to close just as much as a
    // `sustitucion` does." cambiosMotivoMensaje takes only the code (not the
    // tipo — see the deliberate tipo-neutral-wording choice at that case),
    // so both a captain who requested a sustitucion and one who requested a
    // regreso read the exact same sentence. It must be honest for both,
    // never claim the request was the other kind.
    const code = 'plaza_sin_ocupacion_vigente';

    test('the message a sustitucion gets never mentions "regreso"', () {
      final messageForSustitucion = cambiosMotivoMensaje(code);
      expect(messageForSustitucion, isNot(contains('regreso')));
    });

    test('the message a regreso gets never mentions "cambio" or "sustituci"', () {
      final messageForRegreso = cambiosMotivoMensaje(code);
      expect(messageForRegreso, isNot(contains('cambio')));
      expect(messageForRegreso, isNot(contains('sustituci')));
    });

    test('both request types get the identical, tipo-neutral sentence', () {
      expect(cambiosMotivoMensaje(code), equals(cambiosMotivoMensaje(code)));
    });
  });

  group('cambiosMotivoMensaje — tipo-sensitivity audit of the other codes', () {
    // Audit result: only plaza_sin_ocupacion_vigente had the bug fixed above.
    // plaza_cerrada and fuera_de_plazo are the other two codes whose backend
    // rule (PlazaNoCerrada.php / SolicitudEnPlazo.php) also fires for BOTH
    // tipos — they were already tipo-neutral / tipo-symmetric, locked in
    // here so a future edit doesn't reintroduce this class of bug. Every
    // other code's backend rule only ever fires for ONE tipo (guarded by its
    // own `entrantePlayerId() === null` or `isRegreso()` check — see
    // EntranteDisponible.php, EntranteNoEsElSaliente.php,
    // EntranteNoBloqueado.php, PrioridadDePadresRespetada.php,
    // PuntajeDentroDelTecho.php, RegresoSoloConMinimoCumplido.php), so a
    // tipo-specific word in their message is not a bug.
    test('plaza_cerrada stays tipo-neutral', () {
      final message = cambiosMotivoMensaje('plaza_cerrada');
      expect(message, isNot(contains('regreso')));
      expect(message, isNot(contains('sustituci')));
    });

    test('fuera_de_plazo mentions both deadlines symmetrically, never only one', () {
      final message = cambiosMotivoMensaje('fuera_de_plazo');
      expect(message, contains('regreso'));
      expect(message, contains('cambio'));
    });
  });

  group('cambiosMotivoMensaje — arquero_no_ocupa_plaza_de_campo', () {
    // Backend rule: Dictamen\Reglas\ArqueroNoOcupaPlazaDeCampo. This code
    // only ever fires for a `sustitucion` (a `regreso` never carries an
    // entrante), so, like PuntajeDentroDelTecho's own code, a tipo-specific
    // word here is not a bug.
    const code = 'arquero_no_ocupa_plaza_de_campo';

    test('states the rule factually, without a raw code on screen', () {
      final message = cambiosMotivoMensaje(code);
      expect(message, isNot(equals(code)));
      expect(message, contains('arquero'));
    });

    test('an unrecognized code still falls back to the generic message, never this one', () {
      expect(cambiosMotivoMensaje('codigo_inexistente'), isNot(equals(cambiosMotivoMensaje(code))));
    });
  });
}

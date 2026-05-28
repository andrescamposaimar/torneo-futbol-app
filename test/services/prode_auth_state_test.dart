import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/services/prode_auth_state.dart';

void main() {
  group('ProdeUser', () {
    const user = ProdeUser(
      userId: 1,
      playerId: 100,
      name: 'Juan Pérez',
      sessionVersion: 3,
    );

    test('equality: two users with same fields are equal', () {
      const other = ProdeUser(
        userId: 1,
        playerId: 100,
        name: 'Juan Pérez',
        sessionVersion: 3,
      );
      expect(user, equals(other));
    });

    test('equality: differing userId → not equal', () {
      const other = ProdeUser(
        userId: 2,
        playerId: 100,
        name: 'Juan Pérez',
        sessionVersion: 3,
      );
      expect(user, isNot(equals(other)));
    });

    test('toString includes all fields', () {
      final s = user.toString();
      expect(s, contains('userId: 1'));
      expect(s, contains('playerId: 100'));
      expect(s, contains('sessionVersion: 3'));
    });
  });

  group('ProdeAuthState — pattern matching', () {
    String describeState(ProdeAuthState state) {
      return switch (state) {
        ProdeAuthUnauthenticated() => 'unauthenticated',
        ProdeAuthHydrating() => 'hydrating',
        ProdeAuthAuthenticating(:final provider) => 'authenticating:$provider',
        ProdeAuthNeedsDniConfirmation(:final intentToken, :final nameHint) =>
          'dni_required:${intentToken}_hint:$nameHint',
        ProdeAuthAuthenticated(:final user) => 'authenticated:${user.userId}',
        ProdeAuthRevoked(:final reason) => 'revoked:$reason',
        ProdeAuthError(:final code) => 'error:$code',
      };
    }

    test('ProdeAuthUnauthenticated matches correctly', () {
      expect(
        describeState(const ProdeAuthUnauthenticated()),
        equals('unauthenticated'),
      );
    });

    test('ProdeAuthHydrating matches correctly', () {
      expect(
        describeState(const ProdeAuthHydrating()),
        equals('hydrating'),
      );
    });

    test('ProdeAuthAuthenticating carries provider field', () {
      expect(
        describeState(const ProdeAuthAuthenticating(provider: 'google')),
        equals('authenticating:google'),
      );
      expect(
        describeState(const ProdeAuthAuthenticating(provider: 'apple')),
        equals('authenticating:apple'),
      );
    });

    test('ProdeAuthNeedsDniConfirmation carries intentToken and nameHint', () {
      const state = ProdeAuthNeedsDniConfirmation(
        intentToken: 'tok123',
        nameHint: 'Juan',
      );
      expect(
        describeState(state),
        equals('dni_required:tok123_hint:Juan'),
      );
    });

    test('ProdeAuthNeedsDniConfirmation nameHint is nullable', () {
      const state = ProdeAuthNeedsDniConfirmation(intentToken: 'tok', nameHint: null);
      expect(state.nameHint, isNull);
    });

    test('ProdeAuthAuthenticated carries user', () {
      const state = ProdeAuthAuthenticated(
        user: ProdeUser(
          userId: 42,
          playerId: 99,
          name: 'Test',
          sessionVersion: 1,
        ),
      );
      expect(describeState(state), equals('authenticated:42'));
    });

    test('ProdeAuthRevoked carries reason', () {
      const state = ProdeAuthRevoked(reason: 'admin_unlink');
      expect(describeState(state), equals('revoked:admin_unlink'));
    });

    test('ProdeAuthError carries code and message', () {
      const state = ProdeAuthError(code: 'network_error', message: 'Timeout');
      expect(describeState(state), equals('error:network_error'));
    });
  });

  group('ProdeAuthState — equality', () {
    test('Unauthenticated instances are equal', () {
      expect(
        const ProdeAuthUnauthenticated(),
        isNot(equals(const ProdeAuthHydrating())),
      );
    });

    test('Authenticating equality depends on provider', () {
      const a = ProdeAuthAuthenticating(provider: 'google');
      const b = ProdeAuthAuthenticating(provider: 'google');
      const c = ProdeAuthAuthenticating(provider: 'apple');
      expect(a, equals(b));
      expect(a, isNot(equals(c)));
    });

    test('Authenticated equality depends on user', () {
      const user = ProdeUser(
        userId: 1,
        playerId: 1,
        name: 'A',
        sessionVersion: 1,
      );
      const a = ProdeAuthAuthenticated(user: user);
      const b = ProdeAuthAuthenticated(user: user);
      expect(a, equals(b));
    });

    test('Revoked equality depends on reason', () {
      const a = ProdeAuthRevoked(reason: 'admin_unlink');
      const b = ProdeAuthRevoked(reason: 'admin_unlink');
      const c = ProdeAuthRevoked(reason: 'user_deleted');
      expect(a, equals(b));
      expect(a, isNot(equals(c)));
    });

    test('Error equality depends on code and message', () {
      const a = ProdeAuthError(code: 'net', message: 'fail');
      const b = ProdeAuthError(code: 'net', message: 'fail');
      const c = ProdeAuthError(code: 'other', message: 'fail');
      expect(a, equals(b));
      expect(a, isNot(equals(c)));
    });

    test('NeedsDniConfirmation equality depends on intentToken and nameHint', () {
      const a = ProdeAuthNeedsDniConfirmation(intentToken: 't', nameHint: 'Juan');
      const b = ProdeAuthNeedsDniConfirmation(intentToken: 't', nameHint: 'Juan');
      const c = ProdeAuthNeedsDniConfirmation(intentToken: 'x', nameHint: 'Juan');
      expect(a, equals(b));
      expect(a, isNot(equals(c)));
    });
  });

  group('ProdeAuthNeedsDniConfirmation — intentToken is redacted in toString', () {
    test('toString does not leak intentToken', () {
      const state = ProdeAuthNeedsDniConfirmation(intentToken: 'secret_tok');
      expect(state.toString(), isNot(contains('secret_tok')));
      expect(state.toString(), contains('[redacted]'));
    });
  });
}

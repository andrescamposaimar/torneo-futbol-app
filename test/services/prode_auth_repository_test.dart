import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';

/// Register the in-memory platform implementation before tests run.
void _setUpFakeStorage(Map<String, String> store) {
  FlutterSecureStoragePlatform.instance =
      TestFlutterSecureStoragePlatform(store);
}

void main() {
  group('ProdeAuthRepository', () {
    late Map<String, String> store;
    late ProdeAuthRepository repo;

    setUp(() {
      store = {};
      _setUpFakeStorage(store);
      repo = ProdeAuthRepository();
    });

    test('read returns null when storage is empty', () async {
      expect(await repo.readAccessToken(), isNull);
      expect(await repo.readRefreshToken(), isNull);
      expect(await repo.readSessionVersion(), isNull);
      expect(await repo.readTenantId(), isNull);
    });

    test('write and read round-trip for all fields via write()', () async {
      await repo.write(
        accessToken: 'access-abc',
        refreshToken: 'refresh-xyz',
        sessionVersion: '7',
        tenantId: 'marianista',
      );

      expect(await repo.readAccessToken(), equals('access-abc'));
      expect(await repo.readRefreshToken(), equals('refresh-xyz'));
      expect(await repo.readSessionVersion(), equals('7'));
      expect(await repo.readTenantId(), equals('marianista'));
    });

    test('bulk write stores all four fields', () async {
      await repo.write(
        accessToken: 'acc',
        refreshToken: 'ref',
        sessionVersion: '3',
        tenantId: 'marianista',
      );

      expect(await repo.readAccessToken(), equals('acc'));
      expect(await repo.readRefreshToken(), equals('ref'));
      expect(await repo.readSessionVersion(), equals('3'));
      expect(await repo.readTenantId(), equals('marianista'));
    });

    test('clear removes all token fields', () async {
      await repo.write(
        accessToken: 'acc',
        refreshToken: 'ref',
        sessionVersion: '1',
        tenantId: 'marianista',
      );

      await repo.clear();

      expect(await repo.readAccessToken(), isNull);
      expect(await repo.readRefreshToken(), isNull);
      expect(await repo.readSessionVersion(), isNull);
      expect(await repo.readTenantId(), isNull);
    });

    test('clear on empty storage does not throw', () async {
      expect(() => repo.clear(), returnsNormally);
    });

    test('write overwrites existing value', () async {
      await repo.write(
        accessToken: 'old-token',
        refreshToken: 'ref',
        sessionVersion: '1',
        tenantId: 'marianista',
      );
      await repo.write(
        accessToken: 'new-token',
        refreshToken: 'ref',
        sessionVersion: '1',
        tenantId: 'marianista',
      );

      expect(await repo.readAccessToken(), equals('new-token'));
    });

    test('writeTokens persists rotating fields and preserves tenantId',
        () async {
      // Seed initial state via the bulk write (login path).
      await repo.write(
        accessToken: 'old-acc',
        refreshToken: 'old-ref',
        sessionVersion: '1',
        tenantId: 'marianista',
      );

      // Refresh path: rotate access/refresh/session_version only.
      await repo.writeTokens(
        accessToken: 'new-acc',
        refreshToken: 'new-ref',
        sessionVersion: '2',
      );

      expect(await repo.readAccessToken(), equals('new-acc'));
      expect(await repo.readRefreshToken(), equals('new-ref'));
      expect(await repo.readSessionVersion(), equals('2'));
      expect(
        await repo.readTenantId(),
        equals('marianista'),
        reason: 'writeTokens must not touch tenantId',
      );
    });

    test('single-key persistence: only one storage key exists after write()',
        () async {
      await repo.write(
        accessToken: 'acc',
        refreshToken: 'ref',
        sessionVersion: '1',
        tenantId: 'marianista',
      );

      // The underlying storage map must contain exactly one key: 'prode_tokens'.
      expect(store.keys, hasLength(1));
      expect(store.keys.single, equals('prode_tokens'));

      // And the value must be a valid JSON object containing all four fields.
      final decoded = json.decode(store['prode_tokens']!) as Map<String, dynamic>;
      expect(decoded['access_token'], equals('acc'));
      expect(decoded['refresh_token'], equals('ref'));
      expect(decoded['session_version'], equals('1'));
      expect(decoded['tenant_id'], equals('marianista'));
    });

    test('sequential writes coalesce into the same single key', () async {
      await repo.write(
        accessToken: 'a',
        refreshToken: 'r',
        sessionVersion: '1',
        tenantId: 'marianista',
      );
      await repo.writeTokens(
        accessToken: 'a2',
        refreshToken: 'r2',
        sessionVersion: '2',
      );

      // Still only one key regardless of how many write calls occurred.
      expect(store.keys, hasLength(1));
      expect(store.keys.single, equals('prode_tokens'));
    });

    // -------------------------------------------------------------------------
    // readAll() tests
    // -------------------------------------------------------------------------

    group('readAll()', () {
      test('returns all-null snapshot when storage is empty', () async {
        final snapshot = await repo.readAll();
        expect(snapshot.accessToken, isNull);
        expect(snapshot.refreshToken, isNull);
        expect(snapshot.sessionVersion, isNull);
        expect(snapshot.tenantId, isNull);
      });

      test('returns all fields after a bulk write', () async {
        await repo.write(
          accessToken: 'acc',
          refreshToken: 'ref',
          sessionVersion: '9',
          tenantId: 'marianista',
        );

        final snapshot = await repo.readAll();
        expect(snapshot.accessToken, equals('acc'));
        expect(snapshot.refreshToken, equals('ref'));
        expect(snapshot.sessionVersion, equals('9'));
        expect(snapshot.tenantId, equals('marianista'));
      });

      test('returns updated tokens after writeTokens', () async {
        await repo.write(
          accessToken: 'old-acc',
          refreshToken: 'old-ref',
          sessionVersion: '1',
          tenantId: 'marianista',
        );
        await repo.writeTokens(
          accessToken: 'new-acc',
          refreshToken: 'new-ref',
          sessionVersion: '2',
        );

        final snapshot = await repo.readAll();
        expect(snapshot.accessToken, equals('new-acc'));
        expect(snapshot.refreshToken, equals('new-ref'));
        expect(snapshot.sessionVersion, equals('2'));
        expect(snapshot.tenantId, equals('marianista'));
      });

      test('returns all-null snapshot after clear', () async {
        await repo.write(
          accessToken: 'acc',
          refreshToken: 'ref',
          sessionVersion: '1',
          tenantId: 'marianista',
        );
        await repo.clear();

        final snapshot = await repo.readAll();
        expect(snapshot.accessToken, isNull);
        expect(snapshot.refreshToken, isNull);
        expect(snapshot.sessionVersion, isNull);
        expect(snapshot.tenantId, isNull);
      });

      test('single storage read: readAll reads the blob once', () async {
        // Verify readAll uses the same single key as individual reads.
        await repo.write(
          accessToken: 'acc',
          refreshToken: 'ref',
          sessionVersion: '3',
          tenantId: 'marianista',
        );

        final snapshot = await repo.readAll();
        final individual = [
          await repo.readAccessToken(),
          await repo.readRefreshToken(),
          await repo.readSessionVersion(),
          await repo.readTenantId(),
        ];

        expect(snapshot.accessToken, equals(individual[0]));
        expect(snapshot.refreshToken, equals(individual[1]));
        expect(snapshot.sessionVersion, equals(individual[2]));
        expect(snapshot.tenantId, equals(individual[3]));
      });
    });

    // -------------------------------------------------------------------------
    // onTokensChanged callback tests
    // -------------------------------------------------------------------------

    group('onTokensChanged callback', () {
      test('fires after write()', () async {
        var callCount = 0;
        repo.onTokensChanged = () => callCount++;

        await repo.write(
          accessToken: 'acc',
          refreshToken: 'ref',
          sessionVersion: '1',
          tenantId: 'marianista',
        );

        expect(callCount, equals(1));
      });

      test('fires after writeTokens()', () async {
        var callCount = 0;
        repo.onTokensChanged = () => callCount++;

        await repo.writeTokens(
          accessToken: 'acc',
          refreshToken: 'ref',
          sessionVersion: '1',
        );

        expect(callCount, equals(1));
      });

      test('fires after clear()', () async {
        await repo.write(
          accessToken: 'acc',
          refreshToken: 'ref',
          sessionVersion: '1',
          tenantId: 'marianista',
        );

        var callCount = 0;
        repo.onTokensChanged = () => callCount++;

        await repo.clear();

        expect(callCount, equals(1));
      });

      test('fires once per write operation (not twice)', () async {
        var callCount = 0;
        repo.onTokensChanged = () => callCount++;

        await repo.write(
          accessToken: 'a1',
          refreshToken: 'r1',
          sessionVersion: '1',
          tenantId: 'marianista',
        );
        await repo.writeTokens(
          accessToken: 'a2',
          refreshToken: 'r2',
          sessionVersion: '2',
        );

        expect(callCount, equals(2));
      });

      test('does not fire when onTokensChanged is null', () async {
        repo.onTokensChanged = null;
        // Should not throw
        await repo.write(
          accessToken: 'acc',
          refreshToken: 'ref',
          sessionVersion: '1',
          tenantId: 'marianista',
        );
        await repo.clear();
      });
    });
  });
}

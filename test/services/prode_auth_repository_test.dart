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

    test('write and read round-trip for individual keys', () async {
      await repo.writeAccessToken('access-abc');
      await repo.writeRefreshToken('refresh-xyz');
      await repo.writeSessionVersion('7');
      await repo.writeTenantId('marianista');

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
      await repo.writeAccessToken('old-token');
      await repo.writeAccessToken('new-token');

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

    test('individual writes coalesce into the same single key', () async {
      await repo.writeAccessToken('a');
      await repo.writeRefreshToken('r');
      await repo.writeTenantId('marianista');

      // Still only one key regardless of how many individual writes occurred.
      expect(store.keys, hasLength(1));
      expect(store.keys.single, equals('prode_tokens'));
    });
  });
}

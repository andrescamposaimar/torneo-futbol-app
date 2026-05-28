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
  });
}

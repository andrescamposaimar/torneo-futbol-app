import 'dart:io';

import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:torneo_futbol_app/models/credencial.dart';
import 'package:torneo_futbol_app/services/credencial_photo_store.dart';
import 'package:torneo_futbol_app/services/credencial_repository.dart';

/// Simulates a flutter_secure_storage platform whose `read()` always throws —
/// the real-world shape of a Keychain/Keystore decrypt failure (design D5b:
/// "Any read/decrypt exception = no cache + delete the key"). `write()` and
/// `delete()` still succeed against an in-memory [store] so the test can
/// assert the key is actually removed afterward.
class _ThrowingReadFlutterSecureStoragePlatform
    extends FlutterSecureStoragePlatform {
  final Map<String, String> store;
  bool deleteWasCalled = false;

  _ThrowingReadFlutterSecureStoragePlatform(this.store);

  @override
  Future<void> write({
    required String key,
    required String value,
    required Map<String, String> options,
  }) async {
    store[key] = value;
  }

  @override
  Future<String?> read({
    required String key,
    required Map<String, String> options,
  }) async {
    throw PlatformException(code: 'decrypt_failed', message: 'simulated');
  }

  @override
  Future<bool> containsKey({
    required String key,
    required Map<String, String> options,
  }) async =>
      store.containsKey(key);

  @override
  Future<void> delete({
    required String key,
    required Map<String, String> options,
  }) async {
    deleteWasCalled = true;
    store.remove(key);
  }

  @override
  Future<Map<String, String>> readAll({
    required Map<String, String> options,
  }) async =>
      Map.of(store);

  @override
  Future<void> deleteAll({required Map<String, String> options}) async {
    store.clear();
  }
}

/// A [CredencialPhotoStore] whose [write] always fails — used to prove the
/// repository's save() ordering contract (design D5: photo bytes are written
/// BEFORE the JSON blob) without instrumenting real file timestamps: if the
/// photo write fails, the JSON key must never be persisted.
class _ThrowingWritePhotoStore extends CredencialPhotoStore {
  _ThrowingWritePhotoStore() : super();

  @override
  Future<void> write(Uint8List bytes, int photoId) async {
    throw Exception('simulated photo write failure');
  }
}

void _setUpFakeStorage(Map<String, String> store) {
  FlutterSecureStoragePlatform.instance =
      TestFlutterSecureStoragePlatform(store);
}

/// Bytes that sniff as a (fake) JPEG — [CredencialPhotoStore.read] now
/// requires a recognized image signature (design D5, rev 9: "magic-byte
/// sniff passes; no re-hash"), so every fixture that must survive a
/// round-trip through the real store needs a real signature prefix, not
/// arbitrary text bytes.
Uint8List _fakePhotoBytes(String label) => Uint8List.fromList(
    [0xFF, 0xD8, 0xFF, ...label.codeUnits]);

CredencialResponse _activeResponse({int photoId = 1}) {
  return CredencialResponse(
    state: CredencialCardState.active,
    credential: Credencial(
      id: 'cred-1',
      issuedAt: '2026-09-29 00:00:00',
      expiresAt: '2027-09-29 00:00:00',
      playerId: 1,
      fullName: 'Juan Perez',
      dni: '30111222',
      photo: CredencialPhoto(id: photoId, url: 'https://example.com/p.jpg'),
      codeSeed: 'c2VlZA',
      code: const CredencialCode(alg: 'SHA256', step: 30, digits: 6),
    ),
  );
}

void main() {
  group('CredencialRepository', () {
    late Map<String, String> store;
    late Directory tempRoot;
    late CredencialPhotoStore photoStore;
    late CredencialRepository repo;

    setUp(() async {
      store = {};
      _setUpFakeStorage(store);
      tempRoot = await Directory.systemTemp.createTemp('credencial_repo_');
      photoStore = CredencialPhotoStore(baseDirResolver: () async => tempRoot);
      repo = CredencialRepository(photoStore: photoStore);
    });

    tearDown(() async {
      if (await tempRoot.exists()) {
        await tempRoot.delete(recursive: true);
      }
    });

    test('readCached returns null when nothing is cached', () async {
      expect(await repo.readCached(), isNull);
    });

    test(
        'save then readCached round-trips an active credential with photo bytes on disk',
        () async {
      final bytes = _fakePhotoBytes('photo-bytes');
      final response = _activeResponse(photoId: 101);

      await repo.save(response, photoBytes: bytes);
      final result = await repo.readCached();

      expect(result, equals(response));
    });

    test(
        'readCached returns null when the credential has no matching photo on disk',
        () async {
      // Save the JSON without ever writing the matching photo bytes.
      final response = _activeResponse(photoId: 404);
      await repo.save(response); // no photoBytes supplied

      expect(await repo.readCached(), isNull);
    });

    test(
        'readCached returns the response unchanged for non-active states (no photo to verify)',
        () async {
      const response = CredencialResponse(state: CredencialCardState.blocked);
      // Directly exercise save()/readCached() round trip for a null-credential state.
      await repo.save(response);

      expect(await repo.readCached(), equals(response));
    });

    test('malformed JSON in storage deletes the key and returns null',
        () async {
      store['credencial_v1'] = 'not valid json {{{';

      final result = await repo.readCached();

      expect(result, isNull);
      expect(store.containsKey('credencial_v1'), isFalse);
    });

    test(
        'a secure-storage decrypt exception on read deletes the key and returns null',
        () async {
      final throwingStore = {'credencial_v1': 'irrelevant-because-read-throws'};
      final fakePlatform =
          _ThrowingReadFlutterSecureStoragePlatform(throwingStore);
      FlutterSecureStoragePlatform.instance = fakePlatform;

      final throwingRepo = CredencialRepository(photoStore: photoStore);
      final result = await throwingRepo.readCached();

      expect(result, isNull);
      expect(fakePlatform.deleteWasCalled, isTrue);
      expect(throwingStore.containsKey('credencial_v1'), isFalse);
    });

    test(
        'save writes the photo bytes before the JSON key: a photo-write failure leaves no JSON persisted',
        () async {
      final throwingPhotoStore = _ThrowingWritePhotoStore();
      final orderedRepo = CredencialRepository(photoStore: throwingPhotoStore);
      final response = _activeResponse(photoId: 55);
      final bytes = _fakePhotoBytes('bytes');

      await expectLater(
        orderedRepo.save(response, photoBytes: bytes),
        throwsException,
      );

      expect(store.containsKey('credencial_v1'), isFalse);
    });

    test('clear wipes both the storage key and the photo directory', () async {
      final response = _activeResponse(photoId: 77);
      final bytes = _fakePhotoBytes('bytes');
      await repo.save(response, photoBytes: bytes);

      await repo.clear();

      expect(store.containsKey('credencial_v1'), isFalse);
      expect(await photoStore.read(77), isNull);
    });

    test('save garbage-collects a previous photo once the new one is written',
        () async {
      const oldPhotoId = 10;
      const newPhotoId = 20;

      final first = _activeResponse(photoId: oldPhotoId);
      await repo.save(first, photoBytes: _fakePhotoBytes('old'));

      final second = _activeResponse(photoId: newPhotoId);
      await repo.save(second, photoBytes: _fakePhotoBytes('new'));

      expect(await photoStore.read(oldPhotoId), isNull);
      expect(await photoStore.read(newPhotoId), isNotNull);
    });

    test(
        'CRITICAL (verify-report 1575, slice 3b; still true under the id-based '
        'design): save() with photoBytes:null for a NEW photoId that was never '
        'written must NOT delete the previously-cached photo for the OLD '
        'photoId', () async {
      const oldPhotoId = 10;
      final oldBytes = _fakePhotoBytes('old');

      final first = _activeResponse(photoId: oldPhotoId);
      await repo.save(first, photoBytes: oldBytes);
      expect(await photoStore.read(oldPhotoId), isNotNull);

      // Simulates a failed/skipped photo download during an id rotation: the
      // new response is persisted, but no bytes were ever written for the
      // NEW photoId — so no file exists for it yet.
      final second = _activeResponse(photoId: 999);
      await repo.save(second, photoBytes: null);

      expect(
        await photoStore.read(oldPhotoId),
        isNotNull,
        reason: 'the last known-good photo must survive a failed download, '
            'not be garbage-collected out from under the player',
      );
      expect(await photoStore.read(999), isNull);
    });
  });
}

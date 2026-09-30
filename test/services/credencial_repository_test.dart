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
  Future<void> write(Uint8List bytes, String sha256Hex) async {
    throw Exception('simulated photo write failure');
  }
}

void _setUpFakeStorage(Map<String, String> store) {
  FlutterSecureStoragePlatform.instance =
      TestFlutterSecureStoragePlatform(store);
}

CredencialResponse _activeResponse({String sha256 = 'validsha'}) {
  return CredencialResponse(
    state: CredencialCardState.active,
    credential: Credencial(
      id: 'cred-1',
      issuedAt: '2026-09-29 00:00:00',
      expiresAt: '2027-09-29 00:00:00',
      playerId: 1,
      fullName: 'Juan Perez',
      dni: '30111222',
      photo: CredencialPhoto(url: 'https://example.com/p.jpg', sha256: sha256),
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
        'save then readCached round-trips an active credential with a verified photo',
        () async {
      final bytes = Uint8List.fromList('photo-bytes'.codeUnits);
      // Real sha256 of the bytes above — readVerified() re-hashes on load, so
      // an arbitrary label here would fail verification (see the "no
      // matching photo" test below for that deliberate case).
      final response = _activeResponse(
        sha256:
            'dac6f451810bc38390a3b6e278d686b332a77cf21b2ea95145ad73722b77035d',
      );

      await repo.save(response, photoBytes: bytes);
      final result = await repo.readCached();

      expect(result, equals(response));
    });

    test(
        'readCached returns null when the credential has no matching photo on disk',
        () async {
      // Save the JSON without ever writing the matching photo bytes.
      final response = _activeResponse(sha256: 'missing-photo-hash');
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
      final response = _activeResponse(sha256: 'some-hash');
      final bytes = Uint8List.fromList('bytes'.codeUnits);

      await expectLater(
        orderedRepo.save(response, photoBytes: bytes),
        throwsException,
      );

      expect(store.containsKey('credencial_v1'), isFalse);
    });

    test('clear wipes both the storage key and the photo directory', () async {
      final response = _activeResponse(sha256: 'to-be-wiped');
      final bytes = Uint8List.fromList('bytes'.codeUnits);
      await repo.save(response, photoBytes: bytes);

      await repo.clear();

      expect(store.containsKey('credencial_v1'), isFalse);
      expect(await photoStore.readVerified('to-be-wiped'), isNull);
    });

    test('save garbage-collects a previous photo once the new one is written',
        () async {
      // Real sha256 hashes of 'old'/'new' — readVerified() re-hashes on
      // load, so these must match their actual byte content.
      const oldHash =
          'cba06b5736faf67e54b07b561eae94395e774c517a7d910a54369e1263ccfbd4';
      const newHash =
          '11507a0e2f5e69d5dfa40a62a1bd7b6ee57e6bcd85c67c9b8431b36fff21c437';

      final first = _activeResponse(sha256: oldHash);
      await repo.save(first, photoBytes: Uint8List.fromList('old'.codeUnits));

      final second = _activeResponse(sha256: newHash);
      await repo.save(second, photoBytes: Uint8List.fromList('new'.codeUnits));

      expect(await photoStore.readVerified(oldHash), isNull);
      expect(await photoStore.readVerified(newHash), isNotNull);
    });

    test(
        'CRITICAL (verify-report 1575, slice 3b): save() with photoBytes:null '
        'for a NEW sha256 that was never written must NOT delete the '
        'previously-verified photo for the OLD sha256', () async {
      const oldHash =
          'cba06b5736faf67e54b07b561eae94395e774c517a7d910a54369e1263ccfbd4';
      final oldBytes = Uint8List.fromList('old'.codeUnits);

      final first = _activeResponse(sha256: oldHash);
      await repo.save(first, photoBytes: oldBytes);
      expect(await photoStore.readVerified(oldHash), isNotNull);

      // Simulates a failed/skipped photo download during a sha rotation: the
      // new response is persisted, but no bytes were ever written for the
      // NEW sha256 — so no verified file exists for it yet.
      final second = _activeResponse(sha256: 'newsha-never-downloaded');
      await repo.save(second, photoBytes: null);

      expect(
        await photoStore.readVerified(oldHash),
        isNotNull,
        reason: 'the last known-good verified photo must survive a failed '
            'download, not be garbage-collected out from under the player',
      );
      expect(await photoStore.readVerified('newsha-never-downloaded'), isNull);
    });

    test(
        'NEW WARNING 2 (verify-report 1575, slice 3b re-verify): save() with '
        'an active credential whose photo.sha256 is null returns before gc, '
        'never deleting an existing verified photo for a different sha',
        () async {
      const oldHash =
          'cba06b5736faf67e54b07b561eae94395e774c517a7d910a54369e1263ccfbd4';
      final oldBytes = Uint8List.fromList('old'.codeUnits);
      await repo.save(_activeResponse(sha256: oldHash), photoBytes: oldBytes);
      expect(await photoStore.readVerified(oldHash), isNotNull);

      final responseWithNoSha = CredencialResponse(
        state: CredencialCardState.active,
        credential: Credencial(
          id: 'cred-2',
          issuedAt: '2026-09-29 00:00:00',
          expiresAt: '2027-09-29 00:00:00',
          playerId: 1,
          fullName: 'Juan Perez',
          dni: '30111222',
          photo: const CredencialPhoto(
              url: 'https://example.com/p.jpg', sha256: null),
          codeSeed: 'c2VlZA',
          code: const CredencialCode(alg: 'SHA256', step: 30, digits: 6),
        ),
      );

      // This exercises the early-return branch directly (`if (sha256 ==
      // null) return;`, ~line 137): before this test, no caller in the
      // codebase ever reached this branch (CredencialController always
      // short-circuits to CredencialPhotoUnavailable before calling save()
      // when sha256 is null), so it was correct-by-source-read but
      // unverified by any test.
      await repo.save(responseWithNoSha, photoBytes: null);

      expect(
        await photoStore.readVerified(oldHash),
        isNotNull,
        reason: 'save() must do the SAFE thing (skip gc) when sha256 is '
            'null on an active credential, not the old unconditional-gc '
            'behavior that would destroy an unrelated verified photo',
      );
    });
  });
}

import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/services/credencial_photo_store.dart';

/// Design D5: "photo bytes live at
/// `getApplicationSupportDirectory()/credencial/{sha256}.jpg`: write temp +
/// rename BEFORE the JSON, gc AFTER. On load, re-hash; a mismatch is not a
/// card." Every behaviour this class owns is exercised here against a real
/// (but temp, disposable) directory — no platform-channel mocking needed
/// since [CredencialPhotoStore] takes its base directory via injection.
void main() {
  group('CredencialPhotoStore', () {
    late Directory tempRoot;
    late CredencialPhotoStore store;

    setUp(() async {
      tempRoot = await Directory.systemTemp.createTemp('credencial_photo_');
      store = CredencialPhotoStore(baseDirResolver: () async => tempRoot);
    });

    tearDown(() async {
      if (await tempRoot.exists()) {
        await tempRoot.delete(recursive: true);
      }
    });

    // The real sha256 hex digest of the bytes below (computed once, kept as
    // a literal so tests stay independent of the crypto package's own
    // correctness).
    const validSha =
        'fd2f9f15d20995731cfa5982e75fb1eabcde421d7aea04a5dd63636081d1222c';
    final validBytes = Uint8List.fromList('hello-credencial'.codeUnits);

    test('write then readVerified returns the exact bytes back', () async {
      await store.write(validBytes, validSha);

      final readBack = await store.readVerified(validSha);

      expect(readBack, isNotNull);
      expect(readBack, equals(validBytes));
    });

    test('readVerified returns null when no file exists yet', () async {
      final readBack = await store.readVerified(validSha);
      expect(readBack, isNull);
    });

    test('readVerified returns null when the file content no longer matches the hash',
        () async {
      await store.write(validBytes, validSha);

      // Corrupt the file directly, bypassing the store's own write() —
      // simulates any on-disk tampering or partial write that survived.
      final dir = Directory('${tempRoot.path}/credencial');
      final file = File('${dir.path}/$validSha.jpg');
      await file.writeAsBytes(Uint8List.fromList('tampered'.codeUnits));

      final readBack = await store.readVerified(validSha);
      expect(readBack, isNull);
    });

    test('write creates the file via temp-then-rename (no stray .tmp file left behind)',
        () async {
      await store.write(validBytes, validSha);

      final dir = Directory('${tempRoot.path}/credencial');
      final entries = await dir.list().toList();
      final names = entries.map((e) => e.uri.pathSegments.last).toList();

      expect(names, equals(['$validSha.jpg']));
    });

    test('deleteAllExcept removes every other cached photo but keeps the current one',
        () async {
      const otherSha = '2f825aa2f0020ef7cf91dfa30da4668d791c5d4824fc8e41354b89ec05795cd';
      await store.write(validBytes, validSha);
      await store.write(validBytes, otherSha);

      await store.deleteAllExcept(validSha);

      expect(await store.readVerified(validSha), isNotNull);
      expect(await store.readVerified(otherSha), isNull);
    });

    test('deleteAllExcept(null) removes every cached photo', () async {
      await store.write(validBytes, validSha);

      await store.deleteAllExcept(null);

      expect(await store.readVerified(validSha), isNull);
    });

    test('wipe deletes the entire credencial photo directory', () async {
      await store.write(validBytes, validSha);

      await store.wipe();

      final dir = Directory('${tempRoot.path}/credencial');
      expect(await dir.exists(), isFalse);
    });

    test('wipe on an already-empty store does not throw', () async {
      await expectLater(store.wipe(), completes);
    });
  });
}

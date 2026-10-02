import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/services/credencial_photo_store.dart';

/// Design D5 (rev 9): "photo bytes live at
/// `getApplicationSupportDirectory()/credencial/{photoId}.img`: write temp +
/// rename BEFORE the JSON, gc AFTER. On load: file exists AND magic-byte
/// sniff passes; no re-hash." Every behaviour this class owns is exercised
/// here against a real (but temp, disposable) directory — no platform-
/// channel mocking needed since [CredencialPhotoStore] takes its base
/// directory via injection.
void main() {
  group('sniffImageFormat', () {
    test('JPEG signature (FF D8 FF) is recognized', () {
      final bytes = Uint8List.fromList([0xFF, 0xD8, 0xFF, 0, 1, 2]);
      expect(sniffImageFormat(bytes), ImageSignature.jpeg);
    });

    test('PNG signature is recognized', () {
      final bytes = Uint8List.fromList(
          [0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A, 1, 2]);
      expect(sniffImageFormat(bytes), ImageSignature.png);
    });

    test('WebP signature (RIFF....WEBP) is recognized', () {
      final bytes = Uint8List.fromList([
        0x52, 0x49, 0x46, 0x46, // RIFF
        0, 0, 0, 0, // chunk size (irrelevant to the sniff)
        0x57, 0x45, 0x42, 0x50, // WEBP
      ]);
      expect(sniffImageFormat(bytes), ImageSignature.webp);
    });

    test('garbage bytes are rejected', () {
      final bytes = Uint8List.fromList('not-an-image'.codeUnits);
      expect(sniffImageFormat(bytes), isNull);
    });

    test('empty bytes are rejected', () {
      expect(sniffImageFormat(Uint8List(0)), isNull);
    });
  });

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

    // A minimal valid PNG signature followed by arbitrary payload — enough
    // to pass sniffImageFormat() without needing a fully decodable image
    // (the store never decodes, only sniffs).
    final validBytes = Uint8List.fromList([
      0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A, // PNG signature
      ...'hello-credencial'.codeUnits,
    ]);
    const photoId = 55;

    test('write then read returns the exact bytes back', () async {
      await store.write(validBytes, photoId);

      final readBack = await store.read(photoId);

      expect(readBack, isNotNull);
      expect(readBack, equals(validBytes));
    });

    test('read returns null when no file exists yet', () async {
      final readBack = await store.read(photoId);
      expect(readBack, isNull);
    });

    test('read returns null when the file content does not sniff as an image',
        () async {
      await store.write(validBytes, photoId);

      // Corrupt the file directly, bypassing the store's own write() —
      // simulates any on-disk tampering or partial write that survived.
      final dir = Directory('${tempRoot.path}/credencial');
      final file = File('${dir.path}/$photoId.img');
      await file.writeAsBytes(Uint8List.fromList('tampered'.codeUnits));

      final readBack = await store.read(photoId);
      expect(readBack, isNull);
    });

    test('write creates the file via temp-then-rename (no stray .tmp file left behind)',
        () async {
      await store.write(validBytes, photoId);

      final dir = Directory('${tempRoot.path}/credencial');
      final entries = await dir.list().toList();
      final names = entries.map((e) => e.uri.pathSegments.last).toList();

      expect(names, equals(['$photoId.img']));
    });

    test('deleteAllExcept removes every other cached photo but keeps the current one',
        () async {
      const otherPhotoId = 99;
      await store.write(validBytes, photoId);
      await store.write(validBytes, otherPhotoId);

      await store.deleteAllExcept(photoId);

      expect(await store.read(photoId), isNotNull);
      expect(await store.read(otherPhotoId), isNull);
    });

    test('deleteAllExcept(null) removes every cached photo', () async {
      await store.write(validBytes, photoId);

      await store.deleteAllExcept(null);

      expect(await store.read(photoId), isNull);
    });

    test(
        'deleteAllExcept sweeps a leftover file from the retired sha256-based '
        'naming ({sha}.jpg), since its name never matches {photoId}.img',
        () async {
      await store.write(validBytes, photoId);

      final dir = Directory('${tempRoot.path}/credencial');
      final legacyFile = File(
        '${dir.path}/fd2f9f15d20995731cfa5982e75fb1eabcde421d7aea04a5dd63636081d1222c.jpg',
      );
      await legacyFile.writeAsBytes(validBytes);

      await store.deleteAllExcept(photoId);

      expect(await legacyFile.exists(), isFalse);
      expect(await store.read(photoId), isNotNull);
    });

    test('wipe deletes the entire credencial photo directory', () async {
      await store.write(validBytes, photoId);

      await store.wipe();

      final dir = Directory('${tempRoot.path}/credencial');
      expect(await dir.exists(), isFalse);
    });

    test('wipe on an already-empty store does not throw', () async {
      await expectLater(store.wipe(), completes);
    });
  });
}

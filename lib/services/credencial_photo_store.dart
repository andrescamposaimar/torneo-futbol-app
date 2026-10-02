import 'dart:io';
import 'dart:typed_data';

import 'package:path_provider/path_provider.dart';

/// Image formats the credential photo pipeline accepts (design rev 9:
/// jpeg/png/webp — the hash-based verification was removed in favor of the
/// WordPress attachment id, see `credencial_controller.dart`).
enum ImageSignature { jpeg, png, webp }

/// Pure, decode-free signature check (design: "`sniffImageFormat` is pure
/// (JPEG `FF D8 FF`; PNG 8-byte signature; WebP `RIFF....WEBP`)"). Returns
/// `null` when [bytes] do not start with a recognized image signature.
/// Shared by [CredencialPhotoStore.read] (sniffs a file already on disk) and
/// `downloadCredencialPhoto` in `credencial_controller.dart` (sniffs a fresh
/// HTTP response body) so both sides of the cache agree on what "looks like
/// an image" means.
ImageSignature? sniffImageFormat(Uint8List bytes) {
  if (bytes.length >= 3 &&
      bytes[0] == 0xFF &&
      bytes[1] == 0xD8 &&
      bytes[2] == 0xFF) {
    return ImageSignature.jpeg;
  }

  const pngSignature = [0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A];
  if (bytes.length >= pngSignature.length) {
    var isPng = true;
    for (var i = 0; i < pngSignature.length; i++) {
      if (bytes[i] != pngSignature[i]) {
        isPng = false;
        break;
      }
    }
    if (isPng) return ImageSignature.png;
  }

  if (bytes.length >= 12 &&
      bytes[0] == 0x52 && // R
      bytes[1] == 0x49 && // I
      bytes[2] == 0x46 && // F
      bytes[3] == 0x46 && // F
      bytes[8] == 0x57 && // W
      bytes[9] == 0x45 && // E
      bytes[10] == 0x42 && // B
      bytes[11] == 0x50) {
    // P
    return ImageSignature.webp;
  }

  return null;
}

/// Persists the single approved credential photo's raw bytes on disk, at
/// `getApplicationSupportDirectory()/credencial/{photoId}.img` (design D5),
/// keyed by the WordPress featured-image attachment id. Files left by older
/// builds under another name are swept by [deleteAllExcept], because their
/// names never match the current `{photoId}.img`.
///
/// Deliberately outside [FlutterSecureStorage]: the approved photo is already
/// public via `/jugadores`' own `featured_image` field (design D5b), so it
/// does not need Keychain/Keystore-grade protection — only the small
/// `credencial_v1` JSON blob (DNI, birth date, code seed) does, and that is
/// [CredencialRepository]'s job.
///
/// [baseDirResolver] defaults to the real [getApplicationSupportDirectory],
/// and is injectable so tests can point this at a disposable temp directory
/// with no platform-channel mocking required.
class CredencialPhotoStore {
  final Future<Directory> Function() _resolveBaseDir;

  CredencialPhotoStore({Future<Directory> Function()? baseDirResolver})
      : _resolveBaseDir = baseDirResolver ?? getApplicationSupportDirectory;

  Future<Directory> _credencialDir() async {
    final base = await _resolveBaseDir();
    final dir = Directory('${base.path}/credencial');
    if (!await dir.exists()) {
      await dir.create(recursive: true);
    }
    return dir;
  }

  String _fileNameFor(int photoId) => '$photoId.img';

  /// Writes [bytes] to a temp file, then renames it into place at
  /// `{photoId}.img` (design D5: "write temp + rename"). A reader can
  /// therefore never observe a partially-written file at the final path —
  /// [rename] is atomic on both the platforms this app ships to.
  ///
  /// Caller contract (design D5: "write temp + rename BEFORE the JSON, gc
  /// AFTER"): [CredencialRepository.save] MUST call this BEFORE it writes the
  /// `credencial_v1` JSON blob, so a crash between the two steps can never
  /// leave a JSON pointer to a photo file that does not exist yet.
  Future<void> write(Uint8List bytes, int photoId) async {
    final dir = await _credencialDir();
    final finalPath = '${dir.path}/${_fileNameFor(photoId)}';
    final tempFile = File(
      '$finalPath.tmp-${DateTime.now().microsecondsSinceEpoch}',
    );

    await tempFile.writeAsBytes(bytes, flush: true);
    await tempFile.rename(finalPath);
  }

  /// Reads back the bytes for [photoId] (design D5, rev 9: "On load: file
  /// exists AND magic-byte sniff passes; no re-hash"). Returns `null` when
  /// the file is missing, unreadable, or does not sniff as a known image
  /// signature — the caller must treat any of these identically to "no
  /// cached photo", never crash. An atomic rename at [write] time already
  /// guarantees a complete file; the sniff only catches an empty/garbage
  /// file for free, it never fully decodes the image.
  Future<Uint8List?> read(int photoId) async {
    final dir = await _credencialDir();
    final file = File('${dir.path}/${_fileNameFor(photoId)}');

    if (!await file.exists()) return null;

    try {
      final bytes = await file.readAsBytes();
      if (sniffImageFormat(bytes) == null) return null;
      return bytes;
    } catch (_) {
      return null;
    }
  }

  /// Deletes every cached photo file except the one named `{keepPhotoId}.img`
  /// (pass `null` to delete all of them). Design D5: "gc AFTER" — the
  /// repository calls this once the current photo (if any) is already safely
  /// written, so a stale photo never lingers — including files written by
  /// older builds under another name.
  Future<void> deleteAllExcept(int? keepPhotoId) async {
    final dir = await _credencialDir();
    if (!await dir.exists()) return;

    final keepName = keepPhotoId == null ? null : _fileNameFor(keepPhotoId);

    await for (final entity in dir.list()) {
      if (entity is! File) continue;
      final name = entity.uri.pathSegments.last;
      if (name == keepName) continue;
      try {
        await entity.delete();
      } catch (_) {
        // Best-effort GC — a stray leftover file is not fatal; it will be
        // retried on the next save().
      }
    }
  }

  /// Deletes the entire credencial photo directory. Design D5: "Logout,
  /// revoke or player mismatch wipes both the key and the dir."
  Future<void> wipe() async {
    final base = await _resolveBaseDir();
    final dir = Directory('${base.path}/credencial');
    if (await dir.exists()) {
      await dir.delete(recursive: true);
    }
  }
}

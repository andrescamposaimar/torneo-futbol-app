import 'dart:io';
import 'dart:typed_data';

import 'package:crypto/crypto.dart' as crypto;
import 'package:path_provider/path_provider.dart';

/// Persists the single approved credential photo's raw bytes on disk, at
/// `getApplicationSupportDirectory()/credencial/{sha256}.jpg` (design D5).
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

  String _fileNameFor(String sha256Hex) => '$sha256Hex.jpg';

  /// Writes [bytes] to a temp file, then renames it into place at
  /// `{sha256Hex}.jpg` (design D5: "write temp + rename"). A reader can
  /// therefore never observe a partially-written file at the final path —
  /// [rename] is atomic on both the platforms this app ships to.
  ///
  /// Caller contract (design D5: "write temp + rename BEFORE the JSON, gc
  /// AFTER"): [CredencialRepository.save] MUST call this BEFORE it writes the
  /// `credencial_v1` JSON blob, so a crash between the two steps can never
  /// leave a JSON pointer to a photo file that does not exist yet.
  Future<void> write(Uint8List bytes, String sha256Hex) async {
    final dir = await _credencialDir();
    final finalPath = '${dir.path}/${_fileNameFor(sha256Hex)}';
    final tempFile = File(
      '$finalPath.tmp-${DateTime.now().microsecondsSinceEpoch}',
    );

    await tempFile.writeAsBytes(bytes, flush: true);
    await tempFile.rename(finalPath);
  }

  /// Reads back the bytes for [sha256Hex], re-hashing on load (design D5:
  /// "On load, re-hash; a mismatch is not a card"). Returns `null` when the
  /// file is missing, unreadable, or its actual content no longer matches
  /// [sha256Hex] — the caller must treat any of these identically to "no
  /// cached photo", never crash.
  Future<Uint8List?> readVerified(String sha256Hex) async {
    final dir = await _credencialDir();
    final file = File('${dir.path}/${_fileNameFor(sha256Hex)}');

    if (!await file.exists()) return null;

    try {
      final bytes = await file.readAsBytes();
      final actualHash = crypto.sha256.convert(bytes).toString();
      if (actualHash != sha256Hex) return null;
      return bytes;
    } catch (_) {
      return null;
    }
  }

  /// Deletes every cached photo file except the one named `{keepSha256Hex}.jpg`
  /// (pass `null` to delete all of them). Design D5: "gc AFTER" — the
  /// repository calls this once the current photo (if any) is already safely
  /// written, so a stale photo from a previous approved photo never lingers.
  Future<void> deleteAllExcept(String? keepSha256Hex) async {
    final dir = await _credencialDir();
    if (!await dir.exists()) return;

    final keepName = keepSha256Hex == null ? null : _fileNameFor(keepSha256Hex);

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

import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../models/credencial.dart';
import 'credencial_photo_store.dart';

/// Persists the cached `GET /credencial/credencial` response (design D5:
/// "cache the full payload locally so it renders offline").
///
/// Two separate stores back this one logical cache, matching design D5/D5b
/// exactly:
///   - `credencial_v1` in [FlutterSecureStorage] holds the JSON payload
///     (DNI, birth date, code seed, etc.) — written with
///     `IOSOptions(accessibility: unlocked_this_device)`, scoped to this one
///     key only (the default Prode token storage, `prode_tokens` in
///     `prode_auth_repository.dart`, is untouched by this).
///   - [CredencialPhotoStore] holds the actual photo bytes on the
///     filesystem — the approved photo is already public via `/jugadores`,
///     so it does not need Keychain-grade protection.
///
/// Inject a custom [FlutterSecureStorage]/[CredencialPhotoStore] via the
/// constructor to enable testing without platform channel dependencies
/// (mirrors [ProdeAuthRepository]'s own constructor-injection pattern).
class CredencialRepository {
  static const String _storageKey = 'credencial_v1';

  /// Design D5b: "written with `IOSOptions(accessibility:
  /// KeychainAccessibility.unlocked_this_device)`, scoped to this key" — this
  /// is passed per-call on every read/write/delete of [_storageKey] only, so
  /// no other secure-storage key on this device is affected.
  static const IOSOptions _iosOptions =
      IOSOptions(accessibility: KeychainAccessibility.unlocked_this_device);

  final FlutterSecureStorage _storage;
  final CredencialPhotoStore _photoStore;

  CredencialRepository({
    FlutterSecureStorage? storage,
    CredencialPhotoStore? photoStore,
  })  : _storage = storage ?? const FlutterSecureStorage(),
        _photoStore = photoStore ?? CredencialPhotoStore();

  /// Reads the cached credential, confirming the locally-stored photo bytes
  /// still exist and sniff as a real image for the cached `photo.id` (design
  /// D5, rev 9: "On load: file exists AND magic-byte sniff passes; no
  /// re-hash").
  ///
  /// Two DIFFERENT failure modes, handled deliberately differently (design
  /// D5b vs D5's own wording):
  ///   - A secure-storage read exception (an undecryptable Keychain/Keystore
  ///     entry) OR a malformed/unparseable JSON blob is unrecoverable — the
  ///     stored key is deleted outright ("Any read/decrypt exception = no
  ///     cache + delete the key"; a corrupt blob would fail identically on
  ///     every future launch, so leaving it in storage forever serves no
  ///     purpose).
  ///   - A missing or unreadable photo file is NOT necessarily permanent (a
  ///     routine revalidation can fix it once online) — this case returns
  ///     `null` WITHOUT touching the stored JSON key.
  Future<CredencialResponse?> readCached() async {
    String? raw;
    try {
      raw = await _storage.read(key: _storageKey, iOptions: _iosOptions);
    } catch (_) {
      await _deleteKeySafely();
      return null;
    }

    if (raw == null) return null;

    final CredencialResponse response;
    try {
      final decoded = json.decode(raw);
      if (decoded is! Map<String, dynamic>) {
        await _deleteKeySafely();
        return null;
      }
      response = CredencialResponse.fromJson(decoded);
    } catch (_) {
      await _deleteKeySafely();
      return null;
    }

    final credential = response.credential;
    if (credential == null) return response;

    final photoBytes = await _photoStore.read(credential.photo.id);
    if (photoBytes == null) return null;

    return response;
  }

  /// Persists [response] and, when it carries an active credential together
  /// with [photoBytes], the photo bytes themselves.
  ///
  /// Ordering matches design D5 exactly: photo bytes are written (temp +
  /// rename, [CredencialPhotoStore.write]) BEFORE the JSON blob, so a crash
  /// between the two steps can never leave a JSON pointer to a photo that
  /// does not exist yet. If the photo write fails, this method throws and
  /// the JSON is never persisted — the previous cache (if any) stays intact.
  ///
  /// Garbage collection of stale photo files ([CredencialPhotoStore
  /// .deleteAllExcept]) runs AFTER the JSON write, per the same design line
  /// ("gc AFTER") — but ONLY when the file for the NEW `photo.id` is actually
  /// present and sniffs as a real image on disk (CRITICAL fix, verify-report
  /// 1575 slice-3b section, still true under the id-based design): if
  /// [photoBytes] was not supplied AND no file already exists for this
  /// response's `photo.id` (e.g. a caller saved a fresh `active` response
  /// after a failed/skipped photo download), this method must NEVER delete
  /// the other cached photo files — doing so would permanently destroy the
  /// last known-good photo over a single transient failure. Callers that
  /// legitimately want the old photo removed (blocked/no_photo/not_a_player/
  /// logout) must call [clear] instead of relying on this side effect.
  Future<void> save(CredencialResponse response,
      {Uint8List? photoBytes}) async {
    final photoId = response.credential?.photo.id;

    if (response.credential != null && photoBytes != null) {
      await _photoStore.write(photoBytes, photoId!);
    }

    await _storage.write(
      key: _storageKey,
      value: json.encode(response.toJson()),
      iOptions: _iosOptions,
    );

    if (response.credential == null) {
      // No active credential in this response at all — there is no photo to
      // protect, so any previously cached photo files are simply stale.
      await _photoStore.deleteAllExcept(null);
      return;
    }

    final verified = await _photoStore.read(photoId!);
    if (verified == null) {
      // The photo for this response's photo.id is not actually available on
      // disk (write skipped, or the write silently produced an unreadable
      // file) — keep every existing photo file, including any previously
      // cached one for a different photo.id, untouched.
      return;
    }

    await _photoStore.deleteAllExcept(photoId);
  }

  /// Reads back the bytes for a photo already persisted by [save] (same
  /// contract as [CredencialPhotoStore.read] — returns `null` when the file
  /// is missing or does not sniff as a real image).
  ///
  /// Used by [CredencialController] to hand [CredencialActive] its
  /// `photoBytes` by construction (decision 1523: never a valid card
  /// without downloaded photo bytes) instead of the screen re-reading the
  /// file asynchronously at render time (verify-report 1575, slice 3b, NEW
  /// WARNING 1).
  Future<Uint8List?> readPhoto(int photoId) => _photoStore.read(photoId);

  /// Wipes both the secure-storage key and the photo directory (design D5:
  /// "Logout, revoke or player mismatch wipes both the key and the dir").
  ///
  /// Callers (the 3b controller) must invoke this on: logout, a revalidation
  /// outcome of blocked/no_photo/not_a_player, a [ProdeAuthRequired] surfaced
  /// by the transport, and a player_id mismatch.
  Future<void> clear() async {
    await _deleteKeySafely();
    await _photoStore.wipe();
  }

  Future<void> _deleteKeySafely() async {
    try {
      await _storage.delete(key: _storageKey, iOptions: _iosOptions);
    } catch (_) {
      // Best-effort: if delete itself fails there is nothing further this
      // method can do — the next successful write still overwrites the key.
    }
  }
}

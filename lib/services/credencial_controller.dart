import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:http/http.dart' as http;
import 'package:meta/meta.dart';

import '../models/credencial.dart';
import 'credencial_api_service.dart';
import 'credencial_photo_store.dart' show sniffImageFormat;
import 'credencial_repository.dart';
import 'credencial_state.dart';
import 'prode_api_service.dart' show ProdeAuthRequired;

/// Injectable "does this decode as a real image" check used by
/// [downloadCredencialPhoto] (design: "Default `decodes` =
/// `ui.instantiateImageCodec` + `getNextFrame()`"). Exists as its own
/// typedef so tests can substitute a cheap fake instead of exercising the
/// real Flutter image codec for every case.
typedef PhotoDecodeCheck = Future<bool> Function(Uint8List bytes);

Future<bool> _defaultDecodeCheck(Uint8List bytes) async {
  try {
    final codec = await ui.instantiateImageCodec(bytes);
    await codec.getNextFrame();
    return true;
  } catch (_) {
    return false;
  }
}

/// Downloads a photo and validates it BEFORE returning its bytes (design D5):
/// a non-200 response, a
/// non-image `content-type`, bytes that do not sniff as a known image
/// signature, or bytes that fail to decode must never be treated as a
/// successful download. Exposed as a top-level function — rather than folded
/// into [CredencialController] — so it is directly testable against an
/// injected [http.Client] (`MockClient` from `package:http/testing.dart`)
/// and an injected [decodes] check, without any controller plumbing.
@visibleForTesting
Future<Uint8List> downloadCredencialPhoto(
  http.Client client,
  String url, {
  PhotoDecodeCheck decodes = _defaultDecodeCheck,
}) async {
  final response = await client.get(Uri.parse(url));
  final contentType = response.headers['content-type'];
  final isImageContentType = contentType != null &&
      (contentType.startsWith('image/jpeg') ||
          contentType.startsWith('image/png') ||
          contentType.startsWith('image/webp'));
  if (response.statusCode != 200 || !isImageContentType) {
    throw Exception(
      'Unexpected photo download response: status=${response.statusCode}, '
      'content-type=$contentType',
    );
  }

  final bytes = response.bodyBytes;
  if (sniffImageFormat(bytes) == null) {
    throw Exception('Downloaded bytes do not sniff as a jpeg/png/webp image');
  }
  if (!await decodes(bytes)) {
    throw Exception('Downloaded bytes failed to decode as an image');
  }
  return bytes;
}

/// Drives "Mi Credencial" end to end (design D6 "Silent Revalidation"; spec
/// "Offline Cache and Hard Expiry").
///
/// [open] is the entry point the screen calls on mount and on every
/// subsequent re-open (design: "on every app open with connectivity
/// re-GET" — this controller always attempts the GET; it does not check
/// connectivity itself, it reacts to whatever the transport does). The
/// resulting UI state depends on both that outcome and whatever was already
/// cached:
///
/// | GET outcome                                    | UI state                    | cache     |
/// |-------------------------------------------------|-----------------------------|-----------|
/// | 200 active, photo resolved (reused or freshly downloaded) | Active(stale:false) | saved     |
/// | 200 active, photo download/validation failed      | PhotoUnavailable            | unchanged |
/// | 200 no_photo, no request                         | NoPhoto                     | wiped     |
/// | 200 no_photo, pending request                    | PendingPhoto                | wiped     |
/// | 200 no_photo, rejected request                   | RejectedPhoto               | wiped     |
/// | 200 blocked                                      | Blocked                     | wiped     |
/// | 200 not_a_player                                 | NotAPlayer                  | wiped     |
/// | ProdeAuthRequired (unrecoverable 401)             | NotSignedIn                 | wiped     |
/// | any other failure, cache present & not expired   | cache state, stale:true     | unchanged |
/// | any other failure, cache present & hard-expired  | Expired                     | unchanged |
/// | any other failure, no cache                      | OfflineNoCache              | unchanged |
///
/// Design D4/D5 (rev 9): the photo's identity is `photo.id` (the WordPress
/// featured-image attachment id), not a hash of its bytes. Only `active`
/// responses whose photo bytes are actually resolved (same id reused from
/// disk, or a new id freshly downloaded and validated) are ever cached.
///
/// Decision 1523 (non-negotiable): the valid-styled card must NEVER render
/// without photo bytes for the CURRENT approved `photo.id`. A fresh `active`
/// response whose photo could not be reused or downloaded produces
/// [CredencialPhotoUnavailable] instead of [CredencialActive] — and is never
/// persisted, so the previous cache (if any, for a DIFFERENT `photo.id`) is
/// left completely untouched rather than being shown as if it were the new
/// approved photo.
class CredencialController extends StateNotifier<CredencialUiState> {
  final CredencialApiService _api;
  final CredencialRepository _repository;
  final DateTime Function() _now;
  final Future<Uint8List> Function(String url) _downloadPhoto;

  CredencialController({
    required CredencialApiService api,
    required CredencialRepository repository,
    DateTime Function()? now,
    Future<Uint8List> Function(String url)? downloadPhoto,
  })  : _api = api,
        _repository = repository,
        _now = now ?? DateTime.now,
        _downloadPhoto = downloadPhoto ?? _defaultDownloadPhoto,
        super(const CredencialLoading());

  static Future<Uint8List> _defaultDownloadPhoto(String url) async {
    final client = http.Client();
    try {
      return await downloadCredencialPhoto(client, url);
    } finally {
      client.close();
    }
  }

  /// Called on first mount and every time the screen re-opens. Always
  /// attempts a fresh GET — see the class docblock for the full
  /// reconciliation table.
  Future<void> open() async {
    final cached = await _repository.readCached();

    final optimistic =
        cached == null ? null : await _deriveFromResponse(cached, stale: true);
    state = optimistic ?? const CredencialLoading();

    await _revalidate(cached);
  }

  Future<void> _revalidate(CredencialResponse? cached) async {
    final CredencialResponse response;
    try {
      response = await _api.fetchCredencial();
    } on ProdeAuthRequired {
      await _repository.clear();
      state = const CredencialNotSignedIn();
      return;
    } catch (_) {
      await _handleRevalidationFailure(cached);
      return;
    }

    await _applySuccess(response, previousCached: cached);
  }

  Future<void> _handleRevalidationFailure(CredencialResponse? cached) async {
    if (cached == null) {
      state = const CredencialOfflineNoCache();
      return;
    }
    if (_isHardExpired(cached)) {
      state = const CredencialExpired();
      return;
    }
    // cached is non-null and not hard-expired: it was necessarily an
    // `active` response (only `active` is ever persisted — see
    // _applySuccess), so this always derives a real state. The fallback is
    // purely defensive against a corrupted/unexpected cache shape, or the
    // photo file having become unreadable between `readCached()`'s own
    // verification and this second read.
    state = await _deriveFromResponse(cached, stale: true) ??
        const CredencialExpired();
  }

  Future<void> _applySuccess(
    CredencialResponse response, {
    required CredencialResponse? previousCached,
  }) async {
    // SUGGESTION 2 (verify-report 1575, slice 3b) — defense-in-depth: a
    // fresh response for a DIFFERENT player_id than whatever is currently
    // cached must never be layered on top of the previous player's cache.
    // Currently unreachable through the UI (switching accounts always goes
    // through an explicit logout() that already wipes), but cheap insurance
    // against a future auth-flow change reintroducing it.
    var priorCache = previousCached;
    final previousPlayerId = priorCache?.credential?.playerId;
    final newPlayerId = response.credential?.playerId;
    if (previousPlayerId != null &&
        newPlayerId != null &&
        previousPlayerId != newPlayerId) {
      await _repository.clear();
      priorCache = null;
    }

    switch (response.state) {
      case CredencialCardState.active:
        final credential = response.credential;
        if (credential == null) {
          state = const CredencialError('Respuesta inválida del servidor.');
          return;
        }

        final photoId = credential.photo.id;
        final previousPhotoId = priorCache?.credential?.photo.id;
        if (previousPhotoId == photoId) {
          // The approved photo is unchanged since the last verified read —
          // try the file already on disk first, no re-download needed.
          final existingBytes = await _repository.readPhoto(photoId);
          if (existingBytes != null) {
            await _repository.save(response, photoBytes: null);
            state = CredencialActive(
              credential: credential,
              replacement: _replacementFor(response.photoRequest),
              stale: false,
              photoBytes: existingBytes,
            );
            return;
          }
          // Same id, but the file is gone or unreadable (e.g. deleted
          // outside the app) — fall through to a fresh download rather than
          // trusting a cache no longer backed by a readable file.
        }

        Uint8List? downloaded;
        try {
          downloaded = await _downloadPhoto(credential.photo.url);
        } catch (_) {
          downloaded = null;
        }

        if (downloaded == null) {
          // The approved photo changed (or has no prior cache at all) and
          // we could not obtain/validate the NEW one. Decision 1523: the
          // valid card must NEVER render without photo bytes for the
          // CURRENT id — showing the OLD (now-replaced) photo would display
          // a face that may have been rejected/replaced, so this is an
          // explicit non-valid state, never a fallback to the stale card.
          // Nothing is saved: the previous cache (if any, for a different
          // id) is left completely untouched.
          state = const CredencialPhotoUnavailable();
          return;
        }

        await _repository.save(response, photoBytes: downloaded);
        state = CredencialActive(
          credential: credential,
          replacement: _replacementFor(response.photoRequest),
          stale: false,
          photoBytes: downloaded,
        );
      case CredencialCardState.noPhoto:
        await _repository.clear();
        state = _noPhotoStateFor(response.photoRequest);
      case CredencialCardState.blocked:
        await _repository.clear();
        state = const CredencialBlocked();
      case CredencialCardState.notAPlayer:
        await _repository.clear();
        state = const CredencialNotAPlayer();
    }
  }

  CredencialUiState _noPhotoStateFor(CredencialPhotoRequest? request) {
    if (request == null) return const CredencialNoPhoto();
    return switch (request.status) {
      PhotoRequestStatus.pending => const CredencialPendingPhoto(),
      PhotoRequestStatus.rejected => const CredencialRejectedPhoto(),
    };
  }

  CredencialReplacementStatus _replacementFor(CredencialPhotoRequest? request) {
    if (request == null) return CredencialReplacementStatus.none;
    return switch (request.status) {
      PhotoRequestStatus.pending => CredencialReplacementStatus.pending,
      PhotoRequestStatus.rejected => CredencialReplacementStatus.rejected,
    };
  }

  /// Maps a cached [CredencialResponse] to the UI state it would produce were
  /// it a fresh GET result — used both for the optimistic cache display in
  /// [open] and for the "keep last verified" fallback in
  /// [_handleRevalidationFailure].
  ///
  /// Returns `null` (never a byte-less [CredencialActive]) when [response]
  /// carries no active credential, or the photo bytes for its `photo.id`
  /// cannot be read back from disk right now (decision 1523: never render
  /// the valid card without photo bytes) — callers fall back to a non-Active
  /// state in that case.
  Future<CredencialUiState?> _deriveFromResponse(
    CredencialResponse response, {
    required bool stale,
  }) async {
    final credential = response.credential;
    if (response.state != CredencialCardState.active || credential == null) {
      return null;
    }

    final photoBytes = await _repository.readPhoto(credential.photo.id);
    if (photoBytes == null) return null;

    return CredencialActive(
      credential: credential,
      replacement: _replacementFor(response.photoRequest),
      stale: stale,
      photoBytes: photoBytes,
    );
  }

  /// Design "Offline Cache and Hard Expiry": "stop showing it once 1 year has
  /// elapsed since issue/last refresh, regardless of connectivity" — the
  /// server already encodes that boundary as `expires_at` (design D5:
  /// "Reissue on every GET with expires_at = now+365d"), so the client only
  /// has to compare against it. An unparseable/missing `expires_at` is
  /// treated as expired (fail closed rather than showing a credential with
  /// no known expiry).
  bool _isHardExpired(CredencialResponse response) {
    final expiresAt = response.credential?.expiresAt;
    if (expiresAt == null) return true;
    final parsed = DateTime.tryParse(expiresAt.replaceFirst(' ', 'T'));
    if (parsed == null) return true;
    return !_now().isBefore(parsed);
  }
}

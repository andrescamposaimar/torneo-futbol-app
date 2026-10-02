import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/models/credencial.dart';
import 'package:torneo_futbol_app/services/credencial_api_service.dart';
import 'package:torneo_futbol_app/services/credencial_controller.dart';
import 'package:torneo_futbol_app/services/credencial_photo_store.dart';
import 'package:torneo_futbol_app/services/credencial_repository.dart';
import 'package:torneo_futbol_app/services/credencial_state.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';

// ---------------------------------------------------------------------------
// Fake CredencialApiService — fetchCredencial() is driven by an injected
// callback so tests can return a canned response or throw, without any real
// HTTP client / ProdeApiService transport plumbing.
// ---------------------------------------------------------------------------

const _kProdeConfig = ProdeAuthConfig(
  prodeApiBaseUrl: 'https://test.example.com/prode',
  googleWebClientId: 'test-google',
  appleTeamId: 'TEST_TEAM',
);

class _FakeCredencialApiService extends CredencialApiService {
  final Future<CredencialResponse> Function() _fetch;

  _FakeCredencialApiService(this._fetch)
      : super(
          prodeApi: ProdeApiService(
            config: _kProdeConfig,
            authRepo: ProdeAuthRepository(),
          ),
          credencialApiBaseUrl: 'https://test.example.com/credencial',
        );

  @override
  Future<CredencialResponse> fetchCredencial() => _fetch();
}

void _setUpFakeStorage(Map<String, String> store) {
  FlutterSecureStoragePlatform.instance =
      TestFlutterSecureStoragePlatform(store);
}

/// Bytes that sniff as a (fake) JPEG — [CredencialPhotoStore.read] requires a
/// recognized image signature (design D5, rev 9: "magic-byte sniff passes;
/// no re-hash"), so every fixture that round-trips through the real
/// repository/store (not just an injected `downloadPhoto` return value
/// consumed directly) needs a real signature prefix.
Uint8List _fakePhotoBytes(String label) => Uint8List.fromList(
    [0xFF, 0xD8, 0xFF, ...label.codeUnits]);

// ---------------------------------------------------------------------------
// Response builders
// ---------------------------------------------------------------------------

CredencialResponse _activeResponse({
  int photoId = 1,
  String photoUrl = 'https://example.com/p.jpg',
  CredencialPhotoRequest? photoRequest,
  String expiresAt = '2027-09-29 00:00:00',
  int playerId = 1,
}) {
  return CredencialResponse(
    state: CredencialCardState.active,
    photoRequest: photoRequest,
    credential: Credencial(
      id: 'cred-1',
      issuedAt: '2026-09-29 00:00:00',
      expiresAt: expiresAt,
      playerId: playerId,
      fullName: 'Juan Perez',
      dni: '30111222',
      photo: CredencialPhoto(id: photoId, url: photoUrl),
      codeSeed: 'c2VlZA',
      code: const CredencialCode(alg: 'SHA256', step: 30, digits: 6),
    ),
  );
}

CredencialResponse _noPhotoResponse({CredencialPhotoRequest? photoRequest}) =>
    CredencialResponse(
        state: CredencialCardState.noPhoto, photoRequest: photoRequest);

const _blockedResponse = CredencialResponse(state: CredencialCardState.blocked);
const _notAPlayerResponse =
    CredencialResponse(state: CredencialCardState.notAPlayer);

void main() {
  group('CredencialController', () {
    late Map<String, String> store;
    late Directory tempRoot;
    late CredencialPhotoStore photoStore;
    late CredencialRepository repo;

    setUp(() async {
      store = {};
      _setUpFakeStorage(store);
      tempRoot = await Directory.systemTemp.createTemp('credencial_ctrl_');
      photoStore = CredencialPhotoStore(baseDirResolver: () async => tempRoot);
      repo = CredencialRepository(photoStore: photoStore);
    });

    tearDown(() async {
      if (await tempRoot.exists()) {
        await tempRoot.delete(recursive: true);
      }
    });

    CredencialController makeController({
      required Future<CredencialResponse> Function() fetch,
      DateTime? now,
      Future<Uint8List> Function(String url)? downloadPhoto,
    }) {
      return CredencialController(
        api: _FakeCredencialApiService(fetch),
        repository: repo,
        now: now == null ? null : () => now,
        downloadPhoto: downloadPhoto,
      );
    }

    group('200 active', () {
      test(
          'open() with no prior cache → Active(stale:false), photo downloaded and cached',
          () async {
        final bytes = _fakePhotoBytes('photo-bytes');
        var downloadCalls = 0;

        final controller = makeController(
          fetch: () async => _activeResponse(photoId: 101),
          downloadPhoto: (url) async {
            downloadCalls++;
            expect(url, 'https://example.com/p.jpg');
            return bytes;
          },
        );

        await controller.open();

        expect(controller.state, isA<CredencialActive>());
        final active = controller.state as CredencialActive;
        expect(active.stale, isFalse);
        expect(active.credential.fullName, 'Juan Perez');
        expect(active.replacement, CredencialReplacementStatus.none);
        expect(downloadCalls, 1);
        // Decision 1523: Active must carry its photo bytes by construction.
        expect(active.photoBytes, bytes);

        final cached = await repo.readCached();
        expect(cached, isNotNull);
        expect(cached!.credential!.photo.id, 101);
      });

      test('unchanged photo id vs. previous cache → photo is NOT re-downloaded',
          () async {
        final bytes = _fakePhotoBytes('same-photo');
        await repo.save(_activeResponse(photoId: 7), photoBytes: bytes);

        var downloadCalls = 0;
        final controller = makeController(
          fetch: () async => _activeResponse(photoId: 7),
          downloadPhoto: (url) async {
            downloadCalls++;
            return bytes;
          },
        );

        await controller.open();

        expect(downloadCalls, 0);
        expect(controller.state, isA<CredencialActive>());
        // The unchanged-id fast path must still hand Active the bytes read
        // back from disk, not skip them.
        final active = controller.state as CredencialActive;
        expect(active.photoBytes, bytes);
      });

      test(
          'a JSON cache pointing at a photo id with no matching file on disk '
          'is treated as no cache — a successful fetch simply downloads',
          () async {
        // Seed a JSON cache pointing at photoId 7 WITHOUT ever writing the
        // matching file (e.g. it was deleted outside the app).
        // CredencialRepository.readCached() already guards against exactly
        // this shape (file missing -> null), so the controller never even
        // sees it as a "same id" cache hit.
        final seeded = _activeResponse(photoId: 7);
        store['credencial_v1'] = json.encode(seeded.toJson());

        final freshBytes = _fakePhotoBytes('freshly-downloaded');
        var downloadCalls = 0;
        final controller = makeController(
          fetch: () async => _activeResponse(photoId: 7),
          downloadPhoto: (_) async {
            downloadCalls++;
            return freshBytes;
          },
        );

        await controller.open();

        expect(downloadCalls, 1);
        expect(controller.state, isA<CredencialActive>());
        expect((controller.state as CredencialActive).photoBytes, freshBytes);
      });

      test(
          'new photo id vs. previous cache → downloads the new photo',
          () async {
        final oldBytes = _fakePhotoBytes('old-photo');
        await repo.save(_activeResponse(photoId: 1), photoBytes: oldBytes);

        final newBytes = _fakePhotoBytes('new-photo');
        var downloadCalls = 0;
        final controller = makeController(
          fetch: () async => _activeResponse(photoId: 2),
          downloadPhoto: (_) async {
            downloadCalls++;
            return newBytes;
          },
        );

        await controller.open();

        expect(downloadCalls, 1);
        expect(controller.state, isA<CredencialActive>());
        expect((controller.state as CredencialActive).photoBytes, newBytes);
      });

      test(
          'pending replacement request → Active.replacement = pending, currently approved photo kept',
          () async {
        final bytes = _fakePhotoBytes('approved-photo');
        final controller = makeController(
          fetch: () async => _activeResponse(
            photoId: 3,
            photoRequest: const CredencialPhotoRequest(
              id: 5,
              status: PhotoRequestStatus.pending,
              createdAt: '2026-09-29 00:00:00',
            ),
          ),
          downloadPhoto: (_) async => bytes,
        );

        await controller.open();

        final active = controller.state as CredencialActive;
        expect(active.replacement, CredencialReplacementStatus.pending);
      });

      test('rejected replacement request → Active.replacement = rejected',
          () async {
        final bytes = _fakePhotoBytes('approved-photo-2');
        final controller = makeController(
          fetch: () async => _activeResponse(
            photoId: 4,
            photoRequest: const CredencialPhotoRequest(
              id: 6,
              status: PhotoRequestStatus.rejected,
              createdAt: '2026-09-29 00:00:00',
            ),
          ),
          downloadPhoto: (_) async => bytes,
        );

        await controller.open();

        final active = controller.state as CredencialActive;
        expect(active.replacement, CredencialReplacementStatus.rejected);
      });

      // -----------------------------------------------------------------
      // CRITICAL fix (verify-report 1575, slice 3b), still true under the
      // id-based design: decision 1523 forbids ever rendering the
      // valid-styled card without photo bytes for the CURRENT approved
      // photo id. A failed download during an id rotation must produce an
      // explicit non-valid state, never a "successful" Active with a
      // placeholder.
      // -----------------------------------------------------------------

      test(
          'photo download failure on an id rotation → CredencialPhotoUnavailable, '
          'never a placeholder-styled Active', () async {
        final controller = makeController(
          fetch: () async => _activeResponse(photoId: 999),
          downloadPhoto: (_) async => throw Exception('network blip'),
        );

        await controller.open();

        expect(controller.state, isA<CredencialPhotoUnavailable>());
      });

      test(
          'a failed download during an id rotation keeps the OLD photo on '
          'disk and does not overwrite the cached JSON', () async {
        final oldBytes = _fakePhotoBytes('old-approved-photo');
        final oldResponse = _activeResponse(photoId: 1);
        await repo.save(oldResponse, photoBytes: oldBytes);

        final controller = makeController(
          fetch: () async => _activeResponse(photoId: 2),
          downloadPhoto: (_) async => throw Exception('network blip'),
        );

        await controller.open();

        expect(controller.state, isA<CredencialPhotoUnavailable>());
        expect(
          await photoStore.read(1),
          isNotNull,
          reason: 'the last known-good photo must survive a failed download '
              'during an id rotation',
        );
        expect(await repo.readCached(), equals(oldResponse));
      });

      test(
          'offline open with a cached JSON whose photo file is missing on disk → '
          'OfflineNoCache, never an Active placeholder', () async {
        // Bypass repo.save() to simulate exactly the corrupted-cache shape: a
        // JSON pointer to a photo id with no matching file, AND the fetch
        // itself fails (so there is no fallback download attempt either).
        final response = _activeResponse(photoId: 123);
        store['credencial_v1'] = json.encode(response.toJson());

        final controller = makeController(
          fetch: () async => throw Exception('offline'),
        );

        await controller.open();

        expect(controller.state, isA<CredencialOfflineNoCache>());
      });

      test(
          'retry after a failed download succeeds once the network recovers → Active',
          () async {
        final bytes = _fakePhotoBytes('recovered-photo');
        var shouldFail = true;

        final controller = makeController(
          fetch: () async => _activeResponse(photoId: 55),
          downloadPhoto: (_) async {
            if (shouldFail) {
              shouldFail = false;
              throw Exception('network blip');
            }
            return bytes;
          },
        );

        await controller.open();
        expect(controller.state, isA<CredencialPhotoUnavailable>());

        await controller.open();
        expect(controller.state, isA<CredencialActive>());
        expect((controller.state as CredencialActive).stale, isFalse);
      });
    });

    group(
        'same id, but the cached file disappears between readCached() and '
        'the _applySuccess re-check (WARNING 1, verify-report 1575)', () {
      // readCached() itself already verifies the file for the cached
      // photo.id exists (see CredencialRepository.readCached), so deleting
      // the file BEFORE open() calls readCached() would just make `cached`
      // null and never reach this branch at all (previousPhotoId would be
      // null, not a match). The only way to reach it is to delete the file
      // AFTER readCached() has already returned a non-null cache with the
      // file present, but BEFORE _applySuccess re-reads it — a window that
      // spans exactly one `await _api.fetchCredencial()`. The injected
      // `fetch` callback runs squarely inside that window, so deleting the
      // file there reproduces the race deterministically.
      test(
          'download succeeds after the file vanishes → Active with the '
          'freshly downloaded bytes', () async {
        final oldBytes = _fakePhotoBytes('about-to-vanish');
        await repo.save(_activeResponse(photoId: 42), photoBytes: oldBytes);

        final newBytes = _fakePhotoBytes('re-downloaded');
        var downloadCalls = 0;
        final controller = makeController(
          fetch: () async {
            final file = File('${tempRoot.path}/credencial/42.img');
            expect(await file.exists(), isTrue,
                reason: 'the file must still be on disk when open() read '
                    'the cache; only this callback removes it');
            await file.delete();
            return _activeResponse(photoId: 42);
          },
          downloadPhoto: (_) async {
            downloadCalls++;
            return newBytes;
          },
        );

        await controller.open();

        expect(downloadCalls, 1,
            reason: 'same id with a now-missing file must still trigger a '
                'fresh download rather than trusting a stale cache hit');
        expect(controller.state, isA<CredencialActive>());
        expect((controller.state as CredencialActive).photoBytes, newBytes);
        expect(await photoStore.read(42), newBytes);
      });

      test(
          'download fails after the file vanishes → '
          'CredencialPhotoUnavailable, nothing saved', () async {
        final oldBytes = _fakePhotoBytes('about-to-vanish-2');
        await repo.save(_activeResponse(photoId: 43), photoBytes: oldBytes);

        final controller = makeController(
          fetch: () async {
            final file = File('${tempRoot.path}/credencial/43.img');
            await file.delete();
            return _activeResponse(photoId: 43);
          },
          downloadPhoto: (_) async => throw Exception('network blip'),
        );

        await controller.open();

        expect(controller.state, isA<CredencialPhotoUnavailable>());
        expect(await photoStore.read(43), isNull,
            reason: 'the re-download failed, so nothing new was written '
                'for id 43');
        expect(await repo.readCached(), isNull,
            reason: 'the file for the cached id is gone and nothing new '
                'was saved, so the cache must not resurrect a byte-less '
                'state on the next read');
      });
    });

    group('same id, different photo url (D16, design rev 10)', () {
      // Design D16: a URL change alone (same `photo.id`) is a rendition/host
      // change, not a face change (rev 9.1 cache-key decision) — it must
      // never be treated as "file missing" nor trigger PhotoUnavailable.
      test(
          'same id, same url, file present → reused with NO download call '
          '(row 1)', () async {
        final bytes = _fakePhotoBytes('unchanged-url-photo');
        await repo.save(
          _activeResponse(photoId: 20, photoUrl: 'https://example.com/a.jpg'),
          photoBytes: bytes,
        );

        var downloadCalls = 0;
        final controller = makeController(
          fetch: () async => _activeResponse(
              photoId: 20, photoUrl: 'https://example.com/a.jpg'),
          downloadPhoto: (_) async {
            downloadCalls++;
            return bytes;
          },
        );

        await controller.open();

        expect(downloadCalls, 0);
        expect(controller.state, isA<CredencialActive>());
        expect((controller.state as CredencialActive).photoBytes, bytes);
      });

      test(
          'same id, new url, download succeeds → re-downloads, replaces the '
          'cached bytes, Active with the NEW bytes (row 2)', () async {
        final oldBytes = _fakePhotoBytes('old-rendition');
        await repo.save(
          _activeResponse(photoId: 21, photoUrl: 'https://example.com/a.jpg'),
          photoBytes: oldBytes,
        );

        final newBytes = _fakePhotoBytes('new-rendition');
        var downloadCalls = 0;
        final controller = makeController(
          fetch: () async => _activeResponse(
              photoId: 21, photoUrl: 'https://example.com/b.jpg'),
          downloadPhoto: (url) async {
            downloadCalls++;
            expect(url, 'https://example.com/b.jpg');
            return newBytes;
          },
        );

        await controller.open();

        expect(downloadCalls, 1);
        expect(controller.state, isA<CredencialActive>());
        expect((controller.state as CredencialActive).photoBytes, newBytes);
        expect(await photoStore.read(21), newBytes,
            reason: 'the on-disk file for the SAME id must be replaced, not '
                'duplicated under a new key');

        final cached = await repo.readCached();
        expect(cached!.credential!.photo.url, 'https://example.com/b.jpg',
            reason: 'the cached JSON must now record the NEW url the bytes '
                'actually came from');
      });

      test(
          'same id, new url, download fails → keeps the cached bytes, Active '
          '(never PhotoUnavailable for a URL-only change), and the saved '
          'JSON keeps the OLD url so the next open() retries (row 3)',
          () async {
        final oldBytes = _fakePhotoBytes('still-the-approved-face');
        await repo.save(
          _activeResponse(photoId: 22, photoUrl: 'https://example.com/a.jpg'),
          photoBytes: oldBytes,
        );

        final controller = makeController(
          fetch: () async => _activeResponse(
              photoId: 22, photoUrl: 'https://example.com/b.jpg'),
          downloadPhoto: (_) async => throw Exception('network blip'),
        );

        await controller.open();

        expect(controller.state, isA<CredencialActive>(),
            reason: 'design D16: never PhotoUnavailable for a rendition/url '
                'change alone — the cached face is still the approved face');
        final active = controller.state as CredencialActive;
        expect(active.photoBytes, oldBytes);
        expect(active.stale, isFalse);

        expect(await photoStore.read(22), oldBytes,
            reason: 'the file on disk must be untouched by the failed '
                'download attempt');

        final cached = await repo.readCached();
        expect(cached!.credential!.photo.url, 'https://example.com/a.jpg',
            reason: 'the saved JSON must keep the OLD url (the url the '
                'bytes on disk actually came from) so the next open() '
                'retries the download instead of silently giving up');

        // A second open() must retry the download for the still-different
        // url rather than treating the (now self-consistent) cache as a
        // same-url match.
        var secondDownloadCalls = 0;
        final newBytes = _fakePhotoBytes('recovered-rendition');
        final retryController = makeController(
          fetch: () async => _activeResponse(
              photoId: 22, photoUrl: 'https://example.com/b.jpg'),
          downloadPhoto: (_) async {
            secondDownloadCalls++;
            return newBytes;
          },
        );
        await retryController.open();

        expect(secondDownloadCalls, 1,
            reason: 'the retry must actually attempt a fresh download for '
                'the still-pending url change');
        expect((retryController.state as CredencialActive).photoBytes,
            newBytes);
      });
    });

    group('SUGGESTION 2 — defense-in-depth player_id mismatch wipe', () {
      test(
          'a fresh active response for a DIFFERENT player_id than the cached one '
          'wipes the old cache before saving the new one', () async {
        final oldBytes = _fakePhotoBytes('player-1-photo');
        await repo.save(_activeResponse(photoId: 1, playerId: 1),
            photoBytes: oldBytes);

        final newBytes = _fakePhotoBytes('player-2-photo');
        final controller = makeController(
          fetch: () async => _activeResponse(photoId: 2, playerId: 2),
          downloadPhoto: (_) async => newBytes,
        );

        await controller.open();

        expect(controller.state, isA<CredencialActive>());
        expect(await photoStore.read(1), isNull);
      });
    });

    group('200 no_photo', () {
      test('no request → NoPhoto, wipes any previous cache', () async {
        await repo.save(_activeResponse(), photoBytes: Uint8List(0));

        final controller =
            makeController(fetch: () async => _noPhotoResponse());
        await controller.open();

        expect(controller.state, isA<CredencialNoPhoto>());
        expect(await repo.readCached(), isNull);
      });

      test('pending request → PendingPhoto, wipes any previous cache',
          () async {
        await repo.save(_activeResponse(), photoBytes: Uint8List(0));

        final controller = makeController(
          fetch: () async => _noPhotoResponse(
            photoRequest: const CredencialPhotoRequest(
              id: 1,
              status: PhotoRequestStatus.pending,
              createdAt: '2026-09-29 00:00:00',
            ),
          ),
        );
        await controller.open();

        expect(controller.state, isA<CredencialPendingPhoto>());
        expect(await repo.readCached(), isNull);
      });

      test('rejected request → RejectedPhoto, wipes any previous cache',
          () async {
        final controller = makeController(
          fetch: () async => _noPhotoResponse(
            photoRequest: const CredencialPhotoRequest(
              id: 2,
              status: PhotoRequestStatus.rejected,
              createdAt: '2026-09-29 00:00:00',
            ),
          ),
        );
        await controller.open();

        expect(controller.state, isA<CredencialRejectedPhoto>());
      });
    });

    test(
        '200 blocked → Blocked, wipes previous cache (spec: blocked player loses credential)',
        () async {
      await repo.save(_activeResponse(), photoBytes: Uint8List(0));

      final controller = makeController(fetch: () async => _blockedResponse);
      await controller.open();

      expect(controller.state, isA<CredencialBlocked>());
      expect(await repo.readCached(), isNull);
    });

    test('200 not_a_player → NotAPlayer, wipes previous cache', () async {
      await repo.save(_activeResponse(), photoBytes: Uint8List(0));

      final controller = makeController(fetch: () async => _notAPlayerResponse);
      await controller.open();

      expect(controller.state, isA<CredencialNotAPlayer>());
      expect(await repo.readCached(), isNull);
    });

    test(
        'an unrecoverable 401 (ProdeAuthRequired) → NotSignedIn, wipes cache '
        '(spec: revalidation blocked by a revoked session)', () async {
      await repo.save(_activeResponse(), photoBytes: Uint8List(0));

      final controller = makeController(
        fetch: () async => throw const ProdeAuthRequired(
            code: 'session_revoked', message: 'revoked'),
      );
      await controller.open();

      expect(controller.state, isA<CredencialNotSignedIn>());
      expect(await repo.readCached(), isNull);
    });

    group('revalidation failure (network/5xx)', () {
      test(
          'cache present and not hard-expired → keeps showing it, marked stale, cache untouched',
          () async {
        final bytes = _fakePhotoBytes('cached-photo');
        final cachedResponse =
            _activeResponse(photoId: 8, expiresAt: '2027-09-29 00:00:00');
        await repo.save(cachedResponse, photoBytes: bytes);

        final controller = makeController(
          fetch: () async => throw Exception('network unreachable'),
          now: DateTime.parse('2026-10-01T00:00:00'),
        );

        await controller.open();

        expect(controller.state, isA<CredencialActive>());
        final active = controller.state as CredencialActive;
        expect(active.stale, isTrue);
        expect(active.credential.photo.id, 8);
        // The "keep last verified, marked stale" fallback must also hand
        // Active the cached bytes, never a byte-less state.
        expect(active.photoBytes, bytes);
        expect(await repo.readCached(), equals(cachedResponse));
      });

      test('cache present but hard-expired (now >= expires_at) → Expired',
          () async {
        final bytes = _fakePhotoBytes('expired-photo');
        await repo.save(
          _activeResponse(photoId: 9, expiresAt: '2026-01-01 00:00:00'),
          photoBytes: bytes,
        );

        final controller = makeController(
          fetch: () async => throw Exception('offline'),
          now: DateTime.parse('2027-01-01T00:00:00'),
        );

        await controller.open();

        expect(controller.state, isA<CredencialExpired>());
      });

      test('no cache at all → OfflineNoCache', () async {
        final controller = makeController(
          fetch: () async => throw Exception('offline, nothing cached'),
        );

        await controller.open();

        expect(controller.state, isA<CredencialOfflineNoCache>());
      });
    });

    test(
        'open() shows the cached credential optimistically (stale) before the '
        'network call resolves, then replaces it once it succeeds', () async {
      final bytes = _fakePhotoBytes('optimistic-photo');
      await repo.save(_activeResponse(photoId: 11), photoBytes: bytes);

      final completer = Completer<CredencialResponse>();
      final controller = makeController(fetch: () => completer.future);

      final openFuture = controller.open();

      // Let the cache-read (real file I/O + secure storage) resolve without
      // letting the (still pending) network call resolve.
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(controller.state, isA<CredencialActive>());
      expect((controller.state as CredencialActive).stale, isTrue);
      // The optimistic cache display must also carry bytes by construction,
      // never a byte-less Active.
      expect((controller.state as CredencialActive).photoBytes, bytes);

      completer.complete(_activeResponse(photoId: 11));
      await openFuture;

      expect(controller.state, isA<CredencialActive>());
      expect((controller.state as CredencialActive).stale, isFalse);
    });
  });

  group('downloadCredencialPhoto (status/content-type/sniff/decode validation)',
      () {
    test('200 + image/jpeg + decodable → returns the body bytes', () async {
      final bytes = Uint8List.fromList([0xFF, 0xD8, 0xFF, 1, 2, 3]);
      final client = MockClient((request) async => http.Response.bytes(
            bytes,
            200,
            headers: {'content-type': 'image/jpeg'},
          ));

      final result = await downloadCredencialPhoto(
        client,
        'https://example.com/p.jpg',
        decodes: (_) async => true,
      );

      expect(result, bytes);
    });

    test('200 + image/png + decodable → returns the body bytes', () async {
      final bytes = Uint8List.fromList(
          [0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A, 1, 2]);
      final client = MockClient((request) async => http.Response.bytes(
            bytes,
            200,
            headers: {'content-type': 'image/png'},
          ));

      final result = await downloadCredencialPhoto(
        client,
        'https://example.com/p.png',
        decodes: (_) async => true,
      );

      expect(result, bytes);
    });

    test('200 + image/webp + decodable → returns the body bytes (design rev 9: '
        'webp is now accepted, unlike the retired sha-based path)', () async {
      final bytes = Uint8List.fromList([
        0x52, 0x49, 0x46, 0x46, // RIFF
        0, 0, 0, 0,
        0x57, 0x45, 0x42, 0x50, // WEBP
      ]);
      final client = MockClient((request) async => http.Response.bytes(
            bytes,
            200,
            headers: {'content-type': 'image/webp'},
          ));

      final result = await downloadCredencialPhoto(
        client,
        'https://example.com/p.webp',
        decodes: (_) async => true,
      );

      expect(result, bytes);
    });

    test('non-200 status (e.g. a redirect-to-login error page) throws',
        () async {
      final client = MockClient((request) async => http.Response(
            '<html>login</html>',
            302,
            headers: {'content-type': 'text/html'},
          ));

      await expectLater(
        downloadCredencialPhoto(client, 'https://example.com/p.jpg'),
        throwsA(isA<Exception>()),
      );
    });

    test('200 with a non-image content-type (e.g. an HTML error page) throws',
        () async {
      final client = MockClient((request) async => http.Response(
            '<html>error</html>',
            200,
            headers: {'content-type': 'text/html'},
          ));

      await expectLater(
        downloadCredencialPhoto(client, 'https://example.com/p.jpg'),
        throwsA(isA<Exception>()),
      );
    });

    test('missing content-type header throws', () async {
      final client =
          MockClient((request) async => http.Response.bytes([1], 200));

      await expectLater(
        downloadCredencialPhoto(client, 'https://example.com/p.jpg'),
        throwsA(isA<Exception>()),
      );
    });

    test(
        '200 + image content-type but garbage bytes with no valid image '
        'signature throws (sniff rejects it before any decode attempt)',
        () async {
      final client = MockClient((request) async => http.Response.bytes(
            [1, 2, 3, 4, 5],
            200,
            headers: {'content-type': 'image/jpeg'},
          ));

      await expectLater(
        downloadCredencialPhoto(
          client,
          'https://example.com/p.jpg',
          decodes: (_) async =>
              fail('decodes must never run once the sniff already rejected'),
        ),
        throwsA(isA<Exception>()),
      );
    });

    test(
        '200 + valid signature but the decode check reports failure → throws',
        () async {
      final bytes = Uint8List.fromList([0xFF, 0xD8, 0xFF, 1, 2, 3]);
      final client = MockClient((request) async => http.Response.bytes(
            bytes,
            200,
            headers: {'content-type': 'image/jpeg'},
          ));

      await expectLater(
        downloadCredencialPhoto(
          client,
          'https://example.com/p.jpg',
          decodes: (_) async => false,
        ),
        throwsA(isA<Exception>()),
      );
    });

    // -------------------------------------------------------------------
    // WARNING 2 (verify-report 1575): every other test above injects a fake
    // `decodes` callback. The production default — `_defaultDecodeCheck`,
    // backed by the real `ui.instantiateImageCodec` + `getNextFrame()` — had
    // zero coverage anywhere in this PR. `instantiateImageCodec` needs the
    // real Flutter engine image pipeline, which the normal fake-async `test`
    // zone does not provide; `tester.runAsync` (design's own "Testing
    // Strategy") escapes the fake zone so the real codec can run inside a
    // `testWidgets` test without pumping any actual widget.
    // -------------------------------------------------------------------
    testWidgets(
        'downloadCredencialPhoto with the REAL default decode check (no '
        'injected `decodes`) accepts a genuine 1x1 PNG and rejects a '
        'truncated one, under tester.runAsync', (tester) async {
      await tester.runAsync(() async {
        // A minimal, valid, hand-verifiable 1x1 transparent PNG.
        final validPng = base64Decode(
          'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUB'
          'AScY42YAAAAASUVORK5CYII=',
        );
        expect(sniffImageFormat(validPng), ImageSignature.png);

        final okClient = MockClient((request) async => http.Response.bytes(
              validPng,
              200,
              headers: {'content-type': 'image/png'},
            ));

        // No `decodes:` override here — this exercises the production
        // default (`_defaultDecodeCheck`) end to end, exactly as
        // `_defaultDownloadPhoto` calls it in the shipped app.
        final result = await downloadCredencialPhoto(
          okClient,
          'https://example.com/real.png',
        );
        expect(result, validPng);

        // Same valid PNG signature (so the cheap sniff still passes), but
        // the body is cut short — only the real codec can catch this.
        final truncatedPng = Uint8List.fromList(validPng.sublist(0, 20));
        expect(sniffImageFormat(truncatedPng), ImageSignature.png,
            reason: 'the truncation must still look like a PNG by '
                'signature alone — only the real decode step may reject '
                'it, proving this test is not vacuously passing on a '
                'sniff rejection');

        final badClient = MockClient((request) async => http.Response.bytes(
              truncatedPng,
              200,
              headers: {'content-type': 'image/png'},
            ));

        await expectLater(
          downloadCredencialPhoto(badClient, 'https://example.com/real.png'),
          throwsA(isA<Exception>()),
        );
      });
    });
  });
}

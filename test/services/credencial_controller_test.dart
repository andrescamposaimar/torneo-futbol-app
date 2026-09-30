import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:crypto/crypto.dart' as crypto;
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

// ---------------------------------------------------------------------------
// Response builders
// ---------------------------------------------------------------------------

String _sha256Of(Uint8List bytes) => crypto.sha256.convert(bytes).toString();

CredencialResponse _activeResponse({
  String sha256 = 'validsha',
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
      photo: CredencialPhoto(url: 'https://example.com/p.jpg', sha256: sha256),
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
        final bytes = Uint8List.fromList('photo-bytes'.codeUnits);
        final sha = _sha256Of(bytes);
        var downloadCalls = 0;

        final controller = makeController(
          fetch: () async => _activeResponse(sha256: sha),
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
        // Decision 1523 / NEW WARNING 1 (verify-report 1575, slice 3b
        // re-verify): Active must carry verified bytes by construction.
        expect(_sha256Of(active.photoBytes), sha);

        final cached = await repo.readCached();
        expect(cached, isNotNull);
        expect(cached!.credential!.photo.sha256, sha);
      });

      test(
          'unchanged photo sha vs. previous cache → photo is NOT re-downloaded',
          () async {
        final bytes = Uint8List.fromList('same-photo'.codeUnits);
        final sha = _sha256Of(bytes);
        await repo.save(_activeResponse(sha256: sha), photoBytes: bytes);

        var downloadCalls = 0;
        final controller = makeController(
          fetch: () async => _activeResponse(sha256: sha),
          downloadPhoto: (url) async {
            downloadCalls++;
            return bytes;
          },
        );

        await controller.open();

        expect(downloadCalls, 0);
        expect(controller.state, isA<CredencialActive>());
        // Decision 1523 / NEW WARNING 1 (verify-report 1575, slice 3b
        // re-verify): the unchanged-sha fast path must still hand Active
        // the verified bytes read back from disk, not skip them.
        final active = controller.state as CredencialActive;
        expect(_sha256Of(active.photoBytes), sha);
      });

      test(
          'pending replacement request → Active.replacement = pending, currently approved photo kept',
          () async {
        final bytes = Uint8List.fromList('approved-photo'.codeUnits);
        final sha = _sha256Of(bytes);
        final controller = makeController(
          fetch: () async => _activeResponse(
            sha256: sha,
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
        final bytes = Uint8List.fromList('approved-photo-2'.codeUnits);
        final sha = _sha256Of(bytes);
        final controller = makeController(
          fetch: () async => _activeResponse(
            sha256: sha,
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
      // CRITICAL fix (verify-report 1575, slice 3b): decision 1523 forbids
      // ever rendering the valid-styled card without a verified photo for
      // the CURRENT approved sha256. A failed/mismatched download during a
      // sha rotation must produce an explicit non-valid state, never a
      // "successful" Active with a placeholder.
      // -----------------------------------------------------------------

      test(
          'photo download failure on a sha rotation → CredencialPhotoUnavailable, '
          'never a placeholder-styled Active', () async {
        final controller = makeController(
          fetch: () async => _activeResponse(sha256: 'newsha'),
          downloadPhoto: (_) async => throw Exception('network blip'),
        );

        await controller.open();

        expect(controller.state, isA<CredencialPhotoUnavailable>());
      });

      test(
          'a failed download during a sha rotation keeps the OLD verified photo '
          'on disk and does not overwrite the cached JSON', () async {
        final oldBytes = Uint8List.fromList('old-approved-photo'.codeUnits);
        final oldSha = _sha256Of(oldBytes);
        final oldResponse = _activeResponse(sha256: oldSha);
        await repo.save(oldResponse, photoBytes: oldBytes);

        final controller = makeController(
          fetch: () async =>
              _activeResponse(sha256: 'rotated-sha-never-verified'),
          downloadPhoto: (_) async => throw Exception('network blip'),
        );

        await controller.open();

        expect(controller.state, isA<CredencialPhotoUnavailable>());
        expect(
          await photoStore.readVerified(oldSha),
          isNotNull,
          reason: 'the last known-good verified photo must survive a failed '
              'download during a sha rotation',
        );
        expect(await repo.readCached(), equals(oldResponse));
      });

      test(
          'downloaded bytes whose sha256 does not match the declared photo.sha256 '
          '→ CredencialPhotoUnavailable, nothing saved', () async {
        final controller = makeController(
          fetch: () async =>
              _activeResponse(sha256: 'declared-sha-server-claims'),
          downloadPhoto: (_) async =>
              Uint8List.fromList('wrong-bytes'.codeUnits),
        );

        await controller.open();

        expect(controller.state, isA<CredencialPhotoUnavailable>());
        expect(await repo.readCached(), isNull);
      });

      test(
          'offline open with a cached JSON whose photo file is missing on disk → '
          'OfflineNoCache, never an Active placeholder', () async {
        // Bypass repo.save() to simulate exactly the corrupted-cache shape the
        // gate flagged: a JSON pointer to a sha256 with no matching file.
        final response = _activeResponse(sha256: 'missing-on-disk');
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
        final bytes = Uint8List.fromList('recovered-photo'.codeUnits);
        final sha = _sha256Of(bytes);
        var shouldFail = true;

        final controller = makeController(
          fetch: () async => _activeResponse(sha256: sha),
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

    group('SUGGESTION 2 — defense-in-depth player_id mismatch wipe', () {
      test(
          'a fresh active response for a DIFFERENT player_id than the cached one '
          'wipes the old cache before saving the new one', () async {
        final oldBytes = Uint8List.fromList('player-1-photo'.codeUnits);
        final oldSha = _sha256Of(oldBytes);
        await repo.save(_activeResponse(sha256: oldSha, playerId: 1),
            photoBytes: oldBytes);

        final newBytes = Uint8List.fromList('player-2-photo'.codeUnits);
        final newSha = _sha256Of(newBytes);
        final controller = makeController(
          fetch: () async => _activeResponse(sha256: newSha, playerId: 2),
          downloadPhoto: (_) async => newBytes,
        );

        await controller.open();

        expect(controller.state, isA<CredencialActive>());
        expect(await photoStore.readVerified(oldSha), isNull);
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
        final bytes = Uint8List.fromList('cached-photo'.codeUnits);
        final sha = _sha256Of(bytes);
        final cachedResponse =
            _activeResponse(sha256: sha, expiresAt: '2027-09-29 00:00:00');
        await repo.save(cachedResponse, photoBytes: bytes);

        final controller = makeController(
          fetch: () async => throw Exception('network unreachable'),
          now: DateTime.parse('2026-10-01T00:00:00'),
        );

        await controller.open();

        expect(controller.state, isA<CredencialActive>());
        final active = controller.state as CredencialActive;
        expect(active.stale, isTrue);
        expect(active.credential.photo.sha256, sha);
        // Decision 1523 / NEW WARNING 1 (verify-report 1575, slice 3b
        // re-verify): the "keep last verified, marked stale" fallback must
        // also hand Active the verified bytes, never a byte-less state.
        expect(_sha256Of(active.photoBytes), sha);
        expect(await repo.readCached(), equals(cachedResponse));
      });

      test('cache present but hard-expired (now >= expires_at) → Expired',
          () async {
        final bytes = Uint8List.fromList('expired-photo'.codeUnits);
        final sha = _sha256Of(bytes);
        await repo.save(
          _activeResponse(sha256: sha, expiresAt: '2026-01-01 00:00:00'),
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
      final bytes = Uint8List.fromList('optimistic-photo'.codeUnits);
      final sha = _sha256Of(bytes);
      await repo.save(_activeResponse(sha256: sha), photoBytes: bytes);

      final completer = Completer<CredencialResponse>();
      final controller = makeController(fetch: () => completer.future);

      final openFuture = controller.open();

      // Let the cache-read (real file I/O + secure storage) resolve without
      // letting the (still pending) network call resolve.
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(controller.state, isA<CredencialActive>());
      expect((controller.state as CredencialActive).stale, isTrue);
      // Decision 1523 / NEW WARNING 1 (verify-report 1575, slice 3b
      // re-verify): the optimistic cache display must also carry verified
      // bytes by construction, never a byte-less Active.
      expect(_sha256Of((controller.state as CredencialActive).photoBytes), sha);

      completer.complete(_activeResponse(sha256: sha));
      await openFuture;

      expect(controller.state, isA<CredencialActive>());
      expect((controller.state as CredencialActive).stale, isFalse);
    });
  });

  group('fetchAndVerifyPhoto (WARNING 1: statusCode/content-type validation)',
      () {
    test('200 + image/jpeg → returns the body bytes', () async {
      final client = MockClient((request) async => http.Response.bytes(
            [1, 2, 3],
            200,
            headers: {'content-type': 'image/jpeg'},
          ));

      final bytes =
          await fetchAndVerifyPhoto(client, 'https://example.com/p.jpg');

      expect(bytes, [1, 2, 3]);
    });

    test('200 + image/png → returns the body bytes', () async {
      final client = MockClient((request) async => http.Response.bytes(
            [4, 5, 6],
            200,
            headers: {'content-type': 'image/png'},
          ));

      final bytes =
          await fetchAndVerifyPhoto(client, 'https://example.com/p.png');

      expect(bytes, [4, 5, 6]);
    });

    test('non-200 status (e.g. a redirect-to-login error page) throws',
        () async {
      final client = MockClient((request) async => http.Response(
            '<html>login</html>',
            302,
            headers: {'content-type': 'text/html'},
          ));

      await expectLater(
        fetchAndVerifyPhoto(client, 'https://example.com/p.jpg'),
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
        fetchAndVerifyPhoto(client, 'https://example.com/p.jpg'),
        throwsA(isA<Exception>()),
      );
    });

    test('missing content-type header throws', () async {
      final client =
          MockClient((request) async => http.Response.bytes([1], 200));

      await expectLater(
        fetchAndVerifyPhoto(client, 'https://example.com/p.jpg'),
        throwsA(isA<Exception>()),
      );
    });
  });
}

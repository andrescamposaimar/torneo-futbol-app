import 'dart:async';
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Storage key for the single JSON blob that persists all Prode tokens.
///
/// All four fields (access_token, refresh_token, session_version, tenant_id)
/// are serialised together under this key. This makes every bulk operation
/// atomic at the storage layer: one write instead of four independent writes
/// that could be interrupted between them.
///
/// NOTE: No migration from the individual-key layout (PR-04) is needed
/// because PR-04 never shipped to users (the branch was not merged to main).
/// If individual keys are found in storage they will simply be ignored and
/// overwritten on the next successful auth call.
abstract class _Keys {
  static const tokens = 'prode_tokens';
}

/// Immutable snapshot of all four stored Prode token fields.
///
/// Returned by [ProdeAuthRepository.readAll] so callers can read every field
/// in one storage round-trip. All fields are nullable — a missing or empty
/// blob returns all nulls.
class TokenSnapshot {
  final String? accessToken;
  final String? refreshToken;
  final String? sessionVersion;
  final String? tenantId;

  const TokenSnapshot({
    this.accessToken,
    this.refreshToken,
    this.sessionVersion,
    this.tenantId,
  });
}

/// JSON blob schema:
///   {
///     "access_token": String?,
///     "refresh_token": String?,
///     "session_version": String?,
///     "tenant_id": String?
///   }
///
/// Every field is nullable so that a partial or empty blob never causes a
/// decode error — missing keys are returned as null by the read methods.
typedef _TokenMap = Map<String, dynamic>;

/// Thin wrapper around [FlutterSecureStorage] for Prode auth tokens.
///
/// All four fields are stored together as a single JSON-encoded blob under
/// [_Keys.tokens]. Each individual read decodes the blob and returns the
/// requested field. Each write does a read-modify-write under a private
/// serialised-Future mutex so concurrent writes cannot interleave.
///
/// Inject a custom [FlutterSecureStorage] instance via the constructor to
/// enable testing without platform channel dependencies.
///
/// [onTokensChanged] is fired after any successful write or clear. Set by
/// [prodeAuthControllerProvider] to point at [ProdeApiService.invalidateTokenCache]
/// so the in-memory cache stays consistent without callers having to
/// pair write calls with manual cache-invalidation calls.
class ProdeAuthRepository {
  final FlutterSecureStorage _storage;

  /// Callback fired after every successful storage mutation (write, writeTokens,
  /// clear, and the private _writeX helpers). Wired in [prodeAuthControllerProvider]
  /// to [ProdeApiService.invalidateTokenCache].
  void Function()? onTokensChanged;

  /// Serialises concurrent writes so the read-modify-write cycle is safe.
  ///
  /// Each write chains onto the tail of this future, ensuring that no two
  /// writes race on the single blob key. Reads are NOT serialised because
  /// Flutter's isolate model means a read racing with a write will always
  /// see either the old or the new complete blob — never a partial write.
  Future<void> _writeLock = Future.value();

  ProdeAuthRepository({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  Future<_TokenMap> _readBlob() async {
    final raw = await _storage.read(key: _Keys.tokens);
    if (raw == null) return {};
    try {
      final decoded = json.decode(raw);
      if (decoded is Map<String, dynamic>) return decoded;
      return {};
    } on FormatException {
      return {};
    }
  }

  /// Serialises a [mutate] callback through the write lock.
  ///
  /// [mutate] receives the current token map (never null), modifies it
  /// in place, and returns it. The modified map is then encoded and written
  /// back to storage atomically.
  ///
  /// Failure isolation: errors surface to the CALLER via the returned future
  /// while [_writeLock] itself always resolves successfully. A transient
  /// storage failure on one write does NOT poison the lock chain for every
  /// subsequent write on this repository instance.
  ///
  /// Fires [onTokensChanged] AFTER the lock body releases and the caller's
  /// future resolves successfully. Callback errors are swallowed so a
  /// throwing subscriber cannot poison the chain or make the caller think
  /// the storage write failed.
  Future<void> _lockedWrite(void Function(_TokenMap map) mutate) {
    final completer = Completer<void>();
    _writeLock = _writeLock.then((_) async {
      try {
        final map = await _readBlob();
        mutate(map);
        await _storage.write(
          key: _Keys.tokens,
          value: json.encode(map),
        );
        completer.complete();
      } catch (e, st) {
        completer.completeError(e, st);
      }
    });
    return completer.future.then((_) {
      try {
        onTokensChanged?.call();
      } catch (_) {
        // A buggy subscriber must not pretend the write failed.
      }
    });
  }

  // ---------------------------------------------------------------------------
  // Individual read methods (public — used by ProdeApiService cached path)
  // ---------------------------------------------------------------------------

  Future<String?> readAccessToken() async {
    final map = await _readBlob();
    return map['access_token'] as String?;
  }

  Future<String?> readRefreshToken() async {
    final map = await _readBlob();
    return map['refresh_token'] as String?;
  }

  Future<String?> readSessionVersion() async {
    final map = await _readBlob();
    return map['session_version'] as String?;
  }

  Future<String?> readTenantId() async {
    final map = await _readBlob();
    return map['tenant_id'] as String?;
  }

  // ---------------------------------------------------------------------------
  // Bulk read — eliminates sequential _readBlob() calls in bootstrap
  // ---------------------------------------------------------------------------

  /// Reads all four token fields in a single storage round-trip.
  ///
  /// Eliminates the TOCTOU window that arises from calling [readAccessToken],
  /// [readRefreshToken], and [readSessionVersion] sequentially — each of which
  /// decodes the blob independently. Bootstrap should prefer this method.
  Future<TokenSnapshot> readAll() async {
    final map = await _readBlob();
    return TokenSnapshot(
      accessToken: map['access_token'] as String?,
      refreshToken: map['refresh_token'] as String?,
      sessionVersion: map['session_version'] as String?,
      tenantId: map['tenant_id'] as String?,
    );
  }

  // ---------------------------------------------------------------------------
  // Individual write helpers — library-private, exposed only for testing
  // ---------------------------------------------------------------------------

  /// Writes only the access token field.
  ///
  /// No production code calls this directly — use [write] or [writeTokens]
  /// instead. Annotated [@visibleForTesting] so tests can exercise the
  /// individual-field write path without promoting these to a public API.
  @visibleForTesting
  Future<void> writeAccessToken(String token) =>
      _lockedWrite((m) => m['access_token'] = token);

  @visibleForTesting
  Future<void> writeRefreshToken(String token) =>
      _lockedWrite((m) => m['refresh_token'] = token);

  @visibleForTesting
  Future<void> writeSessionVersion(String version) =>
      _lockedWrite((m) => m['session_version'] = version);

  @visibleForTesting
  Future<void> writeTenantId(String id) =>
      _lockedWrite((m) => m['tenant_id'] = id);

  // ---------------------------------------------------------------------------
  // Bulk operations
  // ---------------------------------------------------------------------------

  /// Persist all token fields in one logical write.
  /// Used at initial login when tenantId is known.
  Future<void> write({
    required String accessToken,
    required String refreshToken,
    required String sessionVersion,
    required String tenantId,
  }) =>
      _lockedWrite((m) {
        m['access_token'] = accessToken;
        m['refresh_token'] = refreshToken;
        m['session_version'] = sessionVersion;
        m['tenant_id'] = tenantId;
      });

  /// Persist only the rotating token fields. Used by the refresh path,
  /// where the server does not echo tenantId back. Avoids overwriting
  /// tenantId with stale or empty values.
  Future<void> writeTokens({
    required String accessToken,
    required String refreshToken,
    required String sessionVersion,
  }) =>
      _lockedWrite((m) {
        m['access_token'] = accessToken;
        m['refresh_token'] = refreshToken;
        m['session_version'] = sessionVersion;
        // tenant_id is intentionally NOT touched here
      });

  /// Remove all Prode-related tokens from secure storage.
  /// Call on logout, account deletion, or session_revoked.
  ///
  /// Uses the same failure-isolation pattern as [_lockedWrite]: the error
  /// surfaces to the caller but the lock chain stays healthy.
  /// Fires [onTokensChanged] AFTER the lock body releases (same semantics
  /// as [_lockedWrite]). Callback errors are swallowed.
  Future<void> clear() {
    final completer = Completer<void>();
    _writeLock = _writeLock.then((_) async {
      try {
        await _storage.delete(key: _Keys.tokens);
        completer.complete();
      } catch (e, st) {
        completer.completeError(e, st);
      }
    });
    return completer.future.then((_) {
      try {
        onTokensChanged?.call();
      } catch (_) {
        // A buggy subscriber must not pretend the clear failed.
      }
    });
  }
}

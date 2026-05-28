import 'dart:convert';
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
class ProdeAuthRepository {
  final FlutterSecureStorage _storage;

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
  Future<void> _lockedWrite(void Function(_TokenMap map) mutate) {
    // Chain onto the tail of the current lock future.
    _writeLock = _writeLock.then((_) async {
      final map = await _readBlob();
      mutate(map);
      await _storage.write(
        key: _Keys.tokens,
        value: json.encode(map),
      );
    });
    return _writeLock;
  }

  // ---------------------------------------------------------------------------
  // Access token
  // ---------------------------------------------------------------------------

  Future<String?> readAccessToken() async {
    final map = await _readBlob();
    return map['access_token'] as String?;
  }

  Future<void> writeAccessToken(String token) =>
      _lockedWrite((m) => m['access_token'] = token);

  // ---------------------------------------------------------------------------
  // Refresh token
  // ---------------------------------------------------------------------------

  Future<String?> readRefreshToken() async {
    final map = await _readBlob();
    return map['refresh_token'] as String?;
  }

  Future<void> writeRefreshToken(String token) =>
      _lockedWrite((m) => m['refresh_token'] = token);

  // ---------------------------------------------------------------------------
  // Session version
  // ---------------------------------------------------------------------------

  Future<String?> readSessionVersion() async {
    final map = await _readBlob();
    return map['session_version'] as String?;
  }

  Future<void> writeSessionVersion(String version) =>
      _lockedWrite((m) => m['session_version'] = version);

  // ---------------------------------------------------------------------------
  // Tenant ID
  // ---------------------------------------------------------------------------

  Future<String?> readTenantId() async {
    final map = await _readBlob();
    return map['tenant_id'] as String?;
  }

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
  Future<void> clear() {
    _writeLock = _writeLock.then((_) async {
      await _storage.delete(key: _Keys.tokens);
    });
    return _writeLock;
  }
}

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Keys used to persist Prode authentication tokens in secure storage.
abstract class _Keys {
  static const accessToken = 'prode_access_token';
  static const refreshToken = 'prode_refresh_token';
  static const sessionVersion = 'prode_session_version';
  static const tenantId = 'prode_tenant_id';
}

/// Thin wrapper around [FlutterSecureStorage] for Prode auth tokens.
///
/// Keeps the repository dumb and focused: read, write, clear.
/// Inject a custom [FlutterSecureStorage] instance via the constructor
/// to enable testing without platform channel dependencies.
class ProdeAuthRepository {
  final FlutterSecureStorage _storage;

  ProdeAuthRepository({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  // ---------------------------------------------------------------------------
  // Access token
  // ---------------------------------------------------------------------------

  Future<String?> readAccessToken() => _storage.read(key: _Keys.accessToken);

  Future<void> writeAccessToken(String token) =>
      _storage.write(key: _Keys.accessToken, value: token);

  // ---------------------------------------------------------------------------
  // Refresh token
  // ---------------------------------------------------------------------------

  Future<String?> readRefreshToken() => _storage.read(key: _Keys.refreshToken);

  Future<void> writeRefreshToken(String token) =>
      _storage.write(key: _Keys.refreshToken, value: token);

  // ---------------------------------------------------------------------------
  // Session version
  // ---------------------------------------------------------------------------

  Future<String?> readSessionVersion() =>
      _storage.read(key: _Keys.sessionVersion);

  Future<void> writeSessionVersion(String version) =>
      _storage.write(key: _Keys.sessionVersion, value: version);

  // ---------------------------------------------------------------------------
  // Tenant ID
  // ---------------------------------------------------------------------------

  Future<String?> readTenantId() => _storage.read(key: _Keys.tenantId);

  Future<void> writeTenantId(String id) =>
      _storage.write(key: _Keys.tenantId, value: id);

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
  }) async {
    await Future.wait([
      writeAccessToken(accessToken),
      writeRefreshToken(refreshToken),
      writeSessionVersion(sessionVersion),
      writeTenantId(tenantId),
    ]);
  }

  /// Persist only the rotating token fields. Used by the refresh path,
  /// where the server does not echo tenantId back. Avoids overwriting
  /// tenantId with stale or empty values.
  Future<void> writeTokens({
    required String accessToken,
    required String refreshToken,
    required String sessionVersion,
  }) async {
    await Future.wait([
      writeAccessToken(accessToken),
      writeRefreshToken(refreshToken),
      writeSessionVersion(sessionVersion),
    ]);
  }

  /// Remove all Prode-related tokens from secure storage.
  /// Call on logout, account deletion, or session_revoked.
  Future<void> clear() async {
    await Future.wait([
      _storage.delete(key: _Keys.accessToken),
      _storage.delete(key: _Keys.refreshToken),
      _storage.delete(key: _Keys.sessionVersion),
      _storage.delete(key: _Keys.tenantId),
    ]);
  }
}

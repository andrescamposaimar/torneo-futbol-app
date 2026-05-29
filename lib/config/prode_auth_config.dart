import 'package:flutter/foundation.dart';

/// OAuth and SSO configuration for the Prode authenticated feature.
///
/// Attach this to [TenantIntegrations.prodeAuth] for any tenant that enables
/// [TenantFeatures.prode]. Tenants that leave Prode disabled keep
/// [TenantIntegrations.prodeAuth] null.
@immutable
class ProdeAuthConfig {
  /// Google OAuth Web client ID.
  /// Required — used as the server-side token verification audience.
  final String googleWebClientId;

  /// Google OAuth iOS client ID.
  /// Optional but recommended for native Sign-In SDK clarity on iOS.
  final String? googleIosClientId;

  /// Google OAuth Android client ID.
  /// Optional but recommended for native Sign-In SDK clarity on Android.
  final String? googleAndroidClientId;

  /// Apple Services ID (used on Android / web flows).
  /// On iOS, the native Sign in with Apple framework uses the bundle ID
  /// directly, so this field may be null for iOS-only deployments.
  final String? appleServiceId;

  /// Apple Team ID — required for Apple Sign-In server-side validation.
  final String appleTeamId;

  /// Apple OAuth redirect URI (required when [appleServiceId] is set).
  final String? appleRedirectUri;

  const ProdeAuthConfig({
    required this.googleWebClientId,
    this.googleIosClientId,
    this.googleAndroidClientId,
    this.appleServiceId,
    required this.appleTeamId,
    this.appleRedirectUri,
  });
}

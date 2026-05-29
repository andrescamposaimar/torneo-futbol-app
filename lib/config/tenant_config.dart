import 'package:flutter/material.dart';
import 'prode_auth_config.dart';

@immutable
class BrandColors {
  final Color primary;
  final Color accent;
  final Color splashBackground;
  final Color background;
  final Color card;
  final Color textPrimary;
  final Color textSecondary;

  const BrandColors({
    required this.primary,
    required this.accent,
    required this.splashBackground,
    this.background = const Color(0xFFF9F9F9),
    this.card = Colors.white,
    this.textPrimary = Colors.black,
    this.textSecondary = Colors.grey,
  });
}

@immutable
class TenantFeatures {
  final bool waitingLists;
  final bool newsTab;
  final bool ads;

  /// Whether the Prode (prediction game) feature is enabled for this tenant.
  /// Defaults to false — tenants must explicitly opt in.
  final bool prode;

  const TenantFeatures({
    this.waitingLists = false,
    this.newsTab = true,
    this.ads = true,
    this.prode = false,
  });
}

@immutable
class TenantIntegrations {
  final String? appsScriptUrl;

  /// Prode OAuth/SSO configuration.
  /// Must be non-null when [TenantFeatures.prode] is true.
  /// Validated at startup in bootstrap.dart.
  final ProdeAuthConfig? prodeAuth;

  const TenantIntegrations({this.appsScriptUrl, this.prodeAuth});
}

/// Per-tenant content document URLs (PDFs, webviews, etc.).
/// All fields are optional — a tenant that doesn't have a particular document
/// simply leaves it null and the UI hides or disables that entry.
@immutable
class TenantDocuments {
  final String? reglamentoUrl;
  final String? modalidadUrl;
  final List<TenantAnuario> anuarios;
  final String? solicitudCambioUrl;

  const TenantDocuments({
    this.reglamentoUrl,
    this.modalidadUrl,
    this.anuarios = const [],
    this.solicitudCambioUrl,
  });
}

@immutable
class TenantAnuario {
  final String label;
  final String url;
  const TenantAnuario({required this.label, required this.url});
}

@immutable
class TenantConfig {
  final String tenantId;
  final String appName;
  final String apiBaseUrl;
  final String mediaBaseUrl;
  final BrandColors colors;
  final TenantFeatures features;
  final TenantIntegrations integrations;
  final TenantDocuments documents;
  final String logoAsset;
  final String? androidStoreUrl;
  final String? iosStoreUrl;

  const TenantConfig({
    required this.tenantId,
    required this.appName,
    required this.apiBaseUrl,
    required this.mediaBaseUrl,
    required this.colors,
    required this.features,
    this.integrations = const TenantIntegrations(),
    this.documents = const TenantDocuments(),
    required this.logoAsset,
    this.androidStoreUrl,
    this.iosStoreUrl,
  });
}

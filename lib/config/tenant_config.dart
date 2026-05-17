import 'package:flutter/material.dart';

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

  const TenantFeatures({
    this.waitingLists = false,
    this.newsTab = true,
    this.ads = true,
  });
}

@immutable
class TenantIntegrations {
  final String? appsScriptUrl;

  const TenantIntegrations({this.appsScriptUrl});
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
    required this.logoAsset,
    this.androidStoreUrl,
    this.iosStoreUrl,
  });
}

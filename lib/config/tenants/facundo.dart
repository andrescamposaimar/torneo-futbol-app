import 'package:flutter/material.dart';
import '../tenant_config.dart';

/// Facundo tenant configuration.
///
/// Placeholder values are marked with TODO — replace before going live:
///   - apiBaseUrl: real WordPress REST base URL from client
///   - mediaBaseUrl: real wp-content/uploads/media base URL from client
///   - androidStoreUrl / iosStoreUrl: once the app is listed in stores
///   - logoAsset: place the actual logo at assets/images/facundo/app_logo.png
///   - appName: confirm the commercial league name with the client
const facundoTenant = TenantConfig(
  tenantId: 'facundo',
  // TODO: confirm commercial name with client
  appName: 'Liga Facundo',
  // TODO: replace with client's real WordPress REST URL (no trailing slash)
  apiBaseUrl: 'https://PLACEHOLDER.com/wp-json/entre-redes/v1',
  // TODO: replace with client's real media base URL
  mediaBaseUrl: 'https://PLACEHOLDER.com/wp-content/uploads/media',
  colors: BrandColors(
    // TODO: confirm brand colors with client — using green as placeholder
    primary: Color(0xFF2E7D32),
    accent: Color(0xFF66BB6A),
    splashBackground: Color(0xFF2E7D32),
  ),
  features: TenantFeatures(
    waitingLists: false,
    newsTab: true,
    ads: false,
    prode: false,
  ),
  // appsScriptUrl is null because waitingLists=false for Facundo
  // prodeAuth is null because prode=false for Facundo
  integrations: TenantIntegrations(prodeAuth: null),
  // TODO: provide actual logo asset at this path
  logoAsset: 'assets/images/facundo/app_logo.png',
  documents: TenantDocuments(),
  // TODO: set real store URLs once the app is published
  androidStoreUrl: null,
  iosStoreUrl: null,
);

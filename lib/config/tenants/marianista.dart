import 'package:flutter/material.dart';
import '../tenant_config.dart';

const marianistaTenant = TenantConfig(
  tenantId: 'marianista',
  appName: 'Entre Redes',
  apiBaseUrl: 'https://entreredespadres.com.ar/wp-json/entre-redes/v1',
  mediaBaseUrl: 'https://entreredespadres.com.ar/wp-content/uploads/media',
  colors: BrandColors(
    primary: Color(0xFF005BBB),
    accent: Colors.cyan,
    splashBackground: Color(0xFF005BBB),
  ),
  features: TenantFeatures(
    waitingLists: true,
    newsTab: true,
    ads: true,
  ),
  integrations: TenantIntegrations(
    appsScriptUrl:
        'https://entreredespadres.com.ar/wp-content/uploads/media/listas_jugadores.json',
  ),
  logoAsset: 'assets/images/app_logo.png',
  androidStoreUrl:
      'https://play.google.com/store/apps/details?id=com.entreredes.app',
  iosStoreUrl: 'https://apps.apple.com/app/entre-redes/id6743369159',
);

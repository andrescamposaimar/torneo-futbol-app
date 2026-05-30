import 'package:flutter/material.dart';
import '../tenant_config.dart';
import '../prode_auth_config.dart';

const marianistaTenant = TenantConfig(
  tenantId: 'marianista',
  appName: 'Entre Redes',
  apiBaseUrl: 'https://entreredespadres.com.ar/wp-json/entre-redes/v1',
  mediaBaseUrl: 'https://entreredespadres.com.ar/wp-content/uploads/media',
  colors: BrandColors(
    primary: Color(0xFF005BBB),
    accent: Colors.cyan,
    splashBackground: Color(0xFF0057A9),
  ),
  features: TenantFeatures(
    waitingLists: true,
    newsTab: true,
    ads: true,
    prode: true,
  ),
  integrations: TenantIntegrations(
    appsScriptUrl:
        'https://entreredespadres.com.ar/wp-content/uploads/media/listas_jugadores.json',
    // Google OAuth clients provisioned (Cloud project 162105389930).
    // Apple Team ID still pending — Sign in with Apple stays disabled until set.
    prodeAuth: ProdeAuthConfig(
      prodeApiBaseUrl:
          'https://entreredespadres.com.ar/wp-json/entre-redes/v1/prode',
      googleWebClientId:
          '162105389930-0hsham0na79sutur9c15eo97j9f7n6rr.apps.googleusercontent.com',
      googleIosClientId:
          '162105389930-voh4mkso1fp05grfi5scms4u1bh2jdvj.apps.googleusercontent.com',
      googleAndroidClientId:
          '162105389930-c4cmrp0hmf9drerb348sg1rul8u33ias.apps.googleusercontent.com',
      appleServiceId: 'com.entreredes.app.web',
      appleTeamId: '4Z7MM36M65',
      appleRedirectUri:
          'https://entreredespadres.com.ar/wp-json/entre-redes/v1/prode/auth/apple/callback',
    ),
  ),
  documents: TenantDocuments(
    reglamentoUrl:
        'https://entreredespadres.com.ar/wp-content/uploads/2026/REGLAMENTO-CHAMI-2026.pdf',
    modalidadUrl:
        'https://entreredespadres.com.ar/wp-content/uploads/2026/modalidad_torneo_2026.pdf',
    solicitudCambioUrl:
        'https://entreredespadres.com.ar/jugadores/solicitud-de-cambios',
    anuarios: [
      TenantAnuario(
        label: 'Anuario 2022',
        url: 'https://entreredespadres.com.ar/wp-content/uploads/anuarios/Entreredes2022-Anuario.pdf',
      ),
      TenantAnuario(
        label: 'Anuario 2023',
        url: 'https://entreredespadres.com.ar/wp-content/uploads/anuarios/Anuario-2023-OK.pdf',
      ),
      TenantAnuario(
        label: 'Anuario 2024',
        url: 'https://entreredespadres.com.ar/wp-content/uploads/anuarios/Anuario-2024.pdf',
      ),
      TenantAnuario(
        label: 'Anuario 2025',
        url: 'https://entreredespadres.com.ar/wp-content/uploads/anuarios/Anuario-2025.pdf',
      ),
    ],
  ),
  logoAsset: 'assets/images/marianista/app_logo.png',
  androidStoreUrl:
      'https://play.google.com/store/apps/details?id=com.entreredes.app',
  iosStoreUrl: 'https://apps.apple.com/app/entre-redes/id6743369159',
);

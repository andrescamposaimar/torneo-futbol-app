import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/tenant_config.dart';
import 'package:torneo_futbol_app/config/tenant_provider.dart';
import 'package:torneo_futbol_app/providers/service_providers.dart';
import 'package:torneo_futbol_app/screens/team_detail_screen.dart';
import 'package:torneo_futbol_app/services/i_api_service.dart';
import 'package:torneo_futbol_app/services/i_cache_service.dart';

// The screen's bottomNavigationBar is a ZocaloPublicitario, which reads
// tenantConfigProvider on initState. Ads are left off (the TenantFeatures
// default) so it returns before touching SharedPreferences or a remote
// fetch — this test is about the roster's Pts. column, not the ad banner.
const _tenantCfg = TenantConfig(
  tenantId: 'test',
  appName: 'Test',
  apiBaseUrl: 'https://api.test',
  mediaBaseUrl: 'https://media.test',
  colors: BrandColors(
    primary: Colors.blue,
    accent: Colors.cyan,
    splashBackground: Colors.white,
  ),
  features: TenantFeatures(),
  logoAsset: 'assets/images/app_logo.png',
);

// ---------------------------------------------------------------------------
// Stubs
// ---------------------------------------------------------------------------

/// `noSuchMethod` covers the rest of the interface: this screen's Plantel
/// tab only ever reaches for the team's roster.
class _StubApiService implements IApiService {
  /// The raw `metrics.puntaje` value the backend would send for the one
  /// roster player under test — a number, a string, or absent (`null`).
  final dynamic puntaje;

  _StubApiService({required this.puntaje});

  @override
  Future<Map<String, dynamic>> getJugadoresRaw({
    int? temporada,
    int? liga,
    int? zona,
    int? equipoId,
    String? search,
    int? page,
    int? perPage,
  }) async =>
      {
        'items': [
          {
            'id': 1001,
            'title': {'rendered': 'Juan Pérez'},
            'posicion': 'Mediocampista',
            'equipo_id': equipoId,
            'metrics': {'puntaje': puntaje},
          },
        ],
      };

  @override
  Future<List<dynamic>> getPartidosPorEquipoId(int equipoId) async => [];

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

/// Every getCached* call is a deterministic cache miss — same pattern used
/// by the player-detail test suites for this screen family.
class _NoopCacheService implements ICacheService {
  @override
  dynamic noSuchMethod(Invocation invocation) => Future.value(null);
}

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

Future<void> _pump(WidgetTester tester, {required dynamic puntaje}) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        tenantConfigProvider.overrideWithValue(_tenantCfg),
        apiServiceProvider.overrideWithValue(_StubApiService(puntaje: puntaje)),
        cacheServiceProvider.overrideWithValue(_NoopCacheService()),
      ],
      child: MaterialApp(
        home: TeamDetailScreen(team: const {
          'id': 55,
          'nombre': 'CHELSEA',
          'imagen': null,
        }),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

void main() {
  group('TeamDetailScreen · roster "Pts." column, an unrated player', () {
    testWidgets("renders '-' through formatearPuntaje, not a confident '0'",
        (tester) async {
      await _pump(tester, puntaje: 0);

      expect(find.text('-'), findsOneWidget);
      expect(find.text('0'), findsNothing);
      // find.text defaults to skipOffstage: true; rule out "scrolled off"
      // rather than "never rendered" as the reason for the '0' absence.
      expect(find.text('0', skipOffstage: false), findsNothing);
    });
  });

  group('TeamDetailScreen · roster "Pts." column, a rated player', () {
    testWidgets('renders the real rating', (tester) async {
      await _pump(tester, puntaje: '7,5');

      expect(find.text('7.5'), findsOneWidget);
      expect(find.text('-'), findsNothing);
    });
  });
}

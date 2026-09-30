import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/models/cambios_mis_equipos.dart';
import 'package:torneo_futbol_app/providers/cambios_providers.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_context_screen.dart';
import 'package:torneo_futbol_app/services/cambios_api_service.dart';
import 'package:torneo_futbol_app/services/cambios_context_controller.dart';
import 'package:torneo_futbol_app/services/cambios_plantel_controller.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';

// ---------------------------------------------------------------------------
// Stub controllers — no network, fixed initial state (mirrors the Prode
// screen tests' _StubController convention).
// ---------------------------------------------------------------------------

class _StubContextController extends CambiosContextController {
  _StubContextController(CambiosContextState initialState)
      : super(_fakeService()) {
    state = initialState;
  }

  @override
  Future<void> load() async {}

  @override
  Future<void> refresh() async {}
}

class _StubPlantelController extends CambiosPlantelController {
  _StubPlantelController(CambiosPlantelState initialState) : super(_fakeService()) {
    state = initialState;
  }

  @override
  Future<void> load({required int seasonId, required int teamId}) async {}
}

CambiosApiService _fakeService() {
  return CambiosApiService(
    baseUrl: 'https://nowhere.test/cambios',
    prodeApi: ProdeApiService(
      config: const ProdeAuthConfig(
        prodeApiBaseUrl: 'https://nowhere.test/prode',
        googleWebClientId: 'test',
        appleTeamId: 'TEST',
      ),
      authRepo: ProdeAuthRepository(),
    ),
  );
}

Future<void> _pumpScreen(
  WidgetTester tester,
  CambiosContextState initialState,
) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        cambiosContextControllerProvider
            .overrideWith((ref) => _StubContextController(initialState)),
        cambiosPlantelControllerProvider.overrideWith(
          (ref, scope) => _StubPlantelController(const CambiosPlantelLoaded(plazas: [])),
        ),
      ],
      child: const MaterialApp(
        home: Scaffold(body: CambiosContextScreen(stale: false)),
      ),
    ),
  );
  await tester.pump();
}

void main() {
  group('CambiosContextScreen', () {
    testWidgets('Loading -> shows a spinner', (tester) async {
      await _pumpScreen(tester, const CambiosContextLoading());
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('Error -> shows a friendly message and a retry button', (tester) async {
      await _pumpScreen(tester, const CambiosContextError());
      expect(find.text('Algo salió mal'), findsOneWidget);
      expect(find.text('Reintentar'), findsOneWidget);
    });

    testWidgets('NotCaptain -> shows the honest "no sos capitán" state, not an error', (tester) async {
      await _pumpScreen(tester, const CambiosContextNotCaptain());
      expect(find.text('No sos capitán'), findsOneWidget);
      expect(find.text('Algo salió mal'), findsNothing);
    });

    testWidgets('Ready with one team -> renders the roster directly, no picker', (tester) async {
      await _pumpScreen(
        tester,
        const CambiosContextReady(
          seasonId: 7,
          teams: [CambiosTeam(teamId: 1, nombre: 'Sub 13 A')],
          selectedTeamId: 1,
        ),
      );

      expect(find.byKey(const Key('cambios_team_picker')), findsNothing);
      expect(find.text('Mi Plantel'), findsOneWidget);
    });

    testWidgets('Ready with more than one team -> shows a picker', (tester) async {
      await _pumpScreen(
        tester,
        const CambiosContextReady(
          seasonId: 7,
          teams: [
            CambiosTeam(teamId: 1, nombre: 'Sub 13 A'),
            CambiosTeam(teamId: 2, nombre: 'Sub 15 B'),
          ],
          selectedTeamId: 1,
        ),
      );

      expect(find.byKey(const Key('cambios_team_picker')), findsOneWidget);
    });

    testWidgets('stale=true shows the syncing banner above the content', (tester) async {
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            cambiosContextControllerProvider.overrideWith(
              (ref) => _StubContextController(const CambiosContextLoading()),
            ),
          ],
          child: const MaterialApp(
            home: Scaffold(body: CambiosContextScreen(stale: true)),
          ),
        ),
      );
      await tester.pump();

      expect(find.text('Sincronizando tus datos…'), findsOneWidget);
    });
  });
}

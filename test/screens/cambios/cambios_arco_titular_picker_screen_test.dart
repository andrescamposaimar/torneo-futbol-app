import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/models/cambios_candidato.dart';
import 'package:torneo_futbol_app/models/cambios_plaza.dart';
import 'package:torneo_futbol_app/models/cambios_solicitud.dart';
import 'package:torneo_futbol_app/models/jugador.dart';
import 'package:torneo_futbol_app/providers/cambios_providers.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_arco_titular_picker_screen.dart';
import 'package:torneo_futbol_app/screens/cambios/cambios_solicitar_screen.dart';
import 'package:torneo_futbol_app/services/cambios_api_service.dart';
import 'package:torneo_futbol_app/services/cambios_candidatos_controller.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';

CambiosPlaza _plaza({
  required int plazaId,
  required int titularPlayerId,
  String titularNombre = 'Titular',
  bool esTitularElOcupante = true,
  bool cerrada = false,
  double puntajeTecho = 3.0,
}) {
  return CambiosPlaza(
    plazaId: plazaId,
    titularPlayerId: titularPlayerId,
    titularNombre: titularNombre,
    ocupantePlayerId: esTitularElOcupante ? titularPlayerId : 999,
    ocupanteNombre: esTitularElOcupante ? titularNombre : 'Otro Jugador',
    esTitularElOcupante: esTitularElOcupante,
    cerrada: cerrada,
    puntajeTecho: puntajeTecho,
  );
}

Jugador _jugador({required int id, required String posicion, double puntaje = 4.5}) => Jugador(
      id: id,
      nombre: 'Jugador $id',
      posicion: posicion,
      puntaje: puntaje,
      equipo: 'Equipo A',
      escudo: '',
      temporadas: const [],
      raw: const {},
    );

// EntreRedesAppBar (this screen's own AppBar) is itself a ConsumerWidget
// (watches appConfigProvider for its optional logo) — every pumped tree
// needs a ProviderScope ancestor for that reason alone, even the tests that
// never navigate to step 2.
Widget _wrap(Widget child) => ProviderScope(child: MaterialApp(home: child));

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

/// Only needed so [CambiosSolicitarScreen] (step 2, pushed by the picker on
/// tap) can BUILD without reaching a real backend — this test never
/// interacts with its candidate list.
class _StubCandidatosController extends CambiosCandidatosController {
  _StubCandidatosController()
      : super(
          _fakeService(),
          seasonId: 7,
          teamId: 1,
          plazaId: 2,
          seccion: CambiosCandidatosSeccion.listaEspera,
          autoLoad: false,
        ) {
    state = const CambiosCandidatosLoaded(candidatos: [], query: '');
  }

  @override
  Future<void> load({String query = '', List<double> puntajes = const []}) async {}
}

/// Wraps [child] in the [ProviderScope] step 2 ([CambiosSolicitarScreen])
/// needs in order to build at all once the picker pushes it — only the
/// "navegación a step 2" and "narrow width" groups push that far.
Widget _wrapWithProviders(Widget child) {
  return ProviderScope(
    overrides: [
      cambiosFechaAbiertaProvider(7).overrideWith((ref) => Future.value(null)),
      for (final seccion in CambiosCandidatosSeccion.values)
        cambiosCandidatosControllerProvider((
          seasonId: 7,
          teamId: 1,
          plazaId: 2,
          seccion: seccion,
        )).overrideWith((ref) => _StubCandidatosController()),
    ],
    child: MaterialApp(home: child),
  );
}

void main() {
  final plazaArco = _plaza(plazaId: 1, titularPlayerId: 100, titularNombre: 'El Arquero');
  final plazaCampoA = _plaza(plazaId: 2, titularPlayerId: 101, titularNombre: 'Defensor Uno');
  final plazaCampoB = _plaza(plazaId: 3, titularPlayerId: 102, titularNombre: 'Defensor Dos');

  Map<int, Jugador> jugadoresById({String arcoPosicion = 'Arquero'}) => {
        100: _jugador(id: 100, posicion: arcoPosicion),
        101: _jugador(id: 101, posicion: 'Defensor'),
        102: _jugador(id: 102, posicion: 'Mediocampista'),
      };

  group('CambiosArcoTitularPickerScreen — eligibilidad', () {
    testWidgets('lists the field titulares and excludes the goalkeeper himself', (tester) async {
      await tester.pumpWidget(_wrap(CambiosArcoTitularPickerScreen(
        seasonId: 7,
        teamId: 1,
        plazaArco: plazaArco,
        plazas: [plazaArco, plazaCampoA, plazaCampoB],
        jugadoresById: jugadoresById(),
      )));

      expect(find.byKey(const Key('arco_titular_picker_list')), findsOneWidget);
      expect(find.byKey(const Key('arco_titular_card_2')), findsOneWidget);
      expect(find.byKey(const Key('arco_titular_card_3')), findsOneWidget);
      // The goalkeeper's own plaza (posicion 'Arquero') never appears here.
      expect(find.byKey(const Key('arco_titular_card_1')), findsNothing);
    });

    testWidgets('excludes a titular who is not currently his own plaza\'s occupant', (tester) async {
      final plazaCampoAusente = _plaza(
        plazaId: 4,
        titularPlayerId: 103,
        titularNombre: 'De Baja',
        esTitularElOcupante: false,
      );

      await tester.pumpWidget(_wrap(CambiosArcoTitularPickerScreen(
        seasonId: 7,
        teamId: 1,
        plazaArco: plazaArco,
        plazas: [plazaArco, plazaCampoA, plazaCampoAusente],
        jugadoresById: {
          ...jugadoresById(),
          103: _jugador(id: 103, posicion: 'Delantero'),
        },
      )));

      expect(find.byKey(const Key('arco_titular_card_2')), findsOneWidget);
      expect(find.byKey(const Key('arco_titular_card_4')), findsNothing);
    });

    testWidgets('excludes a cerrada field plaza', (tester) async {
      final plazaCampoCerrada = _plaza(
        plazaId: 5,
        titularPlayerId: 104,
        titularNombre: 'Cerrado',
        cerrada: true,
      );

      await tester.pumpWidget(_wrap(CambiosArcoTitularPickerScreen(
        seasonId: 7,
        teamId: 1,
        plazaArco: plazaArco,
        plazas: [plazaArco, plazaCampoA, plazaCampoCerrada],
        jugadoresById: {
          ...jugadoresById(),
          104: _jugador(id: 104, posicion: 'Delantero'),
        },
      )));

      expect(find.byKey(const Key('arco_titular_card_2')), findsOneWidget);
      expect(find.byKey(const Key('arco_titular_card_5')), findsNothing);
    });

    testWidgets('empty state when no field titular is eligible', (tester) async {
      await tester.pumpWidget(_wrap(CambiosArcoTitularPickerScreen(
        seasonId: 7,
        teamId: 1,
        plazaArco: plazaArco,
        plazas: [plazaArco],
        jugadoresById: jugadoresById(),
      )));

      expect(find.byKey(const Key('arco_titular_picker_list')), findsNothing);
      expect(find.text('No hay titulares disponibles'), findsOneWidget);
    });
  });

  group('CambiosArcoTitularPickerScreen — navegación a step 2', () {
    testWidgets(
        'tapping an eligible titular pushes CambiosSolicitarScreen with HIS OWN field plaza '
        '(not the goal plaza), tipo=sustitucion, his own puntaje, and the grouped context',
        (tester) async {
      await tester.pumpWidget(_wrapWithProviders(CambiosArcoTitularPickerScreen(
        seasonId: 7,
        teamId: 1,
        plazaArco: plazaArco,
        plazas: [plazaArco, plazaCampoA],
        jugadoresById: jugadoresById(),
      )));

      await tester.tap(find.byKey(const Key('arco_titular_card_2')));
      await tester.pumpAndSettle();

      final screen =
          tester.widget<CambiosSolicitarScreen>(find.byType(CambiosSolicitarScreen));
      expect(screen.plaza.plazaId, 2, reason: 'must use the TITULAR\'s own field plaza');
      expect(screen.tipo, CambiosSolicitudTipo.sustitucion);
      expect(screen.puntaje, 4.5);
      expect(screen.grupoArco, isNotNull);
      expect(screen.grupoArco!.plazaArco.plazaId, 1);
    });
  });

  group('CambiosArcoTitularPickerScreen — narrow width', () {
    testWidgets('320px, long names: no RenderFlex overflow', (tester) async {
      tester.view.physicalSize = const Size(320, 640);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      final plazaLarga = _plaza(
        plazaId: 2,
        titularPlayerId: 101,
        titularNombre: 'Von Hohenzollern-Sigmaringen, Maximiliano Alejandro',
      );

      await tester.pumpWidget(_wrap(CambiosArcoTitularPickerScreen(
        seasonId: 7,
        teamId: 1,
        plazaArco: plazaArco,
        plazas: [plazaArco, plazaLarga],
        jugadoresById: jugadoresById(),
      )));

      expect(tester.takeException(), isNull);
      expect(find.byKey(const Key('arco_titular_card_2')), findsOneWidget);
    });
  });
}

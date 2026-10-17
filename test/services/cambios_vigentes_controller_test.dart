import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/cambios_vigente.dart';
import 'package:torneo_futbol_app/services/cambios_api_service.dart';
import 'package:torneo_futbol_app/services/cambios_vigentes_controller.dart';

class _ScriptedService implements CambiosApiService {
  final List<Object> respuestas;
  _ScriptedService(this.respuestas);

  @override
  Future<CambiosVigentes> fetchVigentes() async {
    final siguiente = respuestas.removeAt(0);
    if (siguiente is CambiosVigentes) return siguiente;
    throw siguiente;
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

CambiosVigente _v(String team) => CambiosVigente(
      teamId: 1,
      teamNombre: team,
      plazaId: 1,
      esArco: false,
      baja: const CambiosVigenteJugador(playerId: 1, nombre: 'A, B', puntaje: 4, sinCalificar: false),
      alta: const CambiosVigenteJugador(playerId: 2, nombre: 'C, D', puntaje: 4, sinCalificar: false),
      desdeFechaId: 3,
      desdeLabel: 'Apertura Fecha 7',
      situacion: 'Faltan 2 fechas para poder volver.',
      situacionTipo: 'faltan_fechas',
      fechasRestantes: 2,
    );

void main() {
  test('a failed refresh keeps the list on screen and reports false', () async {
    final controller = CambiosVigentesController(_ScriptedService([
      CambiosVigentes(seasonId: 359, vigentes: [_v('SUIZA')]),
      Exception('sin red'),
    ]));

    await controller.load();
    final actualizado = await controller.refresh();

    expect(actualizado, isFalse);
    final state = controller.state;
    expect(state, isA<CambiosVigentesLoaded>());
    expect((state as CambiosVigentesLoaded).vigentes.single.teamNombre, 'SUIZA');
  });

  test('a successful refresh replaces the list and reports true', () async {
    final controller = CambiosVigentesController(_ScriptedService([
      CambiosVigentes(seasonId: 359, vigentes: [_v('SUIZA')]),
      CambiosVigentes(seasonId: 359, vigentes: [_v('ALBANIA')]),
    ]));

    await controller.load();
    expect(await controller.refresh(), isTrue);
    expect((controller.state as CambiosVigentesLoaded).vigentes.single.teamNombre, 'ALBANIA');
  });

  test('a failed first load is an error state', () async {
    final controller = CambiosVigentesController(_ScriptedService([Exception('sin red')]));

    await controller.load();

    expect(controller.state, isA<CambiosVigentesError>());
  });
}

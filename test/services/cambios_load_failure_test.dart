import 'dart:async';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/models/cambios_plaza.dart';
import 'package:torneo_futbol_app/models/cambios_solicitud.dart';
import 'package:torneo_futbol_app/services/cambios_api_service.dart';
import 'package:torneo_futbol_app/models/cambios_mis_equipos.dart';
import 'package:torneo_futbol_app/services/cambios_context_controller.dart';
import 'package:torneo_futbol_app/services/cambios_load_failure.dart';
import 'package:torneo_futbol_app/services/cambios_plantel_controller.dart';
import 'package:torneo_futbol_app/services/cambios_solicitudes_controller.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';

/// A failed load must say WHY: a server rejection, a malformed payload and a
/// transport failure used to collapse into one indistinguishable error state.
class _FailingService extends CambiosApiService {
  final Object error;

  _FailingService(this.error)
      : super(
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

  @override
  Future<List<CambiosSolicitud>> fetchSolicitudes({
    required int seasonId,
    required int teamId,
  }) async =>
      throw error;

  @override
  Future<List<CambiosPlaza>> fetchPlazas({
    required int seasonId,
    required int teamId,
  }) async =>
      throw error;

  @override
  Future<CambiosMisEquipos> fetchMisEquipos() async => throw error;
}

Future<CambiosContextState> _contextFailure(Object error) async {
  final controller = CambiosContextController(_FailingService(error));
  await controller.load();
  return controller.state;
}

Future<CambiosSolicitudesError> _solicitudesFailure(Object error) async {
  final controller = CambiosSolicitudesController(_FailingService(error));
  await controller.load(seasonId: 7, teamId: 1);
  return controller.state as CambiosSolicitudesError;
}

Future<CambiosPlantelError> _plantelFailure(Object error) async {
  final controller = CambiosPlantelController(_FailingService(error));
  await controller.load(seasonId: 7, teamId: 1);
  return controller.state as CambiosPlantelError;
}

void main() {
  group('CambiosSolicitudesController — load failure', () {
    test('a server error carries its status code', () async {
      final s500 = await _solicitudesFailure(
          const CambiosApiException(statusCode: 500, code: 'error_interno'));
      final s403 = await _solicitudesFailure(
          const CambiosApiException(statusCode: 403, code: 'no_capitan'));

      expect(s500.code, 'solicitudes_500');
      expect(s403.code, 'solicitudes_403');
      expect(s500.message, contains('500'));
    });

    test('a malformed payload is told apart from a server error', () async {
      final failure = await _solicitudesFailure(const CambiosMalformedResponseException());

      expect(failure.code, 'solicitudes_malformed');
    });

    test('a transport failure (timeout, no connection) is told apart from both', () async {
      final timeout = await _solicitudesFailure(TimeoutException('slow'));
      final offline = await _solicitudesFailure(const SocketException('no route'));

      expect(timeout.code, 'solicitudes_error');
      expect(timeout.message, contains('TimeoutException'));
      expect(offline.code, 'solicitudes_error');
      expect(offline.message, contains('SocketException'));
    });

    test('the three causes yield three different states', () async {
      final states = {
        await _solicitudesFailure(const CambiosApiException(statusCode: 500)),
        await _solicitudesFailure(const CambiosMalformedResponseException()),
        await _solicitudesFailure(TimeoutException('slow')),
      };

      expect(states, hasLength(3));
    });
  });

  group('cambios_deshabilitado — one cause must not look like several problems', () {
    test('the code is NOT scoped, so every screen reports the same thing', () {
      const error = CambiosApiException(
        statusCode: 503,
        code: kCodigoDeshabilitado,
        mensajeDeshabilitado: 'Hablá con la comisión.',
      );

      // Scoping it would turn one cause into `plantel_503` and
      // `solicitudes_503` — two different-looking problems.
      expect(describeCambiosLoadFailure('plantel', error).code, kCodigoDeshabilitado);
      expect(describeCambiosLoadFailure('solicitudes', error).code, kCodigoDeshabilitado);
      expect(describeCambiosLoadFailure('candidatos', error).code, kCodigoDeshabilitado);
    });

    test("carries the comisión's words through as the message", () {
      const error = CambiosApiException(
        statusCode: 503,
        code: kCodigoDeshabilitado,
        mensajeDeshabilitado: 'Hablá con la comisión.',
      );

      expect(describeCambiosLoadFailure('plantel', error).message, 'Hablá con la comisión.');
    });

    test('an ordinary 503 is still scoped — only the known code is special', () {
      const error = CambiosApiException(statusCode: 503, code: 'rest_no_route');

      expect(describeCambiosLoadFailure('plantel', error).code, 'plantel_503');
    });
  });

  group('CambiosContextController — the kill switch is resolved at the one door', () {
    test('a cambios_deshabilitado 503 becomes its own state, never an error', () async {
      final estado = await _contextFailure(const CambiosApiException(
        statusCode: 503,
        code: kCodigoDeshabilitado,
        mensajeDeshabilitado: 'Hablá con la comisión.',
      ));

      expect(estado, isA<CambiosContextDeshabilitado>());
      expect((estado as CambiosContextDeshabilitado).mensaje, 'Hablá con la comisión.');
    });

    test('every OTHER failure is still a plain error', () async {
      expect(await _contextFailure(const CambiosApiException(statusCode: 500)),
          isA<CambiosContextError>());
      expect(await _contextFailure(const CambiosApiException(statusCode: 503, code: 'rest_no_route')),
          isA<CambiosContextError>());
      expect(await _contextFailure(TimeoutException('slow')), isA<CambiosContextError>());
    });
  });

  group('CambiosPlantelController — load failure (identical blanket catch, same fix)', () {
    test('a server error, a malformed payload and a transport failure are distinguishable',
        () async {
      expect((await _plantelFailure(const CambiosApiException(statusCode: 502))).code,
          'plantel_502');
      expect((await _plantelFailure(const CambiosMalformedResponseException())).code,
          'plantel_malformed');
      expect((await _plantelFailure(TimeoutException('slow'))).code, 'plantel_error');
    });
  });
}

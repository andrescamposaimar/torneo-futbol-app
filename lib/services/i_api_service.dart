abstract class IApiService {
  Future<Map<String, dynamic>> getPartidos({
    String? fecha,
    int? liga,
    int? temporada,
    String? equipo,
    int? page,
    int? perPage,
  });

  Future<Map<String, dynamic>> getPartidosProgramados({
    int? page,
    int? perPage,
  });

  Future<List<dynamic>> getLigas({int? temporada});

  Future<List<dynamic>> getTemporadas();

  Future<List<dynamic>> getZonas({int? liga});

  Future<List<dynamic>> getEquipos({int? liga, int? temporada});

  Future<Map<String, dynamic>> getTablas({
    String? temporada,
    String? zona,
    String? search,
    int? page,
    int? perPage,
  });

  Future<Map<String, dynamic>> getJugadoresRaw({
    int? temporada,
    int? liga,
    int? zona,
    int? equipoId,
    String? search,
    int? page,
    int? perPage,
  });

  Future<List<dynamic>> getJugadores({
    int page = 1,
    int perPage = 20,
    int? equipoId,
  });

  Future<Map<String, dynamic>> getPartidosPorJugador(
    int jugadorId, {
    int? page,
    int? perPage,
  });

  Future<Map<String, dynamic>> getGoleadoresDelPartido(int partidoId);

  Future<Map<String, dynamic>> getTablaGoleadores({
    int? temporada,
    int? liga,
    int page = 1,
    int perPage = 50,
  });

  Future<List<dynamic>> getHistorialDePartidosPorEquipo(String equipo);

  Future<Map<String, dynamic>> getJugadorPorId(int id);

  Future<List<dynamic>> getJugadoresTemporadaActual(
    int temporadaId, {
    int page = 1,
    int perPage = 20,
  });

  Future<List<dynamic>> getJugadoresPorEquipoId(int equipoId);

  Future<List<dynamic>> getPartidosPorEquipoId(int equipoId);

  Future<Map<String, dynamic>> getTablaImbatibles({
    required int temporada,
    int page = 1,
    int perPage = 10,
  });

  Future<Map<String, dynamic>> getNoticias({
    int page = 1,
    int perPage = 10,
  });
}

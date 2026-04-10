import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'i_api_service.dart';

class ApiService implements IApiService {
  static const String baseUrl = 'https://entreredespadres.com.ar/wp-json/entre-redes/v1';

  void _logRequest(Uri uri, http.Response res) {
    if (!kDebugMode) return;
    debugPrint('📤 [API REQUEST] ${uri.toString()}');
    debugPrint('📥 [STATUS] ${res.statusCode}');
    if (res.statusCode != 200) {
      debugPrint('❌ [ERROR RESPONSE] ${res.body}');
    } else {
      final preview = res.body.length > 500 ? '${res.body.substring(0, 500)}... (truncated)' : res.body;
      debugPrint('✅ [SUCCESS RESPONSE] $preview');
    }
  }

  Future<List<dynamic>> _fetchAllPages(String endpoint, {Map<String, String>? queryParams}) async {
    int page = 1;
    final results = <dynamic>[];

    while (true) {
      final uri = Uri.parse(endpoint).replace(queryParameters: {
        if (queryParams != null) ...queryParams,
        'per_page': '100',
        'page': page.toString(),
      });

      final res = await http.get(uri);
      _logRequest(uri, res);

      if (res.statusCode != 200) {
        throw Exception('Error en $endpoint');
      }

      final data = json.decode(res.body);
      if (data is List && data.isNotEmpty) {
        results.addAll(data);
        if (data.length < 100) break;
      } else {
        break;
      }

      page++;
    }

    return results;
  }

  @override
  Future<Map<String, dynamic>> getPartidos({
    String? fecha,
    int? liga,
    int? temporada,
    String? equipo,
    int? page,
    int? perPage,
  }) async {
    final queryParams = <String, String>{
      'page': (page ?? 1).toString(),
      'per_page': (perPage ?? 16).toString(),
    };
    if (fecha != null) queryParams['fecha'] = fecha;
    if (liga != null) queryParams['liga'] = liga.toString();
    if (temporada != null) queryParams['temporada'] = temporada.toString();
    if (equipo != null) queryParams['equipo'] = equipo;

    final uri = Uri.parse('$baseUrl/partidos').replace(queryParameters: queryParams);
    final res = await http.get(uri);
    _logRequest(uri, res);

    if (res.statusCode == 200) {
      return json.decode(res.body);
    } else {
      throw Exception('Error al obtener partidos');
    }
  }

  @override
  Future<Map<String, dynamic>> getPartidosProgramados({
    int? page,
    int? perPage,
  }) async {
    final queryParams = {
      'page': (page ?? 1).toString(),
      'per_page': (perPage ?? 16).toString(),
    };

    final uri = Uri.parse('$baseUrl/partidos-programados').replace(queryParameters: queryParams);
    final res = await http.get(uri);
    _logRequest(uri, res);

    if (res.statusCode == 200) {
      return json.decode(res.body);
    } else {
      throw Exception('Error al obtener partidos programados');
    }
  }

  @override
  Future<List<dynamic>> getLigas({int? temporada}) async {
    final queryParams = <String, String>{};
    if (temporada != null) queryParams['temporada'] = temporada.toString();
    return _fetchAllPages('$baseUrl/ligas', queryParams: queryParams);
  }

  @override
  Future<List<dynamic>> getTemporadas() async {
    return _fetchAllPages('$baseUrl/temporadas');
  }

  @override
  Future<List<dynamic>> getZonas({int? liga}) async {
    final queryParams = <String, String>{};
    if (liga != null) queryParams['liga'] = liga.toString();

    final uri = Uri.parse('$baseUrl/zonas').replace(queryParameters: queryParams);
    final res = await http.get(uri);
    _logRequest(uri, res);

    if (res.statusCode == 200) {
      return json.decode(res.body);
    } else {
      throw Exception('Error al obtener zonas');
    }
  }

  @override
  Future<List<dynamic>> getEquipos({int? liga, int? temporada}) async {
    final queryParams = <String, String>{};
    if (liga != null) queryParams['liga'] = liga.toString();
    if (temporada != null) queryParams['temporada'] = temporada.toString();

    final results = <dynamic>[];
    int currentPage = 1;
    const int perPage = 32;

    while (true) {
      final uri = Uri.parse('$baseUrl/equipos').replace(queryParameters: {
        ...queryParams,
        'page': currentPage.toString(),
        'per_page': perPage.toString(),
      });

      final res = await http.get(uri);
      _logRequest(uri, res);

      if (res.statusCode != 200) {
        throw Exception('Error al obtener equipos');
      }

      final data = json.decode(res.body);
      if (data is Map<String, dynamic> && data.containsKey('items')) {
        results.addAll(data['items']);
        final totalPages = data['total_pages'] ?? 1;
        if (currentPage >= totalPages) break;
      } else {
        break;
      }

      currentPage++;
    }

    return results;
  }

  @override
  Future<Map<String, dynamic>> getTablas({
    String? temporada,
    String? zona,
    String? search,
    int? page,
    int? perPage,
  }) async {
    final queryParams = <String, String>{
      'page': (page ?? 1).toString(),
      'per_page': (perPage ?? 15).toString(),
    };
    if (temporada != null) queryParams['temporada'] = temporada;
    if (zona != null) queryParams['zona'] = zona;
    if (search != null && search.isNotEmpty) queryParams['search'] = search;

    final uri = Uri.parse('$baseUrl/tablas').replace(queryParameters: queryParams);
    final res = await http.get(uri);
    _logRequest(uri, res);

    if (res.statusCode == 200) {
      return json.decode(res.body);
    } else {
      throw Exception('Error al obtener posiciones');
    }
  }

  @override
  Future<Map<String, dynamic>> getJugadoresRaw({
    int? temporada,
    int? liga,
    int? zona,
    int? equipoId,
    String? search,
    int? page,
    int? perPage,
  }) async {
    final queryParams = <String, String>{
      'page': (page ?? 1).toString(),
      'per_page': (perPage ?? 20).toString(),
    };
    if (temporada != null) queryParams['temporada'] = temporada.toString();
    if (liga != null) queryParams['liga'] = liga.toString();
    if (zona != null) queryParams['zona'] = zona.toString();
    if (equipoId != null) queryParams['equipo_id'] = equipoId.toString();
    if (search != null && search.isNotEmpty) queryParams['search'] = search;

    final uri = Uri.parse('$baseUrl/jugadores').replace(queryParameters: queryParams);
    final res = await http.get(uri);
    _logRequest(uri, res);

    if (res.statusCode == 200) {
      final data = json.decode(res.body);
      final totalHeader = res.headers['x-wp-total'];
      final total = totalHeader != null ? int.tryParse(totalHeader) : null;

      if (data is List) {
        return {
          'items': data,
          'total': total,
        };
      }
      return {
        'items': [],
        'total': total,
      };
    } else {
      throw Exception('Error al obtener jugadores');
    }
  }

  @override
  Future<List<dynamic>> getJugadores({
    int page = 1,
    int perPage = 20,
    int? equipoId,
  }) async {
    final res = await getJugadoresRaw(
      page: page,
      perPage: perPage,
      equipoId: equipoId,
    );
    return res['items'] ?? [];
  }

  @override
  Future<Map<String, dynamic>> getPartidosPorJugador(int jugadorId, {int? page, int? perPage}) async {
    final uri = Uri.parse('$baseUrl/partidos-jugador').replace(queryParameters: {
      'jugador': jugadorId.toString(),
      'page': (page ?? 1).toString(),
      'per_page': (perPage ?? 16).toString(),
    });

    final res = await http.get(uri);
    _logRequest(uri, res);

    if (res.statusCode == 200) {
      final data = json.decode(res.body);
      if (data is Map<String, dynamic> && data.containsKey('items')) {
        return {
          'items': data['items'],
          'current_page': data['current_page'],
          'total_pages': data['total_pages'],
        };
      }
      throw Exception('Formato inesperado en partidos del jugador');
    } else {
      throw Exception('Error al obtener partidos del jugador');
    }
  }

  @override
  Future<Map<String, dynamic>> getGoleadoresDelPartido(int partidoId) async {
    final uri = Uri.parse('$baseUrl/goleadores').replace(queryParameters: {
      'partido_id': partidoId.toString(),
    });

    final res = await http.get(uri);
    _logRequest(uri, res);

    if (res.statusCode == 200) {
      final data = json.decode(res.body);
      if (data is Map<String, dynamic> &&
          data.containsKey('equipo_local') &&
          data.containsKey('equipo_visitante')) {
        return data;
      } else {
        throw Exception('Formato inesperado al obtener goleadores');
      }
    } else {
      throw Exception('Error al obtener goleadores del partido');
    }
  }

  @override
  Future<Map<String, dynamic>> getTablaGoleadores({
    int? temporada,
    int? liga,
    int page = 1,
    int perPage = 50,
  }) async {
    final queryParams = <String, String>{
      'page': page.toString(),
      'per_page': perPage.toString(),
    };
    if (temporada != null) queryParams['id_temporada'] = temporada.toString();
    if (liga != null) queryParams['id_liga'] = liga.toString();

    final uri = Uri.parse('$baseUrl/tabla-goleadores').replace(queryParameters: queryParams);
    final res = await http.get(uri);
    _logRequest(uri, res);

    if (res.statusCode == 200) {
      final data = json.decode(res.body);
      if (data is Map<String, dynamic> && data.containsKey('items')) {
        return {
          'items': List<dynamic>.from(data['items']),
          'total': data['total'],
          'total_pages': data['total_pages'],
          'current_page': data['current_page'],
          'per_page': data['per_page'],
        };
      } else {
        throw Exception('Formato inesperado en tabla de goleadores');
      }
    } else {
      throw Exception('Error al obtener tabla de goleadores');
    }
  }

  @override
  Future<List<dynamic>> getHistorialDePartidosPorEquipo(String equipo) async {
    final uri = Uri.parse('$baseUrl/partidos-equipo').replace(queryParameters: {
      'equipo': equipo,
    });

    final res = await http.get(uri);
    _logRequest(uri, res);

    if (res.statusCode == 200) {
      final data = json.decode(res.body);
      if (data is List) {
        return data;
      }
    }
    throw Exception('Error al obtener historial de partidos del equipo');
  }

  @override
  Future<Map<String, dynamic>> getJugadorPorId(int id) async {
    final uri = Uri.parse('$baseUrl/jugadores/$id');
    final res = await http.get(uri);
    _logRequest(uri, res);

    if (res.statusCode == 200) {
      return json.decode(res.body);
    } else {
      throw Exception('Error al obtener jugador $id');
    }
  }

  @override
  Future<List<dynamic>> getJugadoresTemporadaActual(int temporadaId, {int page = 1, int perPage = 20}) async {
    final res = await getJugadoresRaw(temporada: temporadaId, page: page, perPage: perPage);
    return res['items'] ?? [];
  }

  @override
  Future<List<dynamic>> getJugadoresPorEquipoId(int equipoId) async {
    final res = await getJugadoresRaw(equipoId: equipoId);
    return res['items'] ?? [];
  }

  @override
  Future<List<dynamic>> getPartidosPorEquipoId(int equipoId) async {
    final uri = Uri.parse('$baseUrl/partidos-equipo').replace(queryParameters: {
      'equipo_id': equipoId.toString(),
    });

    final res = await http.get(uri);
    _logRequest(uri, res);

    if (res.statusCode == 200) {
      final data = json.decode(res.body);
      if (data is List) {
        return data;
      }
    }

    throw Exception('Error al obtener historial de partidos del equipo');
  }

  @override
  Future<Map<String, dynamic>> getTablaImbatibles({
    required int temporada,
    int page = 1,
    int perPage = 10,
  }) async {
    final queryParams = <String, String>{
      'temporada': temporada.toString(),
      'page': page.toString(),
      'per_page': perPage.toString(),
    };

    final uri = Uri.parse('$baseUrl/tabla-imbatibles').replace(queryParameters: queryParams);
    final res = await http.get(uri);
    _logRequest(uri, res);

    if (res.statusCode == 200) {
      final data = json.decode(res.body);
      if (data is Map<String, dynamic> && data.containsKey('items')) {
        return {
          'items': List<dynamic>.from(data['items']),
          'total': data['total'],
          'total_pages': data['total_pages'],
          'current_page': data['current_page'],
          'per_page': data['per_page'],
        };
      } else {
        throw Exception('Formato inesperado en tabla de imbatibles');
      }
    } else {
      throw Exception('Error al obtener tabla de imbatibles');
    }
  }
}

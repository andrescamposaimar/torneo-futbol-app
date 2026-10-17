/// One player on either side of a live substitution (`baja` / `alta`) in
/// `GET /cambios/vigentes`.
class CambiosVigenteJugador {
  final int playerId;

  /// The name exactly as the backend stores it ("Apellido, Nombre").
  /// Display code must go through [nombreVisible].
  final String nombre;

  /// Null when the player has no rating yet.
  final double? puntaje;
  final bool sinCalificar;

  const CambiosVigenteJugador({
    required this.playerId,
    required this.nombre,
    required this.puntaje,
    required this.sinCalificar,
  });

  factory CambiosVigenteJugador.fromJson(Map<String, dynamic> json) {
    final nombre = json['nombre'];
    if (nombre is! String) {
      throw const FormatException('vigente jugador: nombre missing');
    }
    return CambiosVigenteJugador(
      playerId: _asInt(json['player_id']),
      nombre: nombre,
      puntaje: _asDouble(json['puntaje']),
      sinCalificar: json['sin_calificar'] == true,
    );
  }

  /// "Nombre Apellido" built from the stored "Apellido, Nombre".
  String get nombreVisible => flipNombre(nombre);

  /// "Nombre Apellido (3,5)" or "Nombre Apellido (sin calificar)".
  String get etiqueta => '$nombreVisible (${formatPuntajeVigente(this)})';
}

/// Flips "Apellido, Nombre" into "Nombre Apellido", splitting on the FIRST
/// comma only. A name without a comma is returned trimmed and unchanged.
String flipNombre(String raw) {
  final i = raw.indexOf(',');
  if (i < 0) return raw.trim();
  final apellido = raw.substring(0, i).trim();
  final nombre = raw.substring(i + 1).trim();
  if (apellido.isEmpty) return nombre;
  if (nombre.isEmpty) return apellido;
  return '$nombre $apellido';
}

/// The ONE place the puntaje of a "Cambios activos" row is turned into text.
/// If product decides to hide puntajes, change (or blank) this and the card
/// label follows.
///
/// "3,5" / "4" (decimal comma, no trailing zero) or "sin calificar" when the
/// backend flags it or sends no value.
String formatPuntajeVigente(CambiosVigenteJugador jugador) {
  final p = jugador.puntaje;
  if (jugador.sinCalificar || p == null) return 'sin calificar';
  var text = p.toString();
  if (text.contains('.')) {
    text = text.replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), '');
  }
  return text.replaceAll('.', ',');
}

/// One live substitution row of `GET /cambios/vigentes`.
class CambiosVigente {
  final int teamId;
  final String teamNombre;
  final int plazaId;
  final bool esArco;
  final CambiosVigenteJugador baja;
  final CambiosVigenteJugador alta;
  final int desdeFechaId;
  final String desdeLabel;
  final String situacion;
  final String situacionTipo;
  final int? fechasRestantes;

  const CambiosVigente({
    required this.teamId,
    required this.teamNombre,
    required this.plazaId,
    required this.esArco,
    required this.baja,
    required this.alta,
    required this.desdeFechaId,
    required this.desdeLabel,
    required this.situacion,
    required this.situacionTipo,
    required this.fechasRestantes,
  });

  factory CambiosVigente.fromJson(Map<String, dynamic> json) {
    final baja = json['baja'];
    final alta = json['alta'];
    final desde = json['desde'];
    final teamNombre = json['team_nombre'];
    final situacion = json['situacion'];
    if (baja is! Map || alta is! Map || desde is! Map) {
      throw const FormatException('vigente: baja/alta/desde missing');
    }
    if (teamNombre is! String || situacion is! String) {
      throw const FormatException('vigente: team_nombre/situacion missing');
    }
    final label = desde['label'];
    return CambiosVigente(
      teamId: _asInt(json['team_id']),
      teamNombre: teamNombre,
      plazaId: _asInt(json['plaza_id']),
      esArco: json['es_arco'] == true,
      baja: CambiosVigenteJugador.fromJson(baja.cast<String, dynamic>()),
      alta: CambiosVigenteJugador.fromJson(alta.cast<String, dynamic>()),
      desdeFechaId: _asInt(desde['fecha_id']),
      desdeLabel: label is String ? label : '',
      situacion: situacion,
      situacionTipo: json['situacion_tipo'] is String ? json['situacion_tipo'] as String : '',
      fechasRestantes: json['fechas_restantes'] == null ? null : _asInt(json['fechas_restantes']),
    );
  }
}

/// The whole `GET /cambios/vigentes` envelope.
class CambiosVigentes {
  final int seasonId;
  final List<CambiosVigente> vigentes;

  const CambiosVigentes({required this.seasonId, required this.vigentes});

  factory CambiosVigentes.fromJson(Map<String, dynamic> json) {
    final raw = json['vigentes'];
    if (raw is! List) {
      throw const FormatException('vigentes: list missing');
    }
    return CambiosVigentes(
      seasonId: _asInt(json['season_id']),
      vigentes: raw.map(_parseRow).whereType<CambiosVigente>().toList(),
    );
  }

  /// This is a read-only list: one corrupt row is skipped instead of turning
  /// the whole screen into an error and hiding every other team's changes.
  static CambiosVigente? _parseRow(Object? row) {
    if (row is! Map) return null;
    try {
      return CambiosVigente.fromJson(row.cast<String, dynamic>());
    } on FormatException {
      return null;
    } on TypeError {
      return null;
    }
  }
}

int _asInt(Object? v) {
  if (v is int) return v;
  if (v is num) return v.toInt();
  if (v is String) return int.parse(v);
  throw FormatException('not an int: $v');
}

double? _asDouble(Object? v) {
  if (v == null) return null;
  if (v is num) return v.toDouble();
  if (v is String) return double.tryParse(v.replaceAll(',', '.'));
  return null;
}

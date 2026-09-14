/// Coerces [value] to a [String], defaulting to `''` for anything other
/// than an actual `String` — a wrong-typed field (e.g. a number where a
/// name was expected) must default, never throw, same contract as
/// `JugadorTitulo._parseInt` for numeric fields.
String _parseString(dynamic value) => value is String ? value : '';

/// One Copa Chaminade title won by the viewed player, as fed by
/// `GET /entre-redes/v1/campeones/jugador/{id}/titulos` (API-2).
///
/// `fromJson` follows the `Jugador.fromJson` convention: tolerant parsing,
/// defaults rather than throwing, and an `_parseInt` that accepts both `num`
/// and `String` since WordPress meta occasionally travels as a string.
class JugadorTitulo {
  final int anio;
  final String zona;
  final String equipoNombre;
  final bool esCapitan;

  const JugadorTitulo({
    required this.anio,
    required this.zona,
    required this.equipoNombre,
    required this.esCapitan,
  });

  factory JugadorTitulo.fromJson(Map<String, dynamic> json) {
    return JugadorTitulo(
      anio: _parseInt(json['anio']),
      zona: _parseString(json['zona']),
      equipoNombre: _parseString(json['equipo_nombre']),
      esCapitan: json['es_capitan'] == true,
    );
  }

  static int _parseInt(dynamic value) {
    if (value is num) return value.toInt();
    if (value is String) return int.tryParse(value) ?? 0;
    return 0;
  }
}

/// One squad member of a Copa Chaminade championship, as fed by
/// `GET /entre-redes/v1/campeones/historia` (API-1, API-3).
///
/// Verified against `TitleShaper::shapeSquadEntry()`
/// (`wordpress_plugins/entre-redes-campeones/src/Rest/TitleShaper.php`):
/// the wire payload carries `nombre`, `es_capitan`, `jugador_id` and
/// `foto_url` — it does NOT carry an `orden` field, even though
/// `SquadEntry::$orden` exists server-side. The squad array itself already
/// arrives sorted by `orden ASC` (`SquadRepository::findByTitle`), so
/// [orden] is derived from the entry's position in that array rather than
/// parsed from JSON.
///
/// [fotoUrl] is parsed now so a future slice (9c, avatars) does not need to
/// touch this model again, but nothing in slice 9a/9b renders it.
class CampeonPlantelEntry {
  final int orden;
  final String nombre;
  final int? jugadorId;
  final bool esCapitan;
  final String? fotoUrl;

  const CampeonPlantelEntry({
    required this.orden,
    required this.nombre,
    this.jugadorId,
    required this.esCapitan,
    this.fotoUrl,
  });

  /// [orden] is supplied by the caller (the entry's index within the
  /// already-sorted `plantel` array), not read from [json].
  factory CampeonPlantelEntry.fromJson(
    Map<String, dynamic> json, {
    required int orden,
  }) {
    final rawFotoUrl = json['foto_url'];
    return CampeonPlantelEntry(
      orden: orden,
      nombre: _parseString(json['nombre']),
      jugadorId: _parseJugadorId(json['jugador_id']),
      esCapitan: json['es_capitan'] == true,
      fotoUrl: (rawFotoUrl is String && rawFotoUrl.isNotEmpty)
          ? rawFotoUrl
          : null,
    );
  }

  /// `null` for an explicit JSON `null` and for any type `_parseInt` cannot
  /// genuinely parse (a bool, a list, a map, an unparseable string). Unlike
  /// `JugadorTitulo._parseInt`'s catch-all-to-`0`, a wrong-typed
  /// `jugador_id` must stay `null` rather than silently becoming the int
  /// `0` — `CampeonesScreen._plantelRow` treats `jugadorId != null` as
  /// "this row is linked" and makes it tappable, so a silent `0` would
  /// render an unlinked player as tappable and fire a real network request
  /// for player id `0` when tapped.
  static int? _parseJugadorId(dynamic value) {
    if (value == null) return null;
    if (value is num) return value.toInt();
    if (value is String) return int.tryParse(value);
    return null;
  }
}

/// One Copa Chaminade championship, as fed by
/// `GET /entre-redes/v1/campeones/historia` (API-1, API-3) — the history
/// screen's full year-by-year list, each with its squad.
///
/// Verified against `TitleShaper::shapeHistoryEntry()` and `TitleRecord`
/// (`wordpress_plugins/entre-redes-campeones/src/Titles/TitleRecord.php`):
/// `posicion` travels as a **string** on the wire (`TitleRecord::$posicion`
/// is `string`, written through unchanged by the shaper), not an int — do
/// not reuse `_parseInt` for it.
class CampeonTitulo {
  final int anio;
  final String zona;
  final String posicion;
  final String equipoNombre;
  final List<CampeonPlantelEntry> plantel;

  const CampeonTitulo({
    required this.anio,
    required this.zona,
    required this.posicion,
    required this.equipoNombre,
    required this.plantel,
  });

  factory CampeonTitulo.fromJson(Map<String, dynamic> json) {
    // A non-list `plantel` (missing, null, or wrong-typed — e.g. a string)
    // becomes an empty squad instead of throwing: `List<dynamic>.from`
    // requires an `Iterable` and throws on anything else.
    final plantelJson = json['plantel'];
    final rawPlantel = plantelJson is List ? plantelJson : const [];
    return CampeonTitulo(
      anio: JugadorTitulo._parseInt(json['anio']),
      zona: _parseString(json['zona']),
      posicion: _parsePosicion(json['posicion']),
      equipoNombre: _parseString(json['equipo_nombre']),
      plantel: [
        for (var i = 0; i < rawPlantel.length; i++)
          // A non-map entry (e.g. a bare string in the array) is skipped
          // rather than crashing the whole year's plantel — and, since
          // this factory maps the entire championship-history response,
          // rather than crashing every other year in the same response too.
          if (rawPlantel[i] is Map)
            CampeonPlantelEntry.fromJson(
              Map<String, dynamic>.from(rawPlantel[i] as Map),
              orden: i,
            ),
      ],
    );
  }

  static String _parsePosicion(dynamic value) {
    if (value is String) return value;
    if (value != null) return value.toString();
    return '';
  }
}

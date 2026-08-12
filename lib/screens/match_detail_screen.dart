import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../providers/service_providers.dart';
import '../utils/date_utils.dart';
import '../utils/text_utils.dart';
import '../widgets/zocalo_publicitario.dart';
import '../widgets/full_field_painter.dart';
import '../widgets/player_pod.dart';
import 'player_detail_screen.dart';


/// A single comparable stat: its label and both teams' values.
class _Estadistica {
  final String titulo;
  final int local;
  final int visitante;

  const _Estadistica(this.titulo, this.local, this.visitante);
}

class MatchDetailScreen extends ConsumerStatefulWidget {
  final Map<String, dynamic> partido;
  const MatchDetailScreen({super.key, required this.partido});

  @override
  ConsumerState<MatchDetailScreen> createState() => _MatchDetailScreenState();
}

class _MatchDetailScreenState extends ConsumerState<MatchDetailScreen> with SingleTickerProviderStateMixin {
  Map<String, dynamic>? goleadores;
  bool isLoading = true;
  String? error;
  late TabController _tabController;
  String equipoSeleccionado = 'local';

  String? estadisticasAdUrl;
  String? alineacionesAdUrl;

  bool get _esFuturo => widget.partido['status'] == 'future';

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);

    // Cargar publicidad
    ref.read(remoteDataServiceProvider).fetchAdImages().then((ads) {
      if (!mounted) return;
      setState(() {
       // estadisticasAdUrl = ads['estadisticas'];
       // alineacionesAdUrl = ads['alineaciones'];
      });
    });

    // Solo cargar goleadores si el partido ya se disputó
    if (!_esFuturo) {
      _loadGoleadores();
    } else {
      // Marcar como no cargando para evitar indicadores infinitos
      isLoading = false;
    }

    _tabController.addListener(() {
      setState(() {}); // 🔁 Necesario para que se redibujen las pestañas al cambiar
    });
  }

  Future<void> _loadGoleadores() async {
    try {
      final data = await ref.read(apiServiceProvider).getGoleadoresDelPartido(widget.partido['id']);
      final cachedPlayers = await ref.read(cacheServiceProvider).getCachedPlayers();

      Map<int, dynamic> cachedMap = {};
      if (cachedPlayers != null) {
        for (var player in cachedPlayers) {
          if (player['id'] != null) {
            cachedMap[player['id']] = player;
          }
        }
      }

      Future<void> enrich(List<dynamic> jugadores, String equipo) async {
        for (var j in jugadores) {
          j['goles'] = j['goles'] ?? 0;
          j['tarjeta_amarilla'] = j['tarjetaamarilla'] ?? j['tarjeta_amarilla'] ?? 0;
          j['tarjeta_roja'] = j['tarjetaroja'] ?? j['tarjeta_roja'] ?? 0;
          j['figura'] = j['figura'] == '1' || j['figura'] == 1 || j['figura'] == true;
          j['equipo'] = equipo;
          j['posicion'] = j['posicion'] ?? '-';
          j['capitan'] = j['capitan'] == true;
          j['reemplazo_alta'] = j['reemplazo_alta'] == true;
          j['reemplazo_baja'] = j['reemplazo_baja'] == true;


          final jugadorId = j['id'];
          if (jugadorId != null && cachedMap.containsKey(jugadorId)) {
            final metrics = cachedMap[jugadorId]['metrics'];
            if (metrics != null && metrics['puntaje'] != null) {
              j['puntaje'] = metrics['puntaje'];
              continue;
            }
          }

          j['puntaje'] = '-';
        }
      }

      await enrich(data['equipo_local']['goleadores'], 'local');
      await enrich(data['equipo_visitante']['goleadores'], 'visitante');

      if (mounted) {
        setState(() {
          goleadores = data;
          isLoading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          error = e.toString();
          isLoading = false;
        });
      }
    }
  }

  /// Devuelve el valor como texto listo para mostrar, o null si no hay dato.
  String? _valorOpcional(dynamic raw) {
    final texto = decodeHtmlEntities(raw?.toString());
    return texto.isEmpty ? null : texto;
  }

  /// La API expone `temporada` como id numérico en algunos endpoints, así que
  /// solo la mostramos cuando el valor es plausible como año.
  String? _temporadaLegible(dynamic raw) {
    final anio = int.tryParse(raw?.toString() ?? '');
    if (anio == null || anio < 2000 || anio > 2100) return null;
    return '$anio';
  }

  Widget _buildInformacion() {
    final p = widget.partido;

    final fechaLarga = formatFechaLarga(p['fecha']?.toString());
    final hora = _valorOpcional(p['hora']);
    final temporada = _temporadaLegible(p['temporada']);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Container(
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: Colors.grey.shade200),
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Padding(
                  padding: EdgeInsets.only(top: 8, bottom: 4),
                  child: Text(
                    'Información del partido',
                    style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                  ),
                ),
                _buildInfoRow(
                  Icons.calendar_month,
                  'Fecha',
                  fechaLarga ?? _valorOpcional(p['fecha']),
                ),
                _buildInfoRow(Icons.access_time, 'Hora', hora == null ? null : '$hora hs'),
                _buildInfoRow(Icons.emoji_events, 'Liga', _valorOpcional(p['liga'])),
                if (temporada != null)
                  _buildInfoRow(Icons.timeline, 'Temporada', temporada),
                _buildInfoRow(Icons.location_on, 'Cancha', _valorOpcional(p['cancha'])),
              ],
            ),
          ),
        ),
      ],
    );
  }

  /// The match hero: crests, score and the scorers of each side, all inside the
  /// very same block. Empty scorer lists simply drop that half of the panel.
  Widget _buildScoreboard({
    required List<String> goleadoresLocal,
    required List<String> goleadoresVisitante,
  }) {
    final p = widget.partido;
    final primary = Theme.of(context).colorScheme.primary;

    final local = decodeHtmlEntities(p['equipo_local']?.toString());
    final visitante = decodeHtmlEntities(p['equipo_visitante']?.toString());
    final golesLocal = int.tryParse(p['goles_local']?.toString() ?? '');
    final golesVisitante = int.tryParse(p['goles_visitante']?.toString() ?? '');

    final hayGoleadores =
        goleadoresLocal.isNotEmpty || goleadoresVisitante.isNotEmpty;

    Widget marcador(int? goles) {
      return Text(
        goles?.toString() ?? '-',
        style: const TextStyle(
          fontSize: 44,
          fontWeight: FontWeight.w800,
          color: Colors.black87,
          height: 1,
        ),
      );
    }

    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        // A whisper of brand colour instead of a saturated block: same family
        // as the strip on the match list cards.
        color: primary.withValues(alpha: 0.06),
        border: Border.all(color: primary.withValues(alpha: 0.12)),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 22, 12, 18),
        child: Column(
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                Expanded(
                  child: _buildEquipoColumna(local, p['escudo_local']?.toString()),
                ),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 8),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      marcador(golesLocal),
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 8),
                        child: Text(
                          '-',
                          style: TextStyle(
                            fontSize: 30,
                            fontWeight: FontWeight.w300,
                            color: Colors.grey.shade400,
                          ),
                        ),
                      ),
                      marcador(golesVisitante),
                    ],
                  ),
                ),
                Expanded(
                  child: _buildEquipoColumna(
                    visitante,
                    p['escudo_visitante']?.toString(),
                  ),
                ),
              ],
            ),
            if (hayGoleadores) ...[
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 16),
                child: Divider(
                  height: 1,
                  thickness: 1,
                  color: primary.withValues(alpha: 0.15),
                ),
              ),
              _buildGoleadores(goleadoresLocal, goleadoresVisitante),
            ],
          ],
        ),
      ),
    );
  }

  /// A crest framed by a white disc so logos of any colour keep their edge
  /// against the tinted panel, with the team name underneath.
  Widget _buildEquipoColumna(String nombre, String? escudoUrl) {
    final placeholder =
        Icon(Icons.shield_outlined, size: 34, color: Colors.grey.shade400);

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          height: 62,
          width: 62,
          padding: const EdgeInsets.all(7),
          decoration: BoxDecoration(
            color: Colors.white,
            shape: BoxShape.circle,
            border: Border.all(color: Colors.grey.shade200),
          ),
          child: escudoUrl != null && escudoUrl.isNotEmpty
              ? Image.network(
                  escudoUrl,
                  fit: BoxFit.contain,
                  errorBuilder: (_, __, ___) => placeholder,
                )
              : placeholder,
        ),
        const SizedBox(height: 10),
        Text(
          nombre,
          textAlign: TextAlign.center,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(
            fontSize: 13,
            fontWeight: FontWeight.w700,
            color: Colors.black87,
            letterSpacing: 0.3,
          ),
        ),
      ],
    );
  }

  Widget _buildInfoRow(IconData icon, String label, String? value) {
    final sinDato = value == null || value.isEmpty;
    final primary = Theme.of(context).colorScheme.primary;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(
              color: primary.withValues(alpha: 0.08),
              shape: BoxShape.circle,
            ),
            child: Icon(icon, size: 20, color: primary),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: TextStyle(fontSize: 12, color: Colors.grey[600]),
                ),
                const SizedBox(height: 2),
                Text(
                  sinDato ? 'No informado' : value,
                  style: TextStyle(
                    fontSize: 16,
                    fontWeight: sinDato ? FontWeight.normal : FontWeight.w500,
                    color: sinDato ? Colors.grey[500] : Colors.black87,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildEstadisticas() {
    
  if (goleadores == null) {
    return LoadingSeccionConAd(
      texto: 'Cargando estadísticas...',
      //adImageUrl: estadisticasAdUrl,
    );
  }

    final localStats = goleadores?['equipo_local']['goleadores'] as List<dynamic>? ?? [];
    final visitanteStats = goleadores?['equipo_visitante']['goleadores'] as List<dynamic>? ?? [];

    int sum(String key, List<dynamic> jugadores) {
      return jugadores.fold(0, (total, jugador) {
        final raw = jugador[key];
        if (raw == null) return total;
        if (raw is num) return total + raw.toInt();
        if (raw is String) {
          final normalizado = raw.replaceAll(',', '.');
          final numParsed = double.tryParse(normalizado);
          return numParsed != null ? total + numParsed.toInt() : total;
        }
        return total;
      });
    }

    final allPlayers = [...localStats, ...visitanteStats];
    final figura = allPlayers.cast<Map<String, dynamic>>().firstWhere(
      (j) => j['figura'] == true,
      orElse: () => {},
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _buildScoreboard(
          goleadoresLocal: _goleadoresDe(localStats),
          goleadoresVisitante: _goleadoresDe(visitanteStats),
        ),
        const SizedBox(height: 20),
        // Goals are already the headline of the scoreboard above.
        _buildBloqueEstadisticas([
          _Estadistica('Amarillas', sum('tarjeta_amarilla', localStats),
              sum('tarjeta_amarilla', visitanteStats)),
          _Estadistica('Rojas', sum('tarjeta_roja', localStats),
              sum('tarjeta_roja', visitanteStats)),
        ]),
        if (figura.isNotEmpty) _buildFiguraCard(figura),
      ],
    );
  }

  /// Surnames of the players who scored, with the goal count when a player
  /// scored more than once. The API has no minute data, so none is shown.
  List<String> _goleadoresDe(List<dynamic> jugadores) {
    final resultado = <String>[];
    for (final j in jugadores.whereType<Map<String, dynamic>>()) {
      final goles = int.tryParse(j['goles']?.toString() ?? '') ?? 0;
      if (goles <= 0) continue;
      final apellido = (j['nombre']?.toString() ?? '').split(',').first.trim();
      if (apellido.isEmpty) continue;
      resultado.add(goles > 1 ? '$apellido ($goles)' : apellido);
    }
    return resultado;
  }

  /// Scorers of each team, split by a ball icon so the column reads as goals.
  Widget _buildGoleadores(List<String> local, List<String> visitante) {
    Widget columna(List<String> nombres, CrossAxisAlignment alineacion) {
      return Column(
        crossAxisAlignment: alineacion,
        children: nombres
            .map((n) => Padding(
                  padding: const EdgeInsets.symmetric(vertical: 2),
                  child: Text(
                    n,
                    textAlign: alineacion == CrossAxisAlignment.end
                        ? TextAlign.right
                        : TextAlign.left,
                    style: TextStyle(
                      fontSize: 13.5,
                      fontWeight: FontWeight.w500,
                      color: Colors.grey.shade800,
                    ),
                  ),
                ))
            .toList(),
      );
    }

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(child: columna(local, CrossAxisAlignment.end)),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14),
          child: Icon(
            Icons.sports_soccer,
            size: 17,
            color: Colors.grey.shade700,
          ),
        ),
        Expanded(child: columna(visitante, CrossAxisAlignment.start)),
      ],
    );
  }

  /// All match stats in a single card.
  ///
  /// Both sides share the same colour and type weight, so the bars only convey
  /// proportion — neither team is singled out as the better one.
  Widget _buildBloqueEstadisticas(List<_Estadistica> estadisticas) {
    Widget valor(int v) => Text(
          '$v',
          style: const TextStyle(
            fontSize: 20,
            fontWeight: FontWeight.w800,
            color: Colors.black87,
          ),
        );

    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: Colors.grey.shade200),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 6),
        child: Column(
          children: [
            for (var i = 0; i < estadisticas.length; i++) ...[
              if (i > 0) Divider(height: 1, color: Colors.grey.withValues(alpha: 0.2)),
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 16),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    valor(estadisticas[i].local),
                    Text(
                      estadisticas[i].titulo.toUpperCase(),
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                        letterSpacing: 0.8,
                        color: Colors.grey[600],
                      ),
                    ),
                    valor(estadisticas[i].visitante),
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  /// Opens the player detail screen for [jugadorId].
  ///
  /// The match endpoints return a reduced player shape (goals, cards, rating),
  /// so the full record has to be fetched by id before pushing the screen.
  Future<void> _abrirDetalleJugador(dynamic jugadorId) async {
    if (jugadorId == null) return;

    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => const Center(child: CircularProgressIndicator()),
    );

    try {
      final jugadorCompleto =
          await ref.read(apiServiceProvider).getJugadorPorId(jugadorId);
      if (!mounted) return;
      Navigator.of(context).pop(); // Quitar loader
      Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => PlayerDetailScreen(player: jugadorCompleto),
        ),
      );
    } catch (_) {
      if (!mounted) return;
      Navigator.of(context).pop();
      showDialog(
        context: context,
        builder: (_) => AlertDialog(
          title: const Text('Error'),
          content: const Text('No se pudo cargar la información del jugador.'),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('OK'),
            ),
          ],
        ),
      );
    }
  }

  /// The man of the match, given its own gold identity so it stands apart from
  /// the blue scoreboard above it.
  Widget _buildFiguraCard(Map<String, dynamic> figura) {
    final nombre = decodeHtmlEntities(figura['nombre']?.toString()).isEmpty
        ? 'Jugador'
        : decodeHtmlEntities(figura['nombre']?.toString());
    final foto = (figura['foto'] is String && figura['foto'].toString().isNotEmpty)
        ? figura['foto'].toString()
        : null;

    // `equipo` on the player is the side marker set while enriching the
    // scorers ('local' / 'visitante'), not a club name, so resolve the real
    // name and crest from the match itself.
    final esLocal = figura['equipo'] == 'local';
    final equipo = decodeHtmlEntities((esLocal
            ? widget.partido['equipo_local']
            : widget.partido['equipo_visitante'])
        ?.toString());
    final escudoEquipo = (esLocal
            ? widget.partido['escudo_local']
            : widget.partido['escudo_visitante'])
        ?.toString();

    // Gold stays the identity of the man of the match, but as a tint with dark
    // type — the same restraint applied to the scoreboard panel.
    const oro = Color(0xFFB8860B);
    const oroTexto = Color(0xFF8A6914);

    return Padding(
      padding: const EdgeInsets.only(top: 20),
      child: Material(
        borderRadius: BorderRadius.circular(20),
        clipBehavior: Clip.antiAlias,
        child: Ink(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(20),
            color: oro.withValues(alpha: 0.09),
            border: Border.all(color: oro.withValues(alpha: 0.28)),
          ),
          child: InkWell(
            onTap: () => _abrirDetalleJugador(figura['id']),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Row(
                children: [
                  Stack(
                    clipBehavior: Clip.none,
                    children: [
                      Container(
                        width: 64,
                        height: 64,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          border: Border.all(color: Colors.white, width: 2.5),
                          image: foto != null
                              ? DecorationImage(
                                  image: NetworkImage(foto), fit: BoxFit.cover)
                              : null,
                          color: Colors.grey.shade200,
                        ),
                        child: foto == null
                            ? Icon(Icons.person, color: Colors.grey.shade500, size: 34)
                            : null,
                      ),
                      Positioned(
                        right: -2,
                        bottom: -2,
                        child: Container(
                          padding: const EdgeInsets.all(4),
                          decoration: const BoxDecoration(
                            color: oro,
                            shape: BoxShape.circle,
                          ),
                          child: const Icon(Icons.star, size: 16, color: Colors.white),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(width: 16),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'FIGURA DEL PARTIDO',
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.w700,
                            letterSpacing: 1.2,
                            color: oroTexto,
                          ),
                        ),
                        const SizedBox(height: 6),
                        Text(
                          nombre,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            fontSize: 18,
                            fontWeight: FontWeight.w800,
                            color: Colors.black87,
                          ),
                        ),
                        if (equipo.isNotEmpty)
                          Padding(
                            padding: const EdgeInsets.only(top: 4),
                            child: Row(
                              children: [
                                if (escudoEquipo != null && escudoEquipo.isNotEmpty) ...[
                                  Container(
                                    width: 22,
                                    height: 22,
                                    padding: const EdgeInsets.all(2),
                                    decoration: BoxDecoration(
                                      color: Colors.white,
                                      shape: BoxShape.circle,
                                      border: Border.all(color: Colors.grey.shade200),
                                    ),
                                    child: Image.network(
                                      escudoEquipo,
                                      fit: BoxFit.contain,
                                      errorBuilder: (_, __, ___) => const SizedBox.shrink(),
                                    ),
                                  ),
                                  const SizedBox(width: 7),
                                ],
                                Flexible(
                                  child: Text(
                                    equipo,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: TextStyle(
                                      fontSize: 13,
                                      fontWeight: FontWeight.w500,
                                      color: Colors.grey.shade700,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                      ],
                    ),
                  ),
                  Icon(
                    Icons.chevron_right,
                    color: oro.withValues(alpha: 0.8),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

Widget _buildAlineaciones() {
  if (goleadores == null) {
    return LoadingSeccionConAd(
      texto: 'Cargando estadísticas...',
      //adImageUrl: alineacionesAdUrl,
    );
  }

  final List<dynamic> jugadoresRaw = equipoSeleccionado == 'local'
      ? (goleadores?['equipo_local']['goleadores'] ?? [])
      : (goleadores?['equipo_visitante']['goleadores'] ?? []);

  final jugadores = jugadoresRaw.whereType<Map<String, dynamic>>().toList();

  final bajas = jugadores.where((j) => j['reemplazo_baja'] == true).toList();
  final disponibles = jugadores.where((j) => j['reemplazo_baja'] != true).toList();

  final filas = _armarFilas(disponibles);

  // A line holding a single player does not need the same slice of pitch as a
  // full defence: giving it less weight reclaims the gap above the keeper.
  final pesos = [for (final fila in filas) fila.length == 1 ? 2 : 3];
  final pesoTotal = pesos.fold<int>(0, (total, peso) => total + peso);

  var acumulado = 0;
  final profundidades = <double>[];
  for (final peso in pesos) {
    // Depth of the row's centre, measured with the real weights so the
    // perspective clamp keeps matching where the row actually sits.
    profundidades.add((acumulado + peso / 2) / pesoTotal);
    acumulado += peso;
  }

  return Column(
    children: [
      _buildSelectorEquipo(),
      const SizedBox(height: 16),
      ClipRRect(
        borderRadius: BorderRadius.circular(16),
        child: AspectRatio(
          aspectRatio: 0.86,
          child: LayoutBuilder(
            builder: (context, constraints) => Stack(
              fit: StackFit.expand,
              children: [
                const CustomPaint(painter: FullFieldPainter()),
                AnimatedSwitcher(
                  duration: const Duration(milliseconds: 400),
                  child: Padding(
                    key: ValueKey(equipoSeleccionado),
                    padding: const EdgeInsets.symmetric(vertical: 10),
                    child: Column(
                      children: [
                        for (var i = 0; i < filas.length; i++)
                          _buildLineaJugadores(
                            filas[i],
                            peso: pesos[i],
                            profundidad: profundidades[i],
                            anchoCancha: constraints.maxWidth,
                          ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
      if (bajas.isNotEmpty) _buildBajas(bajas),
    ],
  );
}

  /// Players unavailable for the match. Wrapped in the same panel language as
  /// the rest of the detail so the list does not read as leftovers.
  Widget _buildBajas(List<Map<String, dynamic>> bajas) {
    return Padding(
      padding: const EdgeInsets.only(top: 20),
      child: Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: Colors.grey.shade200),
        ),
        clipBehavior: Clip.antiAlias,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: double.infinity,
              color: Colors.grey.shade50,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 11),
              child: Row(
                children: [
                  Icon(Icons.person_off_outlined,
                      size: 16, color: Colors.grey.shade600),
                  const SizedBox(width: 8),
                  Text(
                    'BAJAS',
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 0.8,
                      color: Colors.grey.shade700,
                    ),
                  ),
                  const Spacer(),
                  Text(
                    bajas.length == 1 ? '1 jugador' : '${bajas.length} jugadores',
                    style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                  ),
                ],
              ),
            ),
            Divider(height: 1, color: Colors.grey.shade200),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 18, 16, 18),
              child: Wrap(
                spacing: 14,
                runSpacing: 18,
                alignment: WrapAlignment.center,
                children: bajas
                    .map((j) => GestureDetector(
                          onTap: () => _abrirDetalleJugador(j['id']),
                          child: PlayerPod(jugador: j, scale: 0.85, onField: false),
                        ))
                    .toList(),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// Maximum players drawn on a single line before adding another one.
  static const int _maxPorFila = 4;

  /// Maximum number of outfield lines, so a large squad packs rows instead of
  /// producing a column of nearly empty ones.
  static const int _maxFilasCampo = 4;

  /// Relative weight used to order players down the pitch. Positions are often
  /// left at their default value in the backend, so this only drives the
  /// ordering — the number of players per line comes from [_repartirEnFilas].
  static const Map<String, int> _ordenPosicion = {
    'Defensor': 0,
    'Mediocampista': 1,
    'Delantero': 2,
  };

  /// Builds the pitch rows, top to bottom: the keeper alone, then everyone
  /// else spread over balanced lines ordered defence → midfield → attack.
  ///
  /// Players are never filtered out by position: an unrecognised or missing
  /// position sorts with the midfield instead of disappearing from the pitch.
  List<List<Map<String, dynamic>>> _armarFilas(List<Map<String, dynamic>> disponibles) {
    final restantes = List<Map<String, dynamic>>.from(disponibles);

    Map<String, dynamic>? arquero;
    for (final posicion in ['Arquero', 'Arquero Sup.']) {
      final indice = restantes.indexWhere((j) => j['posicion'] == posicion);
      if (indice != -1) {
        arquero = restantes.removeAt(indice);
        break;
      }
    }

    // Decorate with the original index so equal positions keep their incoming
    // order — List.sort is not stable in Dart.
    final indexados = restantes.asMap().entries.toList()
      ..sort((a, b) {
        final pa = _ordenPosicion[a.value['posicion']?.toString()] ?? 1;
        final pb = _ordenPosicion[b.value['posicion']?.toString()] ?? 1;
        return pa != pb ? pa.compareTo(pb) : a.key.compareTo(b.key);
      });

    return [
      if (arquero != null) [arquero],
      ..._repartirEnFilas(indexados.map((e) => e.value).toList()),
    ];
  }

  /// Splits [jugadores] into balanced lines, preserving their order. Extra
  /// players go to the first lines, keeping the defensive side the widest.
  List<List<Map<String, dynamic>>> _repartirEnFilas(List<Map<String, dynamic>> jugadores) {
    if (jugadores.isEmpty) return [];

    final cantidadFilas =
        (jugadores.length / _maxPorFila).ceil().clamp(1, _maxFilasCampo);
    final base = jugadores.length ~/ cantidadFilas;
    var resto = jugadores.length % cantidadFilas;

    final filas = <List<Map<String, dynamic>>>[];
    var desde = 0;
    for (var i = 0; i < cantidadFilas; i++) {
      final cantidad = base + (resto > 0 ? 1 : 0);
      if (resto > 0) resto--;
      filas.add(jugadores.sublist(desde, desde + cantidad));
      desde += cantidad;
    }
    return filas;
  }

  /// One line of players on the pitch.
  ///
  /// The line is clamped to the pitch width at its own depth, using the same
  /// perspective as [FullFieldPainter], so markers never spill past the
  /// touchline. Crowded lines shrink as a block instead of overflowing.
  Widget _buildLineaJugadores(
    List<Map<String, dynamic>> jugadores, {
    required int peso,
    required double profundidad,
    required double anchoCancha,
  }) {
    if (jugadores.isEmpty) return const SizedBox.shrink();

    final escala = 0.78 + (1.0 - 0.78) * profundidad;
    final anchoDisponible =
        anchoCancha * FullFieldPainter.halfWidthAt(profundidad) * 2 * 0.94;

    return Expanded(
      flex: peso,
      child: Center(
        child: SizedBox(
          width: anchoDisponible,
          child: FittedBox(
            fit: BoxFit.scaleDown,
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: jugadores
                  .map((j) => Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 2),
                        child: GestureDetector(
                          onTap: () => _abrirDetalleJugador(j['id']),
                          child: PlayerPod(jugador: j, scale: escala),
                        ),
                      ))
                  .toList(),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildSelectorEquipo() {
    final primary = Theme.of(context).colorScheme.primary;

    Widget opcion(String valor, String nombre) {
      final seleccionado = equipoSeleccionado == valor;
      return Expanded(
        child: GestureDetector(
          onTap: () => setState(() => equipoSeleccionado = valor),
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 200),
            padding: const EdgeInsets.symmetric(vertical: 7),
            decoration: BoxDecoration(
              color: seleccionado ? primary : Colors.transparent,
              borderRadius: BorderRadius.circular(24),
            ),
            child: Text(
              decodeHtmlEntities(nombre),
              textAlign: TextAlign.center,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontWeight: FontWeight.bold,
                color: seleccionado ? Colors.white : Colors.black54,
              ),
            ),
          ),
        ),
      );
    }

    return Container(
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(
        color: Colors.grey.shade200,
        borderRadius: BorderRadius.circular(24),
      ),
      child: Row(
        children: [
          opcion('local', widget.partido['equipo_local']?.toString() ?? 'Local'),
          opcion('visitante', widget.partido['equipo_visitante']?.toString() ?? 'Visitante'),
        ],
      ),
    );
  }

  Widget _buildPendiente() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: const [
            Icon(Icons.schedule, size: 72, color: Colors.black26),
            SizedBox(height: 20),
            Text(
              'El partido no se ha disputado aún.',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold, color: Colors.black54),
            ),
            SizedBox(height: 8),
            Text(
              'La información estará disponible luego de disputado el partido.',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 14, color: Colors.black45),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Detalle del Partido')),
      body: Column(
        children: [
          TabBar(
            controller: _tabController,
            labelColor: Colors.black,
            indicatorColor: Theme.of(context).colorScheme.primary,
            tabs: const [
              Tab(text: 'Estadísticas'),
              Tab(text: 'Alineaciones'),
              Tab(text: 'Información'),
            ],
          ),
          Expanded(
            child: TabBarView(
              controller: _tabController,
              children: [
                _esFuturo
                    ? _buildPendiente()
                    : goleadores == null && isLoading
                        ? LoadingSeccionConAd(
                            texto: 'Cargando estadísticas...',
                            adImageUrl: estadisticasAdUrl,
                          )
                        : SingleChildScrollView(
                            padding: const EdgeInsets.all(16),
                            child: _buildEstadisticas(),
                          ),
                _esFuturo
                    ? _buildPendiente()
                    : goleadores == null && isLoading
                        ? LoadingSeccionConAd(
                            texto: 'Cargando alineaciones...',
                          )
                        : SingleChildScrollView(
                            padding: const EdgeInsets.all(16),
                            child: _buildAlineaciones(),
                          ),
                _esFuturo
                    ? _buildPendiente()
                    : SingleChildScrollView(
                        padding: const EdgeInsets.all(16),
                        child: _buildInformacion(),
                      ),
              ],
            ),
          ),
        ],
      ),
      bottomNavigationBar: const ZocaloPublicitario(), // ✅ Ahora está acá
    );
  }
}

  class LoadingSeccionConAd extends StatelessWidget {
    final String texto;
    final String? adImageUrl;

    const LoadingSeccionConAd({
      super.key,
      required this.texto,
      this.adImageUrl,
    });

    @override
    Widget build(BuildContext context) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const CircularProgressIndicator(),
            const SizedBox(height: 12),
            Text(texto, style: const TextStyle(fontSize: 14)),
            if (adImageUrl != null && adImageUrl!.isNotEmpty) ...[
              const SizedBox(height: 24),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                  child: AspectRatio(
                    aspectRatio: 16 / 9,
                    child: Image.network(
                      adImageUrl!,
                      fit: BoxFit.contain,
                      alignment: Alignment.center,
                      width: double.infinity,
                      errorBuilder: (context, error, stackTrace) =>
                          const Text('No se pudo cargar la imagen publicitaria'),
                    ),
                  ),
              ),
            ]          ],
        ),
      );
    }
  }
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../providers/service_providers.dart';
import '../utils/date_utils.dart';
import '../utils/puntaje_utils.dart';
import '../utils/text_utils.dart';
import '../widgets/zocalo_publicitario.dart';
import '../widgets/full_field_painter.dart';
import '../widgets/player_pod.dart';
import 'player_detail_screen.dart';


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

  bool _goleadoresCargados = false;

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

  Widget _buildResumen() {
    final p = widget.partido;

    final fechaLarga = formatFechaLarga(p['fecha']?.toString());
    final hora = _valorOpcional(p['hora']);
    final temporada = _temporadaLegible(p['temporada']);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _buildScoreboard(),
        const SizedBox(height: 16),
        Card(
          elevation: 2,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
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

  Widget _buildScoreboard() {
    final p = widget.partido;

    final local = decodeHtmlEntities(p['equipo_local']?.toString());
    final visitante = decodeHtmlEntities(p['equipo_visitante']?.toString());
    final golesLocal = int.tryParse(p['goles_local']?.toString() ?? '');
    final golesVisitante = int.tryParse(p['goles_visitante']?.toString() ?? '');

    Widget marcador(int? goles) {
      return Text(
        goles?.toString() ?? '-',
        style: const TextStyle(
          fontSize: 40,
          fontWeight: FontWeight.bold,
          color: Colors.black,
        ),
      );
    }

    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 20),
        child: Row(
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
                    padding: const EdgeInsets.symmetric(horizontal: 6),
                    child: Text(
                      '-',
                      style: TextStyle(
                        fontSize: 28,
                        fontWeight: FontWeight.w300,
                        color: Colors.grey[400],
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
      ),
    );
  }

  Widget _buildEquipoColumna(String nombre, String? escudoUrl) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        SizedBox(
          height: 56,
          width: 56,
          child: escudoUrl != null && escudoUrl.isNotEmpty
              ? Image.network(
                  escudoUrl,
                  fit: BoxFit.contain,
                  errorBuilder: (_, __, ___) =>
                      Icon(Icons.shield_outlined, size: 40, color: Colors.grey[400]),
                )
              : Icon(Icons.shield_outlined, size: 40, color: Colors.grey[400]),
        ),
        const SizedBox(height: 8),
        Text(
          nombre,
          textAlign: TextAlign.center,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(
            fontSize: 14,
            fontWeight: FontWeight.w500,
            color: Colors.black87,
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

    final equipoLocal = widget.partido['equipo_local'] ?? '';
    final equipoVisitante = widget.partido['equipo_visitante'] ?? '';
    final escudoLocal = widget.partido['escudo_local'];
    final escudoVisitante = widget.partido['escudo_visitante'];

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

    Widget statRow(String title, int localVal, int visitanteVal) {
      return Card(
        margin: const EdgeInsets.symmetric(vertical: 6),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text('$localVal', style: const TextStyle(fontSize: 16)),
              Text(title, style: const TextStyle(fontWeight: FontWeight.bold)),
              Text('$visitanteVal', style: const TextStyle(fontSize: 16)),
            ],
          ),
        ),
      );
    }

    final allPlayers = [...localStats, ...visitanteStats];
    final figura = allPlayers.cast<Map<String, dynamic>>().firstWhere(
      (j) => j['figura'] == true,
      orElse: () => {},
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Expanded(child: _buildTeamHeader(equipoLocal, escudoLocal)),
            const SizedBox(width: 16),
            Expanded(child: _buildTeamHeader(equipoVisitante, escudoVisitante)),
          ],
        ),
        const SizedBox(height: 12),
        statRow('Goles', sum('goles', localStats), sum('goles', visitanteStats)),
        statRow('Amarillas', sum('tarjeta_amarilla', localStats), sum('tarjeta_amarilla', visitanteStats)),
        statRow('Rojas', sum('tarjeta_roja', localStats), sum('tarjeta_roja', visitanteStats)),
        if (figura.isNotEmpty) _buildFiguraCard(figura),
      ],
    );
  }

  Widget _buildTeamHeader(String nombre, String? escudoUrl) {
    return Column(
      children: [
        if (escudoUrl != null && escudoUrl.isNotEmpty)
          Image.network(escudoUrl, height: 40),
        const SizedBox(height: 4),
        SizedBox(
          width: double.infinity,
          child: Text(
            nombre,
            style: const TextStyle(fontWeight: FontWeight.bold),
            textAlign: TextAlign.center,
            overflow: TextOverflow.ellipsis,
            maxLines: 2,
          ),
        ),
      ],
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

  Widget _buildFiguraCard(Map<String, dynamic> figura) {
    final nombre = figura['nombre'] ?? 'Jugador';
    final equipo = figura['equipo'] ?? '';
    final puntaje = formatearPuntaje(figura['puntaje']);
    final foto = (figura['foto'] is String && figura['foto'].toString().isNotEmpty)
        ? figura['foto']
        : null;

    return Card(
      margin: const EdgeInsets.only(top: 16),
      child: InkWell(
        onTap: () => _abrirDetalleJugador(figura['id']),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Figura del partido', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              const SizedBox(height: 8),
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: foto != null
                    ? CircleAvatar(backgroundImage: NetworkImage(foto))
                    : const Icon(Icons.person),
                title: Text(nombre),
                subtitle: Text('Equipo: $equipo'),
                trailing: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.star, color: Colors.amber),
                    const SizedBox(width: 4),
                    Text(puntaje),
                  ],
                ),
              ),
            ],
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

  return Column(
    children: [
      _buildSelectorEquipo(),
      const SizedBox(height: 16),
      ClipRRect(
        borderRadius: BorderRadius.circular(16),
        child: AspectRatio(
          aspectRatio: 0.72,
          child: LayoutBuilder(
            builder: (context, constraints) => Stack(
              fit: StackFit.expand,
              children: [
                const CustomPaint(painter: FullFieldPainter()),
                AnimatedSwitcher(
                  duration: const Duration(milliseconds: 400),
                  child: Padding(
                    key: ValueKey(equipoSeleccionado),
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    child: Column(
                      children: [
                        for (var i = 0; i < filas.length; i++)
                          _buildLineaJugadores(
                            filas[i],
                            indice: i,
                            totalFilas: filas.length,
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
      if (bajas.isNotEmpty)
        Padding(
          padding: const EdgeInsets.only(top: 20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Bajas', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              const SizedBox(height: 12),
              Wrap(
                spacing: 12,
                runSpacing: 16,
                alignment: WrapAlignment.center,
                children: bajas
                    .map((j) => GestureDetector(
                          onTap: () => _abrirDetalleJugador(j['id']),
                          child: PlayerPod(jugador: j, scale: 0.85, onField: false),
                        ))
                    .toList(),
              ),
            ],
          ),
        ),
    ],
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
    required int indice,
    required int totalFilas,
    required double anchoCancha,
  }) {
    if (jugadores.isEmpty) return const SizedBox.shrink();

    final profundidad = (indice + 0.5) / totalFilas;
    final escala = 0.78 + (1.0 - 0.78) * profundidad;
    final anchoDisponible =
        anchoCancha * FullFieldPainter.halfWidthAt(profundidad) * 2 * 0.94;

    return Expanded(
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
            padding: const EdgeInsets.symmetric(vertical: 10),
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
      padding: const EdgeInsets.all(4),
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
              Tab(text: 'Resumen'),
              Tab(text: 'Estadísticas'),
              Tab(text: 'Alineaciones'),
            ],
          ),
          Expanded(
            child: TabBarView(
              controller: _tabController,
              children: [
                _esFuturo
                    ? _buildPendiente()
                    : SingleChildScrollView(
                        padding: const EdgeInsets.all(16),
                        child: _buildResumen(),
                      ),
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
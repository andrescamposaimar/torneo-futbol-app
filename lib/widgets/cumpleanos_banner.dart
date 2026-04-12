import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../providers/service_providers.dart';
import '../providers/temporadas_provider.dart';
import '../screens/player_detail_screen.dart';

class CumpleanosBanner extends ConsumerStatefulWidget {
  const CumpleanosBanner({super.key});

  @override
  ConsumerState<CumpleanosBanner> createState() => _CumpleanosBannerState();
}

class _CumpleanosBannerState extends ConsumerState<CumpleanosBanner> {
  List<_Cumpleanero> _cumpleaneros = [];
  bool _loaded = false;

  @override
  void initState() {
    super.initState();
    _loadCumpleaneros();
  }

  Future<void> _loadCumpleaneros() async {
    try {
      final temporada = await ref.read(temporadaActualProvider.future);
      final cache = ref.read(cacheServiceProvider);
      final api = ref.read(apiServiceProvider);

      List<dynamic>? jugadores =
          await cache.getCachedPlayersCurrentSeason(temporada.id);

      if (jugadores == null || jugadores.isEmpty) {
        jugadores =
            await api.getJugadoresTemporadaActual(temporada.id, perPage: 100);
      }

      final hoy = DateTime.now();
      final cumpleaneros = <_Cumpleanero>[];

      for (final j in jugadores) {
        final fechaStr = j['fecha_nacimiento']?.toString();
        if (fechaStr == null || fechaStr.isEmpty) continue;

        final fecha = DateTime.tryParse(fechaStr);
        if (fecha == null) continue;

        if (fecha.month == hoy.month && fecha.day == hoy.day) {
          int edad = hoy.year - fecha.year;
          final nombre =
              j['title']?['rendered']?.toString() ?? j['nombre']?.toString() ?? '';
          if (nombre.isNotEmpty) {
            cumpleaneros.add(_Cumpleanero(
              label: '$nombre ($edad)',
              playerRaw: Map<String, dynamic>.from(j),
            ));
          }
        }
      }

      if (!mounted) return;
      setState(() {
        _cumpleaneros = cumpleaneros;
        _loaded = true;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _loaded = true);
    }
  }

  void _onTapJugador(_Cumpleanero c) {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => PlayerDetailScreen(player: c.playerRaw),
    ));
  }

  @override
  Widget build(BuildContext context) {
    if (!_loaded || _cumpleaneros.isEmpty) return const SizedBox.shrink();

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 12),
      decoration: BoxDecoration(
        color: const Color(0xFFE3F2FD),
        border: Border(
          bottom: BorderSide(color: Colors.blue.shade200, width: 1),
        ),
      ),
      child: Row(
        children: [
          const Text('🎂', style: TextStyle(fontSize: 20)),
          const SizedBox(width: 6),
          Text(
            'Cumplen hoy:',
            style: TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: Colors.blue.shade400,
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: _CumpleanerosTicker(
              cumpleaneros: _cumpleaneros,
              onTap: _onTapJugador,
            ),
          ),
        ],
      ),
    );
  }
}

class _Cumpleanero {
  final String label;
  final Map<String, dynamic> playerRaw;
  const _Cumpleanero({required this.label, required this.playerRaw});
}

class _CumpleanerosTicker extends StatelessWidget {
  final List<_Cumpleanero> cumpleaneros;
  final void Function(_Cumpleanero) onTap;

  const _CumpleanerosTicker({required this.cumpleaneros, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        // Medir el ancho total del texto concatenado
        final fullText = cumpleaneros.map((c) => c.label).join('  ·  ');
        final textPainter = TextPainter(
          text: TextSpan(
            text: fullText,
            style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w500),
          ),
          maxLines: 1,
          textDirection: TextDirection.ltr,
        )..layout();

        final fits = textPainter.width <= constraints.maxWidth;

        if (fits) {
          return _buildStatic();
        }
        return _MarqueeRow(cumpleaneros: cumpleaneros, onTap: onTap);
      },
    );
  }

  Widget _buildStatic() {
    final children = <Widget>[];
    for (int i = 0; i < cumpleaneros.length; i++) {
      if (i > 0) {
        children.add(Text(
          '  ·  ',
          style: TextStyle(
            fontSize: 14,
            fontWeight: FontWeight.w500,
            color: Colors.blue.shade300,
          ),
        ));
      }
      final c = cumpleaneros[i];
      children.add(
        GestureDetector(
          onTap: () => onTap(c),
          child: Text(
            c.label,
            style: TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w600,
              color: const Color(0xFF005BBB),
              decoration: TextDecoration.underline,
              decorationColor: Colors.blue.shade300,
            ),
          ),
        ),
      );
    }
    return Row(mainAxisSize: MainAxisSize.min, children: children);
  }
}

class _MarqueeRow extends StatefulWidget {
  final List<_Cumpleanero> cumpleaneros;
  final void Function(_Cumpleanero) onTap;

  const _MarqueeRow({required this.cumpleaneros, required this.onTap});

  @override
  State<_MarqueeRow> createState() => _MarqueeRowState();
}

class _MarqueeRowState extends State<_MarqueeRow>
    with SingleTickerProviderStateMixin {
  late final ScrollController _scrollController;
  late final AnimationController _animationController;
  double _contentWidth = 0;
  double _viewportWidth = 0;

  @override
  void initState() {
    super.initState();
    _scrollController = ScrollController();
    _animationController = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 1),
    );
  }

  @override
  void dispose() {
    _animationController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _startAnimation() {
    if (_contentWidth <= _viewportWidth) return;

    final totalScroll = _contentWidth - _viewportWidth + 60;
    final duration = Duration(milliseconds: (totalScroll / 40 * 1000).round());
    _animationController.duration = duration;

    _animationController.addListener(() {
      if (_scrollController.hasClients) {
        final maxScroll = _scrollController.position.maxScrollExtent;
        _scrollController.jumpTo(_animationController.value * maxScroll);
      }
    });

    Future.delayed(const Duration(seconds: 2), () {
      if (!mounted) return;
      _loopAnimation();
    });
  }

  Future<void> _loopAnimation() async {
    while (mounted) {
      await _animationController.forward(from: 0);
      await Future.delayed(const Duration(seconds: 3));
      if (!mounted) return;
      _scrollController.jumpTo(0);
      await Future.delayed(const Duration(seconds: 2));
    }
  }

  List<Widget> _buildChildren() {
    final children = <Widget>[];
    for (int i = 0; i < widget.cumpleaneros.length; i++) {
      if (i > 0) {
        children.add(Text(
          '  ·  ',
          style: TextStyle(
            fontSize: 14,
            fontWeight: FontWeight.w500,
            color: Colors.blue.shade300,
          ),
        ));
      }
      final c = widget.cumpleaneros[i];
      children.add(
        GestureDetector(
          onTap: () => widget.onTap(c),
          child: Text(
            c.label,
            style: TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w600,
              color: const Color(0xFF005BBB),
              decoration: TextDecoration.underline,
              decorationColor: Colors.blue.shade300,
            ),
          ),
        ),
      );
    }
    return children;
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 20,
      child: LayoutBuilder(
        builder: (context, constraints) {
          _viewportWidth = constraints.maxWidth;

          return NotificationListener<ScrollMetricsNotification>(
            onNotification: (notification) {
              if (_contentWidth == 0 && _scrollController.hasClients) {
                WidgetsBinding.instance.addPostFrameCallback((_) {
                  if (!mounted || !_scrollController.hasClients) return;
                  _contentWidth = _scrollController.position.maxScrollExtent +
                      _viewportWidth;
                  _startAnimation();
                });
              }
              return false;
            },
            child: SingleChildScrollView(
              controller: _scrollController,
              scrollDirection: Axis.horizontal,
              physics: const NeverScrollableScrollPhysics(),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: _buildChildren(),
              ),
            ),
          );
        },
      ),
    );
  }
}

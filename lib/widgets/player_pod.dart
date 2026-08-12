import 'package:flutter/material.dart';

/// A player marker rendered on the lineup pitch.
///
/// [scale] shrinks the whole marker so rows further up the pitch read as
/// being further away, matching the perspective of the field behind it.
///
/// Set [onField] to false when the marker is placed outside the pitch, so the
/// name renders in dark text instead of the white used over the grass.
class PlayerPod extends StatelessWidget {
  final Map<String, dynamic> jugador;
  final double scale;
  final bool onField;

  const PlayerPod({
    super.key,
    required this.jugador,
    this.scale = 1.0,
    this.onField = true,
  });

  String get apellido {
    final nombreCompleto = jugador['nombre']?.toString() ?? '';
    final partes = nombreCompleto.split(',');
    return partes.first.trim();
  }

  bool get esFigura => jugador['figura'] == true;

  int _entero(dynamic valor) {
    if (valor is int) return valor;
    if (valor is num) return valor.toInt();
    return int.tryParse(valor?.toString() ?? '') ?? 0;
  }

  int get goles => _entero(jugador['goles']);
  int get amarillas => _entero(jugador['tarjeta_amarilla']);
  int get rojas => _entero(jugador['tarjeta_roja']);

  @override
  Widget build(BuildContext context) {
    final avatarRadius = 26.0 * scale;
    final ringColor = esFigura ? const Color(0xFFFFC107) : Colors.white;

    return SizedBox(
      width: 78 * scale,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Stack(
            clipBehavior: Clip.none,
            alignment: Alignment.center,
            children: [
              Container(
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  border: Border.all(color: ringColor, width: 2.5 * scale),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.25),
                      blurRadius: 6,
                      offset: const Offset(0, 2),
                    ),
                  ],
                ),
                child: CircleAvatar(
                  radius: avatarRadius,
                  backgroundColor: Colors.grey.shade300,
                  backgroundImage: _fotoUrl != null ? NetworkImage(_fotoUrl!) : null,
                  child: _fotoUrl == null
                      ? Icon(Icons.person, size: avatarRadius, color: Colors.grey.shade600)
                      : null,
                ),
              ),
              if (goles > 0) _badgeGoles(),
              if (rojas > 0)
                _badgeTarjetas(const Color(0xFFD32F2F), 1)
              else if (amarillas > 0)
                // A second yellow means a sending off, so two is the ceiling.
                _badgeTarjetas(const Color(0xFFFBC02D), amarillas.clamp(1, 2)),
              if (jugador['capitan'] == true) _badgeCapitan(),
            ],
          ),
          SizedBox(height: 5 * scale),
          Text(
            apellido,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 12 * scale,
              fontWeight: FontWeight.w600,
              color: onField ? Colors.white : Colors.black87,
              shadows: onField
                  ? const [
                      Shadow(color: Colors.black54, blurRadius: 3, offset: Offset(0, 1)),
                    ]
                  : null,
            ),
          ),
        ],
      ),
    );
  }

  String? get _fotoUrl {
    final foto = jugador['foto'];
    if (foto is String && foto.isNotEmpty) return foto;
    return null;
  }

  Widget _badgeGoles() {
    return Positioned(
      top: -5 * scale,
      right: -5 * scale,
      child: Container(
        padding: EdgeInsets.symmetric(horizontal: 6 * scale, vertical: 4 * scale),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.25),
              blurRadius: 4,
              offset: const Offset(0, 2),
            ),
          ],
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.sports_soccer, size: 15 * scale, color: Colors.black87),
            if (goles > 1)
              Padding(
                padding: EdgeInsets.only(left: 3 * scale),
                child: Text(
                  '$goles',
                  style: TextStyle(fontSize: 12 * scale, fontWeight: FontWeight.bold),
                ),
              ),
          ],
        ),
      ),
    );
  }

  /// A booking badge. A double yellow is drawn as two overlapping cards offset
  /// diagonally — the white outline of each keeps them readable as two.
  Widget _badgeTarjetas(Color color, int cantidad) {
    final ancho = 12 * scale;
    final alto = 17 * scale;
    final desplazamiento = 5 * scale;

    Widget tarjeta() => Container(
          width: ancho,
          height: alto,
          decoration: BoxDecoration(
            color: color,
            borderRadius: BorderRadius.circular(2.5),
            border: Border.all(color: Colors.white, width: 1.2),
          ),
        );

    return Positioned(
      right: -5 * scale,
      bottom: 6 * scale,
      child: cantidad == 1
          ? tarjeta()
          : SizedBox(
              width: ancho + desplazamiento,
              height: alto + desplazamiento,
              child: Stack(
                children: [
                  // The card behind peeks out from the lower left.
                  Positioned(left: 0, bottom: 0, child: tarjeta()),
                  Positioned(right: 0, top: 0, child: tarjeta()),
                ],
              ),
            ),
    );
  }

  Widget _badgeCapitan() {
    return Positioned(
      top: -5 * scale,
      left: -5 * scale,
      child: Container(
        width: 23 * scale,
        height: 23 * scale,
        decoration: BoxDecoration(
          color: Colors.amber.shade800,
          shape: BoxShape.circle,
          border: Border.all(color: Colors.white, width: 1.8),
        ),
        child: Center(
          child: Text(
            'C',
            style: TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.bold,
              fontSize: 13 * scale,
            ),
          ),
        ),
      ),
    );
  }
}

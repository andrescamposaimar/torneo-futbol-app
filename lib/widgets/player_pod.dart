import 'package:flutter/material.dart';

class PlayerPod extends StatelessWidget {
  final Map<String, dynamic> jugador;

  const PlayerPod({super.key, required this.jugador});

  String get apellido {
    final nombreCompleto = jugador['nombre']?.toString() ?? '';
    final partes = nombreCompleto.split(',');
    return partes.first.trim();
  }

  bool get esFigura => jugador['figura'] == true;
  int get goles => (jugador['goles'] ?? 0) is int ? jugador['goles'] : int.tryParse(jugador['goles'].toString()) ?? 0;
  int get amarillas => (jugador['tarjeta_amarilla'] ?? 0) is int ? jugador['tarjeta_amarilla'] : int.tryParse(jugador['tarjeta_amarilla'].toString()) ?? 0;
  int get rojas => (jugador['tarjeta_roja'] ?? 0) is int ? jugador['tarjeta_roja'] : int.tryParse(jugador['tarjeta_roja'].toString()) ?? 0;
  String get puntajeStr => _formatearPuntaje(jugador['puntaje']);

  String _formatearPuntaje(dynamic valor) {
    if (valor == null || valor is bool) return '-';
    if (valor is num) {
      return valor.toStringAsFixed(valor.truncateToDouble() == valor ? 0 : 1);
    }
    if (valor is String) {
      final normalizado = valor.replaceAll(',', '.');
      final numParsed = double.tryParse(normalizado);
      if (numParsed != null) {
        return numParsed.toStringAsFixed(numParsed.truncateToDouble() == numParsed ? 0 : 1);
      }
    }
    return '-';
  }

  @override
  Widget build(BuildContext context) {
    final borderColor = esFigura ? Colors.blue : Colors.white;

    return Column(
      children: [
        Stack(
          clipBehavior: Clip.none,
          children: [
            Container(
              padding: const EdgeInsets.all(2),
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                border: Border.all(color: borderColor, width: 3),
              ),
              child: CircleAvatar(
                radius: 32,
                backgroundImage: jugador['foto'] != null && jugador['foto'].toString().isNotEmpty
                    ? NetworkImage(jugador['foto'])
                    : null,
                backgroundColor: Colors.grey[300],
                child: jugador['foto'] == null ? const Icon(Icons.person, size: 30) : null,
              ),
            ),
            // Goles arriba derecha
            if (goles > 0)
              Positioned(
                top: -6,
                right: -6,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(12),
                    boxShadow: [
                      BoxShadow(color: Colors.black.withValues(alpha: 0.1), blurRadius: 4, offset: const Offset(0, 2)),
                    ],
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.sports_soccer, size: 14, color: Colors.black),
                      if (goles > 1)
                        Padding(
                          padding: const EdgeInsets.only(left: 4),
                          child: Text(
                            goles.toString(),
                            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            // Amarilla o roja derecha media
            if (rojas > 0)
              const Positioned(
                right: -4,
                top: 24,
                child: Icon(Icons.square, color: Colors.red, size: 16),
              )
            else if (amarillas > 0)
              const Positioned(
                right: -4,
                top: 24,
                child: Icon(Icons.square, color: Colors.amber, size: 16),
              ),
            // Capitán: ícono arriba izquierda
            if (jugador['capitan'] == true)
              Positioned(
                top: -6,
                left: -6,
                child: Container(
                  width: 24,
                  height: 24,
                  decoration: BoxDecoration(
                    color: Colors.amber.shade800,
                    shape: BoxShape.circle,
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withValues(alpha: 0.3),
                        blurRadius: 3,
                        offset: const Offset(0, 2),
                      ),
                    ],
                  ),
                  child: const Center(
                    child: Text(
                      'C',
                      style: TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.bold,
                        fontSize: 14,
                      ),
                    ),
                  ),
                ),
              ),
          ],
        ),
        const SizedBox(height: 4),
        Text(apellido, style: const TextStyle(fontSize: 12), textAlign: TextAlign.center),
      ],
    );
  }
}

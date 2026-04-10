import 'package:flutter/material.dart';

class MatchCard extends StatelessWidget {
  final Map<String, dynamic> partido;
  final VoidCallback onVerDetalle;

  const MatchCard({super.key, required this.partido, required this.onVerDetalle});

  Widget _teamRow(String nombre, String goles, dynamic escudo) {
    return Row(
      children: [
        if (escudo != null && escudo is String && escudo.isNotEmpty)
          Image.network(escudo, height: 24, width: 24, fit: BoxFit.contain)
        else
          const Icon(Icons.shield, size: 20, color: Colors.grey),
        const SizedBox(width: 8),
        Expanded(child: Text(nombre, style: const TextStyle(fontSize: 16))),
        Text(goles, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final liga = partido['liga']?.toString() ?? '';
    final local = partido['equipo_local']?.toString() ?? 'Local';
    final visitante = partido['equipo_visitante']?.toString() ?? 'Visitante';
    final golesLocal = partido['goles_local']?.toString() ?? '-';
    final golesVisitante = partido['goles_visitante']?.toString() ?? '-';
    final escudoLocal = partido['escudo_local'];
    final escudoVisitante = partido['escudo_visitante'];

    return Card(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      elevation: 2,
      margin: const EdgeInsets.symmetric(vertical: 6, horizontal: 12),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(liga, style: const TextStyle(fontWeight: FontWeight.w500, fontSize: 15)),
            const Divider(height: 20, thickness: 1, color: Color(0xFFE0E0E0)),
            _teamRow(local, golesLocal, escudoLocal),
            const SizedBox(height: 8),
            _teamRow(visitante, golesVisitante, escudoVisitante),
            const SizedBox(height: 12),
            Align(
              alignment: Alignment.centerRight,
              child: TextButton(
                onPressed: onVerDetalle,
                child: const Text('Ver detalle'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

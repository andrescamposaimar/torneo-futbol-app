import 'package:flutter/material.dart';

import '../utils/text_utils.dart';

/// A match in a list.
///
/// Single source of truth for the row shown in the matches list and in a
/// team's match history, so the two can never drift apart again.
///
/// [mostrarResultado] switches between the two shapes: a played match puts the
/// score next to each team, while a scheduled one replaces it with kickoff
/// details. [onTap] being null makes the card inert and hides the chevron —
/// there is no affordance for a screen that cannot open the detail.
class MatchCard extends StatelessWidget {
  final Map<String, dynamic> partido;
  final VoidCallback? onTap;
  final bool mostrarResultado;

  const MatchCard({
    super.key,
    required this.partido,
    required this.mostrarResultado,
    this.onTap,
  });

  static String _texto(dynamic valor) {
    final decodificado = decodeHtmlEntities(valor?.toString());
    return decodificado.isEmpty ? '-' : decodificado;
  }

  static String _goles(dynamic valor) {
    if (valor == null || valor.toString().trim().isEmpty) return '-';
    return valor.toString();
  }

  /// `2026-08-08` → `08-08-26`.
  static String _fechaCorta(String fechaOriginal) {
    final partes = fechaOriginal.split('-');
    if (partes.length != 3 || partes[0].length < 4) return fechaOriginal;
    return '${partes[2]}-${partes[1]}-${partes[0].substring(2)}';
  }

  @override
  Widget build(BuildContext context) {
    final primary = Theme.of(context).colorScheme.primary;

    final liga = _texto(partido['liga']);
    final local = _texto(partido['equipo_local']);
    final visitante = _texto(partido['equipo_visitante']);
    final escudoLocal = partido['escudo_local']?.toString();
    final escudoVisitante = partido['escudo_visitante']?.toString();
    final mesa = partido['mesa']?.toString() ?? '';

    return Container(
      margin: const EdgeInsets.symmetric(vertical: 5, horizontal: 12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        // A hairline instead of a drop shadow: the list stays calm when many
        // cards are stacked.
        border: Border.all(color: Colors.grey.shade200),
      ),
      clipBehavior: Clip.antiAlias,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // A tinted strip carries the league and breaks the flat white.
              Container(
                width: double.infinity,
                color: primary.withValues(alpha: 0.05),
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
                child: Text(
                  liga.toUpperCase(),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 0.6,
                    color: primary.withValues(alpha: 0.85),
                  ),
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.center,
                  children: [
                    Expanded(
                      flex: 3,
                      child: Column(
                        children: [
                          _teamRow(local, escudoLocal,
                              mostrarResultado ? _goles(partido['goles_local']) : ''),
                          const SizedBox(height: 10),
                          _teamRow(visitante, escudoVisitante,
                              mostrarResultado
                                  ? _goles(partido['goles_visitante'])
                                  : ''),
                        ],
                      ),
                    ),
                    if (!mostrarResultado)
                      Expanded(
                        flex: 2,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.end,
                          children: [
                            _metaRow(Icons.calendar_today,
                                _fechaCorta(partido['fecha']?.toString() ?? '-')),
                            _metaRow(Icons.access_time,
                                partido['hora']?.toString() ?? '-'),
                            _metaRow(Icons.location_on, _texto(partido['cancha'])),
                            if (mesa.isNotEmpty)
                              _metaRow(Icons.groups, 'Mesa: $mesa'),
                          ],
                        ),
                      ),
                    if (onTap != null) ...[
                      const SizedBox(width: 14),
                      // A tinted disc reads as an affordance; a bare chevron
                      // did not make the card feel tappable.
                      Container(
                        width: 30,
                        height: 30,
                        decoration: BoxDecoration(
                          color: primary.withValues(alpha: 0.08),
                          shape: BoxShape.circle,
                        ),
                        child: Icon(Icons.chevron_right,
                            size: 20, color: primary.withValues(alpha: 0.8)),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _teamRow(String nombre, String? escudoUrl, String goles) {
    final placeholder = Icon(Icons.shield, size: 20, color: Colors.grey.shade400);

    return Row(
      children: [
        SizedBox(
          width: 26,
          height: 26,
          child: escudoUrl != null &&
                  escudoUrl.isNotEmpty &&
                  Uri.tryParse(escudoUrl)?.hasScheme == true
              ? Image.network(
                  escudoUrl,
                  fit: BoxFit.contain,
                  errorBuilder: (context, error, stackTrace) => placeholder,
                )
              : placeholder,
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            nombre,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.w500,
              color: Colors.black87,
            ),
          ),
        ),
        if (goles.isNotEmpty)
          Container(
            margin: const EdgeInsets.only(left: 10),
            width: 28,
            height: 26,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: Colors.grey.shade100,
              borderRadius: BorderRadius.circular(7),
            ),
            child: Text(
              goles,
              style: const TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.w700,
                color: Colors.black87,
              ),
            ),
          ),
      ],
    );
  }

  /// Secondary match data — date, kickoff, venue. Muted on purpose so it never
  /// competes with the teams.
  Widget _metaRow(IconData icono, String texto) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icono, size: 14, color: Colors.grey.shade500),
          const SizedBox(width: 5),
          Flexible(
            child: Text(
              texto,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
            ),
          ),
        ],
      ),
    );
  }
}

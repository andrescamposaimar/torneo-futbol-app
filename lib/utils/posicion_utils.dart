import 'package:flutter/material.dart';

/// Retorna la abreviatura de la posición para mostrar en etiquetas.
/// 'mediocampista' → 'Medio.', vacío o 'sin posicion' → 'Sin Cargar', resto sin cambios.
String posicionAbreviada(String rawPos) {
  if (rawPos.isEmpty || rawPos.toLowerCase() == 'sin posicion') return 'Sin Cargar';
  if (rawPos.toLowerCase() == 'mediocampista') return 'Medio.';
  return rawPos;
}

/// Retorna el color de fondo para la etiqueta de posición.
/// Recibe la posición ya abreviada (resultado de [posicionAbreviada]).
Color posicionColor(String posicion) {
  switch (posicion.toLowerCase()) {
    case 'arquero':
      return Colors.cyan.shade700;
    case 'defensor':
      return Colors.indigo.shade600;
    case 'medio.':
      return Colors.orange.shade600;
    case 'delantero':
      return Colors.red.shade600;
    case 'sin cargar':
      return Colors.grey.shade600;
    default:
      return Colors.grey.shade400;
  }
}

/// Orden numérico de posiciones para ordenar listas de jugadores.
/// Posiciones no incluidas reciben 99 por defecto.
const Map<String, int> ordenPosiciones = {
  'Arquero': 1,
  'Defensor': 2,
  'Mediocampista': 3,
  'Delantero': 4,
};

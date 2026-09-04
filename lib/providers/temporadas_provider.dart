import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/temporada.dart';
import 'repository_providers.dart';

final temporadasProvider = FutureProvider<List<dynamic>>((ref) async {
  return ref.read(apiRepositoryProvider).getTemporadas();
});

final temporadaActualProvider = FutureProvider<Temporada>((ref) async {
  final lista = await ref.read(temporadasProvider.future);
  final temporadas = lista.map((t) => Temporada.fromJson(t)).toList();
  if (temporadas.isEmpty) {
    throw StateError(
      'No hay temporadas disponibles: la respuesta de /temporadas '
      '(o la caché) está vacía.',
    );
  }
  return temporadas.firstWhere(
    (t) => t.isCurrent,
    orElse: () => temporadas.first,
  );
});

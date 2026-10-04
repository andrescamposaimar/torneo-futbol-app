import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/jugador.dart';
import 'package:torneo_futbol_app/widgets/cambios_jugador_card.dart';

Widget _wrap(Widget child) => MaterialApp(home: Scaffold(body: child));

Jugador _jugadorConFoto(String imagen) => Jugador(
      id: 1,
      nombre: 'Juan Pérez',
      imagen: imagen,
      posicion: 'Delantero',
      puntaje: 3.0,
      equipo: 'Equipo A',
      escudo: '',
      temporadas: const [],
      raw: const {},
    );

void main() {
  group('CambiosJugadorCard — foto', () {
    testWidgets('a null jugador (fetch pending/failed) renders the person icon', (tester) async {
      await tester.pumpWidget(_wrap(
        const CambiosJugadorCard(playerId: 1, nombre: 'Juan Pérez'),
      ));

      expect(find.byIcon(Icons.person), findsOneWidget);
      final avatar = tester.widget<CircleAvatar>(find.byType(CircleAvatar));
      expect(avatar.foregroundImage, isNull);
    });

    /// FIX 6 (avatar fallback on a FAILED load) applied to THIS card too —
    /// see `CambiosAvatar`'s own docblock, and
    /// `cambios_candidato_card_test.dart`'s equivalent test for
    /// `CambiosCandidatoCard`: both cards shared the identical pre-existing
    /// gap (`backgroundImage` alone, no fallback on a 404/deleted
    /// attachment), so both must share the identical fix.
    testWidgets(
        'a jugador with a photo wires a NetworkImage as the foreground image, with the person '
        'icon still underneath as the fallback for a failed load', (tester) async {
      final originalOnError = FlutterError.onError;
      FlutterError.onError = (details) {
        if (details.exception is NetworkImageLoadException) return;
        originalOnError?.call(details);
      };

      try {
        await tester.pumpWidget(_wrap(
          CambiosJugadorCard(
            playerId: 1,
            nombre: 'Juan Pérez',
            jugador: _jugadorConFoto('https://entreredespadres.com.ar/foto-1.jpg'),
          ),
        ));
        await tester.pump();

        final avatar = tester.widget<CircleAvatar>(find.byType(CircleAvatar));
        final foregroundImage = avatar.foregroundImage;
        expect(foregroundImage, isA<NetworkImage>());
        expect(
            (foregroundImage as NetworkImage).url, 'https://entreredespadres.com.ar/foto-1.jpg');
        expect(avatar.onForegroundImageError, isNotNull);
        expect(find.byIcon(Icons.person), findsOneWidget);
      } finally {
        FlutterError.onError = originalOnError;
      }
    });
  });
}

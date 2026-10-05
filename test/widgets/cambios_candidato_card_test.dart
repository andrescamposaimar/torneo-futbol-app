import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/widgets/cambios_candidato_card.dart';
import 'package:torneo_futbol_app/widgets/cambios_jugador_card.dart';

Widget _wrap(Widget child, {Size size = const Size(375, 667)}) {
  return MediaQuery(
    data: MediaQueryData(size: size),
    child: MaterialApp(
      home: Scaffold(
        body: SizedBox(width: size.width, child: child),
      ),
    ),
  );
}

void main() {
  group('CambiosCandidatoCard — badge', () {
    testWidgets('a padre candidate shows the "Padre" badge, not "Invitado"', (tester) async {
      await tester.pumpWidget(_wrap(
        CambiosCandidatoCard(
          playerId: 1,
          nombre: 'Juan Pérez',
          esPadre: true,
          puntaje: 4.5,
          selected: false,
          onTap: () {},
        ),
      ));

      expect(find.text('Padre'), findsOneWidget);
      expect(find.text('Invitado'), findsNothing);
    });

    testWidgets('a non-padre candidate shows the "Invitado" badge, not "Padre"', (tester) async {
      await tester.pumpWidget(_wrap(
        CambiosCandidatoCard(
          playerId: 2,
          nombre: 'Nico del Padrón',
          esPadre: false,
          puntaje: 3.0,
          selected: false,
          onTap: () {},
        ),
      ));

      expect(find.text('Invitado'), findsOneWidget);
      expect(find.text('Padre'), findsNothing);
    });

    testWidgets('"Padre" and "Invitado" render with different colors (not identical grey pills)',
        (tester) async {
      await tester.pumpWidget(_wrap(
        CambiosCandidatoCard(
          playerId: 1,
          nombre: 'Padre Uno',
          esPadre: true,
          puntaje: 4.5,
          selected: false,
          onTap: () {},
        ),
      ));
      final padreContainer = tester.widget<Container>(
        find.descendant(of: find.byType(CambiosBadge), matching: find.byType(Container)),
      );
      final padreDecoration = padreContainer.decoration as BoxDecoration;

      await tester.pumpWidget(_wrap(
        CambiosCandidatoCard(
          playerId: 2,
          nombre: 'Invitado Uno',
          esPadre: false,
          puntaje: 3.0,
          selected: false,
          onTap: () {},
        ),
      ));
      final invitadoContainer = tester.widget<Container>(
        find.descendant(of: find.byType(CambiosBadge), matching: find.byType(Container)),
      );
      final invitadoDecoration = invitadoContainer.decoration as BoxDecoration;

      expect(padreDecoration.color, isNot(equals(invitadoDecoration.color)));
    });

    testWidgets('no RenderFlex overflow with a long name at a narrow phone width', (tester) async {
      await tester.pumpWidget(_wrap(
        CambiosCandidatoCard(
          playerId: 3,
          nombre: 'Rogel, Gaston Alejandro',
          esPadre: false,
          puntaje: 4.5,
          selected: false,
          onTap: () {},
        ),
        size: const Size(320, 667),
      ));

      expect(tester.takeException(), isNull);
      // The overflow indicator paints a distinctive yellow/black stripe
      // widget when a RenderFlex overflows — its absence is the other half
      // of this guard (a caught exception alone would already fail the
      // test, but this also catches a RenderFlex that merely logs instead
      // of throwing).
      expect(find.byWidgetPredicate((w) => w.runtimeType.toString() == 'ErrorWidget'),
          findsNothing);

      final nombreFinder = find.text('Rogel, Gaston Alejandro');
      expect(nombreFinder, findsOneWidget);
      expect(find.text('Invitado'), findsOneWidget);
    });

    testWidgets('long name ellipsizes and the badge keeps its own width (never squeezed)',
        (tester) async {
      await tester.pumpWidget(_wrap(
        CambiosCandidatoCard(
          playerId: 3,
          nombre: 'Rogel, Gaston Alejandro',
          esPadre: false,
          puntaje: 4.5,
          selected: false,
          onTap: () {},
        ),
        size: const Size(320, 667),
      ));

      final nombreText = tester.widget<Text>(find.text('Rogel, Gaston Alejandro'));
      expect(nombreText.overflow, TextOverflow.ellipsis);

      // The badge's rendered width should be unaffected by the name's
      // length — it renders at its natural (unsqueezed) size either way.
      final badgeWidthNarrow = tester.getSize(find.text('Invitado')).width;

      await tester.pumpWidget(_wrap(
        CambiosCandidatoCard(
          playerId: 4,
          nombre: 'Jo',
          esPadre: false,
          puntaje: 4.5,
          selected: false,
          onTap: () {},
        ),
        size: const Size(320, 667),
      ));
      final badgeWidthShortName = tester.getSize(find.text('Invitado')).width;

      expect(badgeWidthNarrow, closeTo(badgeWidthShortName, 0.5));
    });
  });

  group('CambiosCandidatoCard — foto', () {
    testWidgets('no fotoUrl renders the person icon, never a NetworkImage', (tester) async {
      await tester.pumpWidget(_wrap(
        CambiosCandidatoCard(
          playerId: 1,
          nombre: 'Sin Foto',
          esPadre: false,
          puntaje: 3.0,
          selected: false,
          onTap: () {},
        ),
      ));

      expect(find.byIcon(Icons.person), findsOneWidget);
      final avatar = tester.widget<CircleAvatar>(find.byType(CircleAvatar));
      expect(avatar.foregroundImage, isNull);
      expect(avatar.onForegroundImageError, isNull);
    });

    testWidgets('an empty fotoUrl also renders the person icon, same as null', (tester) async {
      await tester.pumpWidget(_wrap(
        CambiosCandidatoCard(
          playerId: 1,
          nombre: 'Foto Vacia',
          esPadre: false,
          puntaje: 3.0,
          fotoUrl: '',
          selected: false,
          onTap: () {},
        ),
      ));

      expect(find.byIcon(Icons.person), findsOneWidget);
    });

    /// FIX 6 (avatar fallback on a FAILED load): `CambiosAvatar` wires the
    /// photo through `foregroundImage` + a `child` rendered UNDERNEATH it
    /// (the same idiom as `CampeonAvatar`/`ProdeIdentityCard`'s own avatar)
    /// instead of the old `backgroundImage`-only approach, which left an
    /// EMPTY circle forever on a 404 or deleted attachment — there was no
    /// `child` to fall back to. Here the person icon is in the tree
    /// regardless of whether the photo ever loads, and
    /// `onForegroundImageError` is a non-null no-op that swallows a failed
    /// load instead of crashing.
    testWidgets(
        'a fotoUrl wires a NetworkImage as the foreground image, with the person icon still '
        'underneath as the fallback for a failed load', (tester) async {
      // Flutter's test binding intercepts every HTTP call and returns 400,
      // so NetworkImage always fails to decode here — same suppression
      // `prode_identity_card_test.dart`/`campeon_avatar_test.dart` already
      // use for their own photo avatar tests.
      final originalOnError = FlutterError.onError;
      FlutterError.onError = (details) {
        if (details.exception is NetworkImageLoadException) return;
        originalOnError?.call(details);
      };

      try {
        await tester.pumpWidget(_wrap(
          CambiosCandidatoCard(
            playerId: 1,
            nombre: 'Con Foto',
            esPadre: false,
            puntaje: 3.0,
            fotoUrl: 'https://entreredespadres.com.ar/foto-1.jpg',
            selected: false,
            onTap: () {},
          ),
        ));
        await tester.pump();

        final avatar = tester.widget<CircleAvatar>(find.byType(CircleAvatar));
        final foregroundImage = avatar.foregroundImage;
        expect(foregroundImage, isA<NetworkImage>());
        expect(
            (foregroundImage as NetworkImage).url, 'https://entreredespadres.com.ar/foto-1.jpg');

        // The failure-swallowing callback must be wired...
        expect(avatar.onForegroundImageError, isNotNull);
        // ...and the person icon must still be in the tree as the
        // underlying fallback — never an empty circle.
        expect(find.byIcon(Icons.person), findsOneWidget);
      } finally {
        FlutterError.onError = originalOnError;
      }
    });
  });

  group('CambiosCandidatoCard — posicion', () {
    testWidgets('renders the abbreviated posicion text via CambiosPosicionBadge', (tester) async {
      await tester.pumpWidget(_wrap(
        CambiosCandidatoCard(
          playerId: 1,
          nombre: 'Juan Pérez',
          esPadre: false,
          puntaje: 4.5,
          posicion: 'Mediocampista',
          selected: false,
          onTap: () {},
        ),
      ));

      // posicionAbreviada('Mediocampista') == 'Medio.' — see posicion_utils.dart.
      expect(find.text('Medio.'), findsOneWidget);
      expect(find.byType(CambiosPosicionBadge), findsOneWidget);
    });

    testWidgets('an empty/unresolved posicion renders "Sin Cargar", never a blank badge',
        (tester) async {
      await tester.pumpWidget(_wrap(
        CambiosCandidatoCard(
          playerId: 1,
          nombre: 'Juan Pérez',
          esPadre: false,
          puntaje: 4.5,
          selected: false,
          onTap: () {},
        ),
      ));

      expect(find.text('Sin Cargar'), findsOneWidget);
    });

    testWidgets('the SAME posicion renders the SAME badge color CambiosJugadorCard uses '
        '(shared CambiosPosicionBadge, never a second color map)', (tester) async {
      await tester.pumpWidget(_wrap(
        CambiosCandidatoCard(
          playerId: 1,
          nombre: 'Juan Pérez',
          esPadre: false,
          puntaje: 4.5,
          posicion: 'Arquero',
          selected: false,
          onTap: () {},
        ),
      ));

      final badge = tester.widget<CambiosPosicionBadge>(find.byType(CambiosPosicionBadge));
      expect(badge.posicion, 'Arquero');

      final strip = tester.widget<Container>(
        find.descendant(
          of: find.byType(CambiosPosicionBadge),
          matching: find.byWidgetPredicate((w) => w is Container && w.decoration is BoxDecoration),
        ),
      );
      final decoration = strip.decoration as BoxDecoration;
      expect(decoration.color, Colors.cyan.shade700, reason: 'Arquero is cyan — posicionColor().');
    });

    testWidgets(
        'no RenderFlex overflow at a narrow phone width with BOTH a long name and the position '
        'badge rendered together', (tester) async {
      await tester.pumpWidget(_wrap(
        CambiosCandidatoCard(
          playerId: 3,
          nombre: 'Rogel, Gaston Alejandro',
          esPadre: false,
          puntaje: 4.5,
          posicion: 'Mediocampista',
          selected: false,
          onTap: () {},
        ),
        size: const Size(320, 667),
      ));

      expect(tester.takeException(), isNull);
      expect(find.byWidgetPredicate((w) => w.runtimeType.toString() == 'ErrorWidget'),
          findsNothing);
      expect(find.text('Medio.'), findsOneWidget);
      expect(find.text('Rogel, Gaston Alejandro'), findsOneWidget);
    });
  });
}

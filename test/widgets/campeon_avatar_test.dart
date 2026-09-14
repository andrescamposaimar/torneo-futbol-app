import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/widgets/campeon_avatar.dart';

Future<void> _pump(
  WidgetTester tester, {
  required String nombre,
  String? fotoUrl,
}) async {
  await tester.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: CampeonAvatar(nombre: nombre, fotoUrl: fotoUrl),
      ),
    ),
  );
}

void main() {
  group('CampeonAvatar · fallback selection', () {
    testWidgets('a null fotoUrl renders the initials variant', (tester) async {
      await _pump(tester, nombre: 'BASSO, A.');

      expect(
        find.byKey(const ValueKey('campeon_avatar_initials_BASSO, A.')),
        findsOneWidget,
      );
      expect(find.text('BA'), findsOneWidget);
      final avatar = tester.widget<CircleAvatar>(find.byType(CircleAvatar));
      expect(avatar.foregroundImage, isNull);
      expect(avatar.onForegroundImageError, isNull);
    });

    testWidgets('an empty fotoUrl renders the initials variant', (tester) async {
      await _pump(tester, nombre: 'BASSO, A.', fotoUrl: '');

      expect(
        find.byKey(const ValueKey('campeon_avatar_initials_BASSO, A.')),
        findsOneWidget,
      );
    });

    testWidgets('a non-empty fotoUrl renders the photo variant', (tester) async {
      await _pump(
        tester,
        nombre: 'BASSO, A.',
        fotoUrl: 'https://example.com/basso.jpg',
      );

      expect(
        find.byKey(const ValueKey('campeon_avatar_photo_BASSO, A.')),
        findsOneWidget,
      );
      final avatar = tester.widget<CircleAvatar>(find.byType(CircleAvatar));
      expect(avatar.foregroundImage, isA<NetworkImage>());
      // A failed network load must not crash the widget — the error
      // callback is present and swallows the error, leaving the initials
      // (rendered underneath via `child`) visible.
      expect(avatar.onForegroundImageError, isNotNull);
      // The initials are still built underneath, even on the photo
      // variant — they show while the image loads or if it fails.
      expect(find.text('BA'), findsOneWidget);
    });
  });

  group('CampeonAvatar · APP-5 (no lesser rendering for the unlinked case)',
      () {
    testWidgets(
        'the photo and initials variants carry IDENTICAL radius, background '
        'alpha and text style — CampeonAvatar has no way to know which rows '
        'are linked, so it cannot mark one as lesser', (tester) async {
      await _pump(tester, nombre: 'MAZZARA, M.', fotoUrl: null);
      final initialsAvatar =
          tester.widget<CircleAvatar>(find.byType(CircleAvatar));
      final initialsText = tester.widget<Text>(find.text('MM'));

      await _pump(
        tester,
        nombre: 'MAZZARA, M.',
        fotoUrl: 'https://example.com/mazzara.jpg',
      );
      final photoAvatar =
          tester.widget<CircleAvatar>(find.byType(CircleAvatar));
      final photoText = tester.widget<Text>(find.text('MM'));

      expect(photoAvatar.radius, initialsAvatar.radius);
      expect(
        (photoAvatar.backgroundColor as Color).a,
        (initialsAvatar.backgroundColor as Color).a,
      );
      expect(photoText.style?.fontWeight, initialsText.style?.fontWeight);
      expect(photoText.style?.fontSize, initialsText.style?.fontSize);
      expect(photoText.style?.color, initialsText.style?.color);
    });
  });

  group('CampeonAvatar · semantics', () {
    testWidgets('is excluded from semantics — the name is read beside it',
        (tester) async {
      await _pump(tester, nombre: 'BASSO, A.');

      expect(
        find.descendant(
          of: find.byType(CampeonAvatar),
          matching: find.byType(ExcludeSemantics),
        ),
        findsOneWidget,
      );
    });
  });
}

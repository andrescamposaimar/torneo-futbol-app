import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/credencial.dart';
import 'package:torneo_futbol_app/services/credencial_state.dart';
import 'package:torneo_futbol_app/widgets/credencial_card.dart';
import 'package:torneo_futbol_app/widgets/rotating_code_view.dart';

void main() {
  group('formatDni', () {
    test('groups an 8-digit DNI by three from the right', () {
      expect(formatDni('29392780'), '29.392.780');
    });

    test('groups a 7-digit DNI by three from the right', () {
      expect(formatDni('1234567'), '1.234.567');
    });

    test('keeps a leading zero (a DNI is an identifier, not a number)', () {
      expect(formatDni('01234567'), '01.234.567');
    });

    test('returns non-digit input unchanged', () {
      expect(formatDni('ABC123'), 'ABC123');
    });

    test('returns an empty string unchanged', () {
      expect(formatDni(''), '');
    });
  });

  group('CredencialCard', () {
    Credencial credencial({
      String? birthDate,
      String? caracter,
      CredencialTeam? team,
      String dni = '30111222',
    }) {
      return Credencial(
        id: 'cred-1',
        issuedAt: '2026-09-29 00:00:00',
        expiresAt: '2027-09-29 00:00:00',
        playerId: 1,
        fullName: 'Juan Perez',
        dni: dni,
        birthDate: birthDate,
        caracter: caracter,
        team: team,
        photo: const CredencialPhoto(id: 1, url: 'https://example.com/p.jpg'),
        codeSeed: 'c2VlZA',
        code: const CredencialCode(alg: 'SHA256', step: 30, digits: 6),
      );
    }

    /// A 1x1 transparent PNG — the smallest byte sequence `Image.memory` can
    /// actually decode, so every test below exercises the real
    /// (non-nullable) [CredencialCard.photoBytes] contract instead of a
    /// placeholder.
    Uint8List validPngBytes() => Uint8List.fromList([
          137, 80, 78, 71, 13, 10, 26, 10, 0, 0, 0, 13, 73, 72, 68, 82, 0, 0,
          0, 1, 0, 0, 0, 1, 8, 6, 0, 0, 0, 31, 21, 196, 137, 0, 0, 0, 10, 73,
          68, 65, 84, 120, 156, 99, 0, 1, 0, 0, 5, 0, 1, 13, 10, 45, 180, 0, 0,
          0, 0, 73, 69, 78, 68, 174, 66, 96, 130, //
        ]);

    Future<void> pumpCard(
      WidgetTester tester,
      Credencial credential, {
      CredencialReplacementStatus replacement = CredencialReplacementStatus.none,
      bool stale = false,
      Uint8List? photoBytes,
      Size size = const Size(390, 844),
      double textScale = 1.0,
    }) {
      tester.view.physicalSize = size;
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      return tester.pumpWidget(
        MediaQuery(
          data: MediaQueryData(textScaler: TextScaler.linear(textScale)),
          child: MaterialApp(
            home: Scaffold(
              body: CredencialCard(
                credential: credential,
                replacement: replacement,
                stale: stale,
                photoBytes: photoBytes ?? validPngBytes(),
                now: () => DateTime(2026, 9, 30),
              ),
            ),
          ),
        ),
      );
    }

    testWidgets('renders full name, formatted DNI and the rotating code view',
        (tester) async {
      await pumpCard(tester, credencial());

      expect(find.text('Juan Perez'), findsOneWidget);
      expect(find.textContaining('30.111.222'), findsOneWidget);
      expect(find.byType(RotatingCodeView), findsOneWidget);
    });

    testWidgets('computes and shows age from birth_date', (tester) async {
      // now = 2026-09-30; born 2000-01-01 → 26 years old.
      await pumpCard(tester, credencial(birthDate: '2000-01-01 00:00:00'));

      expect(find.textContaining('26 años'), findsOneWidget);
    });

    testWidgets('birth_date null → renders without an age line, no crash',
        (tester) async {
      await pumpCard(tester, credencial(birthDate: null));

      expect(find.textContaining('años'), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('shows the HABILITADO status chip with its icon',
        (tester) async {
      await pumpCard(tester, credencial());

      expect(find.text('HABILITADO'), findsOneWidget);
      expect(find.byIcon(Icons.verified), findsOneWidget);
    });

    testWidgets('caracter present → shown in the secondary line',
        (tester) async {
      await pumpCard(tester, credencial(caracter: 'Padre Alumno'));

      expect(find.textContaining('Padre Alumno'), findsOneWidget);
    });

    testWidgets(
        'caracter null → omitted without failing (spec: Caracter is empty)',
        (tester) async {
      await pumpCard(tester, credencial(caracter: null));

      expect(tester.takeException(), isNull);
    });

    testWidgets('team present → team name shown in the secondary line',
        (tester) async {
      await pumpCard(
        tester,
        credencial(
          team: const CredencialTeam(
              id: 1, name: 'Real Madrid', kind: CredencialTeamKind.team),
        ),
      );

      expect(find.textContaining('Real Madrid'), findsOneWidget);
    });

    testWidgets('waiting-list team kind → name still shown', (tester) async {
      await pumpCard(
        tester,
        credencial(
          team: const CredencialTeam(
            id: 2,
            name: 'Lista de Espera 2026',
            kind: CredencialTeamKind.waitingList,
          ),
        ),
      );

      expect(find.textContaining('Lista de Espera 2026'), findsOneWidget);
    });

    testWidgets(
        'caracter and team present → secondary line joins both with " · "',
        (tester) async {
      await pumpCard(
        tester,
        credencial(
          caracter: 'Padre Alumno',
          team: const CredencialTeam(
              id: 1, name: 'Real Madrid', kind: CredencialTeamKind.team),
        ),
      );

      expect(find.text('Padre Alumno · Real Madrid'), findsOneWidget);
    });

    testWidgets(
        'team null → renders without a team badge, no crash (spec: '
        'Team or list name unavailable)', (tester) async {
      await pumpCard(tester, credencial(team: null));

      expect(tester.takeException(), isNull);
    });

    testWidgets(
        'caracter AND team both null → no secondary line at all, no crash',
        (tester) async {
      await pumpCard(tester, credencial(caracter: null, team: null));

      expect(tester.takeException(), isNull);
    });

    testWidgets(
        'photoBytes → rendered via Image.memory, wider than half the screen '
        '(design: photo as protagonist, centered and large)', (tester) async {
      const screenWidth = 390.0;
      await pumpCard(
        tester,
        credencial(),
        photoBytes: validPngBytes(),
        size: const Size(screenWidth, 844),
      );

      expect(find.byType(Image), findsOneWidget);
      expect(find.byIcon(Icons.person), findsNothing);
      expect(tester.takeException(), isNull);

      final imageBox = tester.renderObject(find.byType(Image)) as RenderBox;
      expect(imageBox.size.width, greaterThan(screenWidth * 0.5));
    });

    testWidgets('photo carries an accessibility label with the full name',
        (tester) async {
      await pumpCard(tester, credencial());

      expect(find.bySemanticsLabel('Foto de Juan Perez'), findsOneWidget);
    });

    testWidgets('stale → shows an offline/last-verified banner',
        (tester) async {
      await pumpCard(tester, credencial(), stale: true);

      expect(find.textContaining('conexión'), findsOneWidget);
    });

    testWidgets('replacement pending → shows a review-in-progress banner',
        (tester) async {
      await pumpCard(
        tester,
        credencial(),
        replacement: CredencialReplacementStatus.pending,
      );

      expect(find.textContaining('revisión'), findsOneWidget);
    });

    testWidgets(
        'replacement rejected → shows a rejection banner with no reason given',
        (tester) async {
      await pumpCard(
        tester,
        credencial(),
        replacement: CredencialReplacementStatus.rejected,
      );

      expect(find.textContaining('rechazada'), findsOneWidget);
    });

    testWidgets(
        'never scrolls, even with two banners on a narrow, tall-text screen '
        '— design: banners take space from the photo, the photo is the '
        'only flexible child, nothing below it ever needs to scroll',
        (tester) async {
      await pumpCard(
        tester,
        credencial(),
        stale: true,
        replacement: CredencialReplacementStatus.pending,
        size: const Size(375, 667),
        textScale: 1.3,
      );

      expect(find.byType(Scrollable), findsNothing);
      expect(find.byType(RotatingCodeView), findsOneWidget);
      expect(tester.takeException(), isNull);

      final screen = Offset.zero & const Size(375, 667);
      final codeRect = tester.getRect(find.byType(RotatingCodeView));
      expect(screen.contains(codeRect.topLeft), isTrue);
      expect(screen.contains(codeRect.bottomRight), isTrue);
    });

    testWidgets('the Active card never contains a Scrollable', (tester) async {
      await pumpCard(tester, credencial());

      expect(find.byType(Scrollable), findsNothing);
    });

    testWidgets(
        'the photo grows to use leftover space: taller on a bigger screen '
        'than on a smaller one', (tester) async {
      await pumpCard(tester, credencial(), size: const Size(375, 667));
      final smallPhotoHeight =
          tester.getRect(find.byType(Image)).height;

      await pumpCard(tester, credencial(), size: const Size(430, 932));
      final bigPhotoHeight = tester.getRect(find.byType(Image)).height;

      expect(bigPhotoHeight, greaterThan(smallPhotoHeight));
    });

    group('no overflow across the device/text-scale matrix', () {
      const sizes = [
        Size(375, 667),
        Size(390, 844),
        Size(430, 932),
        Size(360, 640),
      ];

      for (final size in sizes) {
        for (final scale in [1.0, 1.3]) {
          Future<void> assertFitsWithoutScrolling(
            WidgetTester tester,
          ) async {
            expect(tester.takeException(), isNull);
            expect(find.byType(Scrollable), findsNothing);

            final screen = Offset.zero & size;
            final photoRect = tester.getRect(find.byType(Image));
            final codeRect = tester.getRect(find.byType(RotatingCodeView));

            expect(screen.contains(photoRect.topLeft), isTrue);
            expect(screen.contains(photoRect.bottomRight), isTrue);
            expect(screen.contains(codeRect.topLeft), isTrue);
            expect(screen.contains(codeRect.bottomRight), isTrue);

            final nameRect = tester.getRect(find.text('Juan Perez'));
            expect(photoRect.height, greaterThan(nameRect.height));
            expect(photoRect.height, greaterThan(codeRect.height));
          }

          testWidgets(
              '$size @ textScale $scale, full data, no banners',
              (tester) async {
            await pumpCard(
              tester,
              credencial(
                birthDate: '2000-01-01 00:00:00',
                caracter: 'Padre Ex-Alumno',
                team: const CredencialTeam(
                  id: 1,
                  name: 'Lista de No Inscriptos 2026',
                  kind: CredencialTeamKind.notRegistered,
                ),
              ),
              size: size,
              textScale: scale,
            );
            await assertFitsWithoutScrolling(tester);
          });

          testWidgets('$size @ textScale $scale, all banners at once',
              (tester) async {
            await pumpCard(
              tester,
              credencial(
                birthDate: '2000-01-01 00:00:00',
                caracter: 'Padre Ex-Alumno',
                team: const CredencialTeam(
                  id: 1,
                  name: 'Lista de No Inscriptos 2026',
                  kind: CredencialTeamKind.notRegistered,
                ),
              ),
              stale: true,
              replacement: CredencialReplacementStatus.pending,
              size: size,
              textScale: scale,
            );
            await assertFitsWithoutScrolling(tester);
          });

          testWidgets('$size @ textScale $scale, minimal data',
              (tester) async {
            await pumpCard(tester, credencial(), size: size, textScale: scale);
            await assertFitsWithoutScrolling(tester);
          });
        }
      }
    });
  });
}

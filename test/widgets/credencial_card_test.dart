import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/models/credencial.dart';
import 'package:torneo_futbol_app/services/credencial_state.dart';
import 'package:torneo_futbol_app/widgets/credencial_card.dart';
import 'package:torneo_futbol_app/widgets/rotating_code_view.dart';

Credencial _credencial({
  String? birthDate,
  String? caracter,
  CredencialTeam? team,
}) {
  return Credencial(
    id: 'cred-1',
    issuedAt: '2026-09-29 00:00:00',
    expiresAt: '2027-09-29 00:00:00',
    playerId: 1,
    fullName: 'Juan Perez',
    dni: '30111222',
    birthDate: birthDate,
    caracter: caracter,
    team: team,
    photo: const CredencialPhoto(id: 1, url: 'https://example.com/p.jpg'),
    codeSeed: 'c2VlZA',
    code: const CredencialCode(alg: 'SHA256', step: 30, digits: 6),
  );
}

/// A 1x1 transparent PNG — the smallest byte sequence `Image.memory` can
/// actually decode, so every test below exercises the real (non-nullable)
/// [CredencialCard.photoBytes] contract instead of a placeholder.
Uint8List _validPngBytes() => Uint8List.fromList([
      137,
      80,
      78,
      71,
      13,
      10,
      26,
      10,
      0,
      0,
      0,
      13,
      73,
      72,
      68,
      82,
      0,
      0,
      0,
      1,
      0,
      0,
      0,
      1,
      8,
      6,
      0,
      0,
      0,
      31,
      21,
      196,
      137,
      0,
      0,
      0,
      10,
      73,
      68,
      65,
      84,
      120,
      156,
      99,
      0,
      1,
      0,
      0,
      5,
      0,
      1,
      13,
      10,
      45,
      180,
      0,
      0,
      0,
      0,
      73,
      69,
      78,
      68,
      174,
      66,
      96,
      130,
    ]);

Future<void> _pumpCard(
  WidgetTester tester,
  Credencial credencial, {
  CredencialReplacementStatus replacement = CredencialReplacementStatus.none,
  bool stale = false,
  Uint8List? photoBytes,
}) {
  return tester.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: CredencialCard(
          credential: credencial,
          replacement: replacement,
          stale: stale,
          photoBytes: photoBytes ?? _validPngBytes(),
          now: () => DateTime(2026, 9, 30),
        ),
      ),
    ),
  );
}

void main() {
  group('CredencialCard', () {
    testWidgets('renders full name, DNI and the rotating code view',
        (tester) async {
      await _pumpCard(tester, _credencial());

      expect(find.text('Juan Perez'), findsOneWidget);
      expect(find.textContaining('30111222'), findsOneWidget);
      expect(find.byType(RotatingCodeView), findsOneWidget);
    });

    testWidgets('computes and shows age from birth_date', (tester) async {
      // now = 2026-09-30; born 2000-01-01 → 26 years old.
      await _pumpCard(tester, _credencial(birthDate: '2000-01-01 00:00:00'));

      expect(find.textContaining('26'), findsWidgets);
    });

    testWidgets('birth_date null → renders without an age line, no crash',
        (tester) async {
      await _pumpCard(tester, _credencial(birthDate: null));

      expect(tester.takeException(), isNull);
    });

    testWidgets('caracter present → shown', (tester) async {
      await _pumpCard(tester, _credencial(caracter: 'Padre Alumno'));

      expect(find.text('Padre Alumno'), findsOneWidget);
    });

    testWidgets(
        'caracter null → omitted without failing (spec: Caracter is empty)',
        (tester) async {
      await _pumpCard(tester, _credencial(caracter: null));

      expect(tester.takeException(), isNull);
    });

    testWidgets('team present → team name shown as a badge', (tester) async {
      await _pumpCard(
        tester,
        _credencial(
          team: const CredencialTeam(
              id: 1, name: 'Real Madrid', kind: CredencialTeamKind.team),
        ),
      );

      expect(find.text('Real Madrid'), findsOneWidget);
    });

    testWidgets('waiting-list team kind → name still shown', (tester) async {
      await _pumpCard(
        tester,
        _credencial(
          team: const CredencialTeam(
            id: 2,
            name: 'Lista de Espera 2026',
            kind: CredencialTeamKind.waitingList,
          ),
        ),
      );

      expect(find.text('Lista de Espera 2026'), findsOneWidget);
    });

    testWidgets(
        'team null → renders without a team badge, no crash (spec: '
        'Team or list name unavailable)', (tester) async {
      await _pumpCard(tester, _credencial(team: null));

      expect(tester.takeException(), isNull);
    });

    testWidgets(
        'photoBytes → rendered via Image.memory (decision 1523: no '
        'placeholder path exists — a card is only ever built with verified '
        'bytes)', (tester) async {
      await _pumpCard(tester, _credencial(), photoBytes: _validPngBytes());

      expect(find.byType(Image), findsOneWidget);
      expect(find.byIcon(Icons.person), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('stale → shows an offline/last-verified notice',
        (tester) async {
      await _pumpCard(tester, _credencial(), stale: true);

      expect(find.textContaining('conexión'), findsOneWidget);
    });

    testWidgets('replacement pending → shows a review-in-progress notice',
        (tester) async {
      await _pumpCard(
        tester,
        _credencial(),
        replacement: CredencialReplacementStatus.pending,
      );

      expect(find.textContaining('revisión'), findsOneWidget);
    });

    testWidgets(
        'replacement rejected → shows a rejection notice with no reason given',
        (tester) async {
      await _pumpCard(
        tester,
        _credencial(),
        replacement: CredencialReplacementStatus.rejected,
      );

      expect(find.textContaining('rechazada'), findsOneWidget);
    });

    testWidgets(
        'no overflow on a narrow phone viewport (375x667, textScale 1.3)',
        (tester) async {
      tester.view.physicalSize = const Size(375, 667);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(
        MediaQuery(
          data: const MediaQueryData(textScaler: TextScaler.linear(1.3)),
          child: MaterialApp(
            home: Scaffold(
              body: CredencialCard(
                credential: _credencial(
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
                photoBytes: _validPngBytes(),
                now: () => DateTime(2026, 9, 30),
              ),
            ),
          ),
        ),
      );

      expect(tester.takeException(), isNull);
    });
  });
}

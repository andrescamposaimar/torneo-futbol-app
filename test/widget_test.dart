import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:torneo_futbol_app/app.dart';
import 'package:torneo_futbol_app/config/tenant_provider.dart';
import 'package:torneo_futbol_app/config/tenants/marianista.dart';

void main() {
  testWidgets('App smoke test', (WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          tenantConfigProvider.overrideWithValue(marianistaTenant),
        ],
        child: const EntreRedesApp(),
      ),
    );
    expect(find.byType(EntreRedesApp), findsOneWidget);
  });
}

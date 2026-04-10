import 'package:flutter_test/flutter_test.dart';

import 'package:entre_redes_flutter_app/main.dart';

void main() {
  testWidgets('App smoke test', (WidgetTester tester) async {
    await tester.pumpWidget(const EntreRedesApp());
    expect(find.byType(EntreRedesApp), findsOneWidget);
  });
}

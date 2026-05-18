import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/tenant_config.dart';
import 'package:torneo_futbol_app/theme.dart';

void main() {
  group('buildAppTheme', () {
    const primaryColor = Color(0xFF123456);
    const accentColor = Color(0xFF654321);

    late ThemeData theme;

    setUp(() {
      theme = buildAppTheme(
        BrandColors(
          primary: primaryColor,
          accent: accentColor,
          splashBackground: primaryColor,
        ),
      );
    });

    test('colorScheme.primary matches the injected primary color', () {
      expect(theme.colorScheme.primary, equals(primaryColor));
    });

    test('appBarTheme.backgroundColor matches the injected primary color', () {
      expect(theme.appBarTheme.backgroundColor, equals(primaryColor));
    });
  });
}

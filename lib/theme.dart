import 'package:flutter/material.dart';
import 'config/tenant_config.dart';

/// Builds a MaterialApp [ThemeData] from the tenant's [BrandColors].
/// This is the single source of truth for all theme-derived colors.
/// Screens should use [Theme.of(context).colorScheme.primary] rather than
/// hard-coding color literals.
ThemeData buildAppTheme(BrandColors colors) {
  return ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(seedColor: colors.accent).copyWith(
      primary: colors.primary,
      surface: colors.background,
    ),
    scaffoldBackgroundColor: colors.background,
    appBarTheme: AppBarTheme(
      backgroundColor: colors.primary,
      foregroundColor: Colors.white,
      iconTheme: const IconThemeData(color: Colors.white),
      titleTextStyle: const TextStyle(
        fontFamily: 'Roboto',
        fontWeight: FontWeight.bold,
        fontSize: 20,
        color: Colors.white,
      ),
    ),
    bottomNavigationBarTheme: BottomNavigationBarThemeData(
      selectedItemColor: colors.primary,
      unselectedItemColor: Colors.grey,
    ),
    textTheme: const TextTheme(
      bodyLarge: TextStyle(fontSize: 16),
      bodyMedium: TextStyle(fontSize: 14),
      titleLarge: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
    ),
    cardColor: colors.card,
  );
}

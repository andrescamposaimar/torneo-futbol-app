import 'package:flutter/material.dart';

class AppColors {
  static const primary = Color(0xFF00B7CC); // celeste EntreRedes
  static const background = Color(0xFFF9F9F9);
  static const textPrimary = Colors.black;
  static const textSecondary = Colors.grey;
  static const card = Colors.white;
}

class AppTheme {
  static ThemeData get lightTheme => ThemeData(
    primaryColor: AppColors.primary,
    scaffoldBackgroundColor: AppColors.background,
    appBarTheme: AppBarTheme(
      color: AppColors.primary,
      titleTextStyle: TextStyle(
        fontFamily: 'Roboto',
        fontWeight: FontWeight.bold,
        fontSize: 20,
        color: Colors.white,
      ),
      iconTheme: IconThemeData(color: Colors.white),
    ),
    bottomNavigationBarTheme: BottomNavigationBarThemeData(
      selectedItemColor: AppColors.primary,
      unselectedItemColor: AppColors.textSecondary,
    ),
    textTheme: const TextTheme(
      bodyLarge: TextStyle(fontSize: 16),
      bodyMedium: TextStyle(fontSize: 14),
      titleLarge: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
    ),
    cardColor: AppColors.card,
    useMaterial3: true,
  );
}
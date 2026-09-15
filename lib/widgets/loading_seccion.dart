import 'package:flutter/material.dart';

/// The loading state shown while a section's data is being fetched.
///
/// Declared once here instead of as seven near-identical copies across
/// `teams_screen.dart`, `match_detail_screen.dart`, `standings_screen.dart`,
/// `scorers_screen.dart`, `listas_screen.dart`, `players_screen.dart`, and
/// `imbatibles_screen.dart` (this codebase has already been burned by
/// exactly that kind of copy-paste once — see `year_pill.dart`).
///
/// Named `LoadingSeccion` rather than the previous `LoadingSeccionConAd`:
/// the ad banner it used to render alongside the spinner was removed in an
/// earlier pass, so the old name no longer describes what this widget does.
class LoadingSeccion extends StatelessWidget {
  final String texto;

  const LoadingSeccion({super.key, required this.texto});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          const CircularProgressIndicator(),
          const SizedBox(height: 12),
          Text(texto, style: const TextStyle(fontSize: 14)),
        ],
      ),
    );
  }
}

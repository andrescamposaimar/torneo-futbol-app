import 'package:flutter/material.dart';

/// A small pill styled the same everywhere a year (or similarly short label)
/// stands alone in the app: `player_detail_screen.dart`'s TEMPORADAS chips,
/// its título rows' year pill, and `campeones_screen.dart`'s year cards all
/// share this one widget instead of independently maintained copies of the
/// same styling (this codebase has already been burned by exactly that kind
/// of copy-paste once).
class YearPill extends StatelessWidget {
  final String label;

  const YearPill(this.label, {super.key});

  @override
  Widget build(BuildContext context) {
    final primary = Theme.of(context).colorScheme.primary;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
      decoration: BoxDecoration(
        color: primary.withValues(alpha: 0.07),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: primary.withValues(alpha: 0.15)),
      ),
      child: Text(
        label,
        style: TextStyle(
          fontSize: 13,
          fontWeight: FontWeight.w700,
          color: primary.withValues(alpha: 0.9),
        ),
      ),
    );
  }
}

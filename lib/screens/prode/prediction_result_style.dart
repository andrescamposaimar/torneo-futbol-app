import 'package:flutter/material.dart';

// ---------------------------------------------------------------------------
// PredictionResultStyle — T-11
// ---------------------------------------------------------------------------

/// Immutable value object returned by [resolvePredictionStyle].
///
/// Carries the three presentation properties needed by [_MatchCard] to render
/// an evaluation badge: the badge [color], a short human-readable [label], and
/// an [icon].
///
/// All consumers MUST call [resolvePredictionStyle] instead of constructing
/// this directly. The constructor is intentionally accessible so callers can
/// hold a typed reference, but the resolver owns all mapping logic.
@immutable
class PredictionResultStyle {
  final Color color;
  final String label;
  final IconData icon;

  const PredictionResultStyle({
    required this.color,
    required this.label,
    required this.icon,
  });
}

// ---------------------------------------------------------------------------
// Resolver
// ---------------------------------------------------------------------------

/// Maps (`evaluationMethod`, `points`) to a [PredictionResultStyle].
///
/// Rules — in priority order:
///
/// | method              | points   | Color          | Label          |
/// |---------------------|----------|----------------|----------------|
/// | `exact_score`       | any      | green          | "+3 Exacto"    |
/// | `result_only`       | >= 1     | amber          | "+1 Ganador"   |
/// | `result_only`       | 0 / null | red            | "0 pts"        |
/// | `no_prediction`     | any      | red            | "0 pts"        |
/// | `no_match_score`    | any      | grey           | "Sin resultado"|
/// | null / unknown      | 3        | green          | "+3 Exacto"    |
/// | null / unknown      | >= 1     | amber          | "+1 Ganador"   |
/// | null / unknown      | 0        | red            | "0 pts"        |
/// | null / unknown      | null     | grey (neutral) | "—"            |
///
/// This function MUST be null-safe and MUST NOT throw for any combination of
/// inputs. Pre-change payloads (points known but method null) MUST receive a
/// sensible color derived from [points] alone.
PredictionResultStyle resolvePredictionStyle({
  required String? method,
  required int? points,
}) {
  // --- Explicit method path ---
  if (method == 'exact_score') {
    return PredictionResultStyle(
      color: Colors.green.shade700,
      label: '+3 Exacto',
      icon: Icons.check_circle,
    );
  }

  if (method == 'result_only') {
    final pts = points ?? 0;
    if (pts >= 1) {
      return PredictionResultStyle(
        color: Colors.amber.shade700,
        label: '+1 Ganador',
        icon: Icons.check_circle_outline,
      );
    } else {
      return PredictionResultStyle(
        color: Colors.red.shade700,
        label: '0 pts',
        icon: Icons.cancel_outlined,
      );
    }
  }

  if (method == 'no_prediction') {
    return PredictionResultStyle(
      color: Colors.red.shade700,
      label: '0 pts',
      icon: Icons.remove_circle_outline,
    );
  }

  if (method == 'no_match_score') {
    return PredictionResultStyle(
      color: Colors.grey.shade600,
      label: 'Sin resultado',
      icon: Icons.schedule,
    );
  }

  // --- Null / unknown method — infer from points (backward compat) ---
  if (points == null) {
    return PredictionResultStyle(
      color: Colors.grey.shade600,
      label: '—',
      icon: Icons.help_outline,
    );
  }

  if (points >= 3) {
    return PredictionResultStyle(
      color: Colors.green.shade700,
      label: '+3 Exacto',
      icon: Icons.check_circle,
    );
  }

  if (points >= 1) {
    return PredictionResultStyle(
      color: Colors.amber.shade700,
      label: '+1 Ganador',
      icon: Icons.check_circle_outline,
    );
  }

  // points == 0
  return PredictionResultStyle(
    color: Colors.red.shade700,
    label: '0 pts',
    icon: Icons.cancel_outlined,
  );
}

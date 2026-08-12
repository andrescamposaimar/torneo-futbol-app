import 'dart:math' as math;
import 'dart:ui' show lerpDouble;

import 'package:flutter/material.dart';

/// Draws a football pitch in perspective.
///
/// The far goal line (top) is rendered narrower than the near one (bottom),
/// which is what gives the pitch its tilted look. Every measurement is
/// expressed in normalized pitch coordinates and mapped through [_point], so
/// the drawing scales to any canvas size.
class FullFieldPainter extends CustomPainter {
  const FullFieldPainter();

  /// Half width of the pitch at the far goal line, as a fraction of the canvas.
  static const double farHalfWidth = 0.33;

  /// Half width of the pitch at the near goal line.
  static const double nearHalfWidth = 0.5;

  /// Half width of the pitch at [depth], as a fraction of the canvas width.
  ///
  /// Callers laying widgets over the pitch use this so their content follows
  /// the same perspective as the drawing and never spills onto the touchline.
  static double halfWidthAt(double depth) =>
      lerpDouble(farHalfWidth, nearHalfWidth, depth)!;

  /// Number of mowing stripes painted from the far to the near goal line.
  static const int _stripeCount = 8;

  static const Color _grassDark = Color(0xFF1B6435);
  static const Color _grassLight = Color(0xFF2A7D46);

  /// Maps normalized pitch coordinates to canvas coordinates.
  ///
  /// [nx] runs 0 (left touchline) to 1 (right touchline).
  /// [ny] runs 0 (far goal line) to 1 (near goal line).
  Offset _point(double nx, double ny, Size size) {
    final halfWidth = halfWidthAt(ny) * size.width;
    return Offset(size.width / 2 + (nx - 0.5) * 2 * halfWidth, ny * size.height);
  }

  /// Builds the quad delimited by two normalized x bounds and two y bounds.
  /// Its sides follow the perspective, so it renders as a trapezoid.
  Path _quad(double left, double right, double top, double bottom, Size size) {
    return Path()
      ..moveTo(_point(left, top, size).dx, _point(left, top, size).dy)
      ..lineTo(_point(right, top, size).dx, _point(right, top, size).dy)
      ..lineTo(_point(right, bottom, size).dx, _point(right, bottom, size).dy)
      ..lineTo(_point(left, bottom, size).dx, _point(left, bottom, size).dy)
      ..close();
  }

  @override
  void paint(Canvas canvas, Size size) {
    final pitch = _quad(0, 1, 0, 1, size);

    // Keep every stroke inside the pitch so the corner arcs stay clean.
    canvas.save();
    canvas.clipPath(pitch);

    _paintStripes(canvas, size);
    _paintMarkings(canvas, size);
    _paintDepthShade(canvas, size);

    canvas.restore();
  }

  /// Darkens the far end so the pitch reads as receding rather than flat.
  void _paintDepthShade(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    canvas.drawRect(
      rect,
      Paint()
        ..shader = const LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [Color(0x40000000), Color(0x00000000)],
        ).createShader(rect),
    );
  }

  void _paintStripes(Canvas canvas, Size size) {
    final paint = Paint()..style = PaintingStyle.fill;

    for (var i = 0; i < _stripeCount; i++) {
      paint.color = i.isEven ? _grassDark : _grassLight;
      canvas.drawPath(
        _quad(0, 1, i / _stripeCount, (i + 1) / _stripeCount, size),
        paint,
      );
    }
  }

  void _paintMarkings(Canvas canvas, Size size) {
    final line = Paint()
      ..color = Colors.white.withValues(alpha: 0.55)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2;

    final spot = Paint()
      ..color = Colors.white.withValues(alpha: 0.55)
      ..style = PaintingStyle.fill;

    // Touchlines and goal lines.
    canvas.drawPath(_quad(0, 1, 0, 1, size), line);

    // Halfway line.
    canvas.drawLine(_point(0, 0.5, size), _point(1, 0.5, size), line);

    // Centre circle and spot. Squashed vertically to sit on the plane.
    final centre = _point(0.5, 0.5, size);
    final centreRadiusX = halfWidthAt(0.5) * size.width * 0.32;
    canvas.drawOval(
      Rect.fromCenter(
        center: centre,
        width: centreRadiusX * 2,
        height: size.height * 0.085,
      ),
      line,
    );
    canvas.drawCircle(centre, 2.5, spot);

    // Penalty and goal areas at both ends.
    for (final far in [true, false]) {
      final penaltyOuter = far ? 0.17 : 0.83;
      final goalOuter = far ? 0.06 : 0.94;
      final goalLine = far ? 0.0 : 1.0;
      final penaltySpot = far ? 0.115 : 0.885;

      canvas.drawPath(_quad(0.21, 0.79, math.min(goalLine, penaltyOuter),
          math.max(goalLine, penaltyOuter), size), line);
      canvas.drawPath(_quad(0.35, 0.65, math.min(goalLine, goalOuter),
          math.max(goalLine, goalOuter), size), line);
      canvas.drawCircle(_point(0.5, penaltySpot, size), 2.5, spot);

      // Arc of the penalty area, drawn only outside the box.
      final arcCentre = _point(0.5, penaltySpot, size);
      final arcWidth = halfWidthAt(penaltySpot) * size.width * 0.36;
      canvas.drawArc(
        Rect.fromCenter(
          center: arcCentre,
          width: arcWidth * 2,
          height: size.height * 0.085,
        ),
        far ? 0 : math.pi,
        math.pi,
        false,
        line,
      );
    }

    // Corner arcs.
    for (final corner in [[0.0, 0.0], [1.0, 0.0], [0.0, 1.0], [1.0, 1.0]]) {
      canvas.drawCircle(_point(corner[0], corner[1], size), size.width * 0.03, line);
    }
  }

  @override
  bool shouldRepaint(covariant FullFieldPainter oldDelegate) => false;
}

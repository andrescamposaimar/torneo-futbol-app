import 'package:flutter/material.dart';

class FullFieldPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = Colors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.5;

    final center = Offset(size.width / 2, size.height / 2);

    canvas.drawRect(Rect.fromLTWH(0, 0, size.width, size.height), paint);
    canvas.drawLine(Offset(0, center.dy), Offset(size.width, center.dy), paint);
    canvas.drawCircle(center, 20, paint);
    canvas.drawRect(Rect.fromLTWH((size.width - 200) / 2, 0, 200, 60), paint);
    canvas.drawRect(Rect.fromLTWH((size.width - 200) / 2, size.height - 60, 200, 60), paint);
    canvas.drawCircle(Offset(size.width / 2, 60), 2, paint);
    canvas.drawCircle(Offset(size.width / 2, size.height - 60), 2, paint);
    canvas.drawCircle(const Offset(0, 0), 3, paint);
    canvas.drawCircle(Offset(size.width, 0), 3, paint);
    canvas.drawCircle(Offset(0, size.height), 3, paint);
    canvas.drawCircle(Offset(size.width, size.height), 3, paint);
  }

  @override
  bool shouldRepaint(CustomPainter oldDelegate) => false;
}

import 'dart:async';

import 'package:flutter/material.dart';

import '../services/rotating_code.dart';

/// Renders the 30s rotating liveness code (spec "Rotating Liveness Code")
/// together with a VISIBLE liveness cue: a countdown ring that moves every
/// second and the current wall-clock time. Both exist so a static screenshot
/// shown to security staff is obviously frozen (design "Flutter states";
/// this widget's own purpose is purely presentational/anti-screenshot, no
/// server round-trip is ever made here — [RotatingCode.code] is pure and
/// local, per its own docblock).
///
/// [now] defaults to [DateTime.now] and is injectable so tests can drive the
/// clock deterministically instead of depending on wall-clock timing.
class RotatingCodeView extends StatefulWidget {
  final String seed;
  final int stepSeconds;
  final DateTime Function() now;

  const RotatingCodeView({
    super.key,
    required this.seed,
    this.stepSeconds = RotatingCode.step,
    this.now = DateTime.now,
  });

  @override
  State<RotatingCodeView> createState() => _RotatingCodeViewState();
}

class _RotatingCodeViewState extends State<RotatingCodeView> {
  Timer? _timer;
  late DateTime _tick;

  @override
  void initState() {
    super.initState();
    _tick = widget.now();
    // A 1s tick is deliberately faster than the 30s code rotation — it is
    // the liveness cue itself (the ring/clock), not the code's own step.
    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      setState(() => _tick = widget.now());
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final nowSeconds = _tick.millisecondsSinceEpoch ~/ 1000;
    final code = RotatingCode.code(widget.seed, nowSeconds);
    final elapsedInStep = nowSeconds % widget.stepSeconds;
    // Counts DOWN to the next rotation: full ring right after a rotation,
    // empty right before the next one.
    final remainingFraction = 1 - (elapsedInStep / widget.stepSeconds);

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            SizedBox(
              width: 28,
              height: 28,
              child: CircularProgressIndicator(
                value: remainingFraction,
                strokeWidth: 3,
              ),
            ),
            const SizedBox(width: 12),
            Text(
              code,
              style: const TextStyle(
                fontSize: 32,
                fontWeight: FontWeight.bold,
                letterSpacing: 4,
              ),
            ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          _formatClock(_tick),
          style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
        ),
      ],
    );
  }

  String _formatClock(DateTime dt) {
    String two(int n) => n.toString().padLeft(2, '0');
    return '${two(dt.hour)}:${two(dt.minute)}:${two(dt.second)}';
  }
}

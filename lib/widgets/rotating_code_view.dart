import 'dart:async';

import 'package:flutter/material.dart';

import '../services/rotating_code.dart';

/// Groups a code string into runs of three characters separated by a space
/// (e.g. "572189" -> "572 189"), matching how the server's 6-digit codes are
/// meant to be read aloud/compared at a glance. Pure and independent of
/// [RotatingCode.digits] so it keeps working if that ever changes.
@visibleForTesting
String groupCode(String code) {
  final buffer = StringBuffer();
  for (var i = 0; i < code.length; i++) {
    if (i != 0 && i % 3 == 0) buffer.write(' ');
    buffer.write(code[i]);
  }
  return buffer.toString();
}

/// Reads a code out digit-by-digit for screen readers (e.g. "572189" ->
/// "5 7 2 1 8 9") — distinct from [groupCode] (which groups by 3 for visual
/// reading), spoken digits are clearer one at a time.
String _spokenDigits(String code) => code.split('').join(' ');

/// Renders the 30s rotating liveness code (spec "Rotating Liveness Code")
/// as a self-contained, tinted panel (design D-UI): a header, the ring +
/// grouped code, a countdown line and a live wall-clock. The ring moving
/// every second and the clock ticking with seconds are both deliberate
/// anti-screenshot cues — a static screenshot shown to security staff is
/// obviously frozen (design "Flutter states"; this widget's own purpose is
/// purely presentational, no server round-trip is ever made here —
/// [RotatingCode.code] is pure and local, per its own docblock).
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
  static const double _ringSize = 44;
  static const double _ringStroke = 4;
  static const double _headerIconSize = 16;
  static const double _headerGap = 12;
  static const double _codeGap = 8;
  static const double _countdownGap = 4;
  static const EdgeInsets _panelPadding = EdgeInsets.all(16);

  static const TextStyle _headerStyle =
      TextStyle(fontSize: 13, fontWeight: FontWeight.w600);
  static const TextStyle _codeStyle = TextStyle(
    fontSize: 40,
    fontWeight: FontWeight.w700,
    letterSpacing: 2,
    fontFeatures: [FontFeature.tabularFigures()],
  );
  static const TextStyle _countdownStyle =
      TextStyle(fontSize: 13, fontWeight: FontWeight.w500);
  static const TextStyle _clockStyle = TextStyle(
    fontSize: 16,
    fontWeight: FontWeight.w600,
    fontFeatures: [FontFeature.tabularFigures()],
  );

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
    final colorScheme = Theme.of(context).colorScheme;
    final primary = colorScheme.primary;

    final nowSeconds = _tick.millisecondsSinceEpoch ~/ 1000;
    final code = RotatingCode.code(widget.seed, nowSeconds);
    final elapsedInStep = nowSeconds % widget.stepSeconds;
    // Counts DOWN to the next rotation: full ring right after a rotation,
    // empty right before the next one.
    final remainingFraction = 1 - (elapsedInStep / widget.stepSeconds);
    final remainingSeconds = widget.stepSeconds - elapsedInStep;
    final grouped = groupCode(code);

    return Container(
      width: double.infinity,
      padding: _panelPadding,
      decoration: BoxDecoration(
        color: primary.withValues(alpha: 0.06),
        border: Border.all(color: primary.withValues(alpha: 0.15)),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          // FittedBox(scaleDown): a real device at the supported sizes never
          // needs to shrink this — it is a safety net so an unusually long
          // system font/text-scale combination degrades gracefully (shrinks)
          // instead of ever throwing a RenderFlex overflow.
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.lock_outline,
                    size: _headerIconSize, color: primary),
                const SizedBox(width: 8),
                Text(
                  'Código de verificación',
                  style: _headerStyle.copyWith(color: primary),
                ),
              ],
            ),
          ),
          SizedBox(height: _headerGap),
          Semantics(
            label: 'Código de verificación ${_spokenDigits(code)}',
            child: FittedBox(
              fit: BoxFit.scaleDown,
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                mainAxisSize: MainAxisSize.min,
                children: [
                  SizedBox(
                    width: _ringSize,
                    height: _ringSize,
                    child: CircularProgressIndicator(
                      value: remainingFraction,
                      strokeWidth: _ringStroke,
                      color: primary,
                      // A visible track keeps the ring readable as a circle
                      // in the last seconds, when the arc is nearly empty.
                      backgroundColor: primary.withValues(alpha: 0.15),
                      strokeCap: StrokeCap.round,
                    ),
                  ),
                  const SizedBox(width: 16),
                  ExcludeSemantics(
                    child: Text(grouped, style: _codeStyle),
                  ),
                ],
              ),
            ),
          ),
          SizedBox(height: _codeGap),
          // Not a live region: the countdown text changes every second, but
          // announcing it that often would be noise for a screen reader.
          Text(
            remainingSeconds == 1
                ? 'Se actualiza en 1 segundo'
                : 'Se actualiza en $remainingSeconds segundos',
            style: _countdownStyle.copyWith(color: colorScheme.onSurfaceVariant),
          ),
          SizedBox(height: _countdownGap),
          Text(
            _formatClock(_tick),
            style: _clockStyle.copyWith(color: colorScheme.onSurfaceVariant),
          ),
        ],
      ),
    );
  }

  String _formatClock(DateTime dt) {
    String two(int n) => n.toString().padLeft(2, '0');
    return '${two(dt.hour)}:${two(dt.minute)}:${two(dt.second)}';
  }
}

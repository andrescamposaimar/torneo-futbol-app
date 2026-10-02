import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../models/credencial.dart';
import '../services/credencial_state.dart';
import 'rotating_code_view.dart';

/// Formats a DNI by grouping its digits in runs of three from the right with
/// a "." separator (e.g. "29392780" -> "29.392.780"), the conventional way
/// Argentine DNIs are displayed. A DNI is an IDENTIFIER, not a quantity:
/// `NumberFormat` (from `package:intl`, already a dependency elsewhere in
/// this app) would parse it to an `int` first and silently drop any leading
/// zero — exactly the kind of corruption an identifier must never suffer —
/// so this is a dedicated string-only grouping instead. Non-digit input
/// (including an empty string) is returned unchanged rather than guessing
/// at a format it was never designed for.
@visibleForTesting
String formatDni(String dni) {
  if (dni.isEmpty || !RegExp(r'^\d+$').hasMatch(dni)) return dni;
  final buffer = StringBuffer();
  for (var i = 0; i < dni.length; i++) {
    final remaining = dni.length - i;
    if (i != 0 && remaining % 3 == 0) buffer.write('.');
    buffer.write(dni[i]);
  }
  return buffer.toString();
}

/// Renders the actual credential (design D-UI: "photo as protagonist" card
/// redesign) — a large, centered photo, full name, DNI/age/status, the
/// `caracter`+team line, and the rotating liveness code panel (spec
/// "Credential Payload and Display" + "Rotating Liveness Code").
///
/// Pure/presentational: it never touches the network, the repository, or the
/// filesystem — [photoBytes] must already be the bytes resolved by
/// [CredencialController] for `credential.photo.id` (design D5; decision
/// 1523: a card with valid styling must NEVER render without photo bytes, so
/// this widget does not accept a nullable/placeholder photo at all).
///
/// Owns its own [SafeArea]: the photo region is the single flexible
/// ([Expanded]) child of a non-scrolling [Column] and claims exactly
/// whatever vertical space the other (intrinsically-sized) blocks do not
/// need — real layout arithmetic performed by [Column]/[Expanded] itself,
/// not a hand-estimated budget. This card must NEVER scroll (the user
/// compares the face on screen with the person in front of them; a card
/// that scrolls a few pixels on the real device, even if it measures clean
/// in tests, fails that job) — see [CredencialCard] tests asserting no
/// [Scrollable] exists in this subtree. Exactly one padding/inset layer
/// (matches the app's container/presentational split: the screen hands
/// this widget the entire body and nothing else, avoiding the
/// double-padding overflow this screen hit before — see
/// `credencial_screen.dart`).
class CredencialCard extends StatelessWidget {
  final Credencial credential;
  final CredencialReplacementStatus replacement;
  final bool stale;
  final Uint8List photoBytes;
  final DateTime Function() now;

  const CredencialCard({
    super.key,
    required this.credential,
    required this.photoBytes,
    this.replacement = CredencialReplacementStatus.none,
    this.stale = false,
    this.now = DateTime.now,
  });

  static const double _spacing = 16;
  static const double _minPhotoHeight = 160;
  static const double _photoMaxWidthFraction = 0.8;
  static const double _photoAspectRatio = 3 / 4;

  // The two non-photo blocks (banners above, name/info/secondary/code below)
  // are each capped to a FIXED fraction of the viewport height and wrapped
  // in FittedBox(scaleDown) — a real safety net, not an estimate: if their
  // true (measured-by-Flutter) natural height exceeds the cap, FittedBox
  // uniformly shrinks that block's rendered output to fit exactly inside
  // it; if it already fits, nothing changes (scale stays 1.0). These two
  // caps sum to 0.70, so the Expanded photo below is GUARANTEED at least
  // ~30% of the viewport minus spacing — it can never be squeezed to zero
  // by runaway banner or code-panel text the way a fixed-floor-only
  // approach could, and the Column itself can never overflow regardless of
  // content length or text scale, because every one of its children now
  // has either a flexible (Expanded) or a hard-capped (FittedBox) height.
  static const double _bannersHeightFraction = 0.22;
  static const double _footerHeightFraction = 0.48;

  @override
  Widget build(BuildContext context) {
    final age = _ageFromBirthDate(credential.birthDate, now());
    final secondaryLine = _secondaryLine();
    final banners = _bannerSpecs();

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(_spacing),
        child: LayoutBuilder(
          builder: (context, constraints) {
            final maxPhotoWidth = constraints.maxWidth * _photoMaxWidthFraction;
            final maxBannersHeight =
                constraints.maxHeight * _bannersHeightFraction;
            final maxFooterHeight =
                constraints.maxHeight * _footerHeightFraction;

            Widget capped({required double maxHeight, required Widget child}) {
              return ConstrainedBox(
                constraints: BoxConstraints(maxHeight: maxHeight),
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.topCenter,
                  child: ConstrainedBox(
                    constraints: BoxConstraints(maxWidth: constraints.maxWidth),
                    child: child,
                  ),
                ),
              );
            }

            return Column(
              mainAxisSize: MainAxisSize.max,
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                if (banners.isNotEmpty)
                  capped(
                    maxHeight: maxBannersHeight,
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        for (final banner in banners) ...[
                          banner.build(context),
                          const SizedBox(height: _spacing),
                        ],
                      ],
                    ),
                  ),
                Expanded(
                  child: Center(
                    child: ConstrainedBox(
                      // minHeight is a soft floor, not a hard guarantee: if
                      // the leftover space Expanded computes is smaller
                      // than this, BoxConstraints.enforce() clamps the
                      // floor down to whatever is actually available —
                      // the photo can shrink toward (never below) zero
                      // instead of ever forcing this Column to overflow.
                      // The real guarantee comes from the two capped
                      // blocks above/below leaving the photo its ~30%
                      // share (see `_bannersHeightFraction`/
                      // `_footerHeightFraction` docs).
                      constraints: BoxConstraints(
                        maxWidth: maxPhotoWidth,
                        minHeight: _minPhotoHeight,
                      ),
                      child: AspectRatio(
                        aspectRatio: _photoAspectRatio,
                        child: _Photo(
                          photoBytes: photoBytes,
                          fullName: credential.fullName,
                        ),
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: _spacing),
                capped(
                  maxHeight: maxFooterHeight,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        credential.fullName,
                        textAlign: TextAlign.center,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 24,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Wrap(
                        alignment: WrapAlignment.center,
                        spacing: _spacing,
                        runSpacing: 8,
                        children: [
                          _InfoItem(
                            caption: 'DNI',
                            value: formatDni(credential.dni),
                          ),
                          if (age != null)
                            _InfoItem(caption: 'Edad', value: '$age años'),
                          const _StatusChip(),
                        ],
                      ),
                      if (secondaryLine != null) ...[
                        const SizedBox(height: 8),
                        Builder(
                          builder: (context) => Text(
                            secondaryLine,
                            textAlign: TextAlign.center,
                            // Bounded like the name above: a fixed, short,
                            // known-shape string ("{caracter} ·
                            // {team.name}") that should never need more
                            // than two lines, but capped defensively so it
                            // can never be the thing that pushes the code
                            // panel past the viewport.
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              fontSize: 14,
                              fontWeight: FontWeight.w500,
                              color:
                                  Theme.of(context).colorScheme.onSurfaceVariant,
                            ),
                          ),
                        ),
                      ],
                      const SizedBox(height: _spacing),
                      RotatingCodeView(
                        seed: credential.codeSeed,
                        stepSeconds: credential.code.step,
                        now: now,
                      ),
                    ],
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }

  /// "{caracter} · {team.name}" (spec: team/list name MAY be unavailable;
  /// `caracter` MAY be empty — both are independently optional, the line
  /// itself only disappears when BOTH are null).
  String? _secondaryLine() {
    final parts = [
      if (credential.caracter != null) credential.caracter!,
      if (credential.team != null) credential.team!.name,
    ];
    return parts.isEmpty ? null : parts.join(' · ');
  }

  List<_BannerSpec> _bannerSpecs() {
    return [
      if (stale)
        const _BannerSpec(
          kind: _BannerKind.stale,
          icon: Icons.cloud_off,
          text: 'Sin conexión — mostrando tu última credencial verificada.',
        ),
      if (replacement == CredencialReplacementStatus.pending)
        const _BannerSpec(
          kind: _BannerKind.pending,
          icon: Icons.hourglass_top,
          text: 'Tu foto nueva está en revisión.',
        ),
      if (replacement == CredencialReplacementStatus.rejected)
        const _BannerSpec(
          kind: _BannerKind.rejected,
          icon: Icons.error_outline,
          text: 'Tu foto nueva fue rechazada. La comisión se pondrá en '
              'contacto.',
        ),
    ];
  }

  /// Design "age computed on device from birth_date". Returns null when
  /// [birthDate] is null (spec: an out-of-range age is already nulled
  /// server-side, so a null here simply means "do not show an age line").
  int? _ageFromBirthDate(String? birthDate, DateTime today) {
    if (birthDate == null) return null;
    final parsed = DateTime.tryParse(birthDate.replaceFirst(' ', 'T'));
    if (parsed == null) return null;

    var age = today.year - parsed.year;
    final hadBirthdayThisYear = today.month > parsed.month ||
        (today.month == parsed.month && today.day >= parsed.day);
    if (!hadBirthdayThisYear) age -= 1;
    return age;
  }
}

enum _BannerKind { stale, pending, rejected }

/// One of the (0-2) compact one-line banners shown above the photo (design
/// D-UI). Tinted `errorContainer`/`onErrorContainer` for stale/rejected
/// (both report something has gone wrong with trust), `primary` 10% for
/// pending (a neutral in-progress notice).
class _BannerSpec {
  final _BannerKind kind;
  final IconData icon;
  final String text;

  const _BannerSpec({required this.kind, required this.icon, required this.text});

  static const double iconSize = 18;
  static const double _gap = 10;
  static const EdgeInsets _padding = EdgeInsets.symmetric(
    horizontal: 14,
    vertical: 12,
  );
  static const TextStyle _textStyle = TextStyle(
    fontSize: 13,
    fontWeight: FontWeight.w500,
  );

  Widget build(BuildContext context) {
    final colorScheme = Theme.of(context).colorScheme;
    final Color background;
    final Color foreground;
    switch (kind) {
      case _BannerKind.pending:
        background = colorScheme.primary.withValues(alpha: 0.1);
        foreground = colorScheme.primary;
      case _BannerKind.stale:
      case _BannerKind.rejected:
        background = colorScheme.errorContainer;
        foreground = colorScheme.onErrorContainer;
    }

    return Container(
      width: double.infinity,
      padding: _padding,
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Icon(icon, size: iconSize, color: foreground),
          const SizedBox(width: _gap),
          Expanded(
            child: Text(text, style: _textStyle.copyWith(color: foreground)),
          ),
        ],
      ),
    );
  }
}

/// A "{caption}\n{value}" pair in the info row (e.g. "DNI" / "29.392.780").
class _InfoItem extends StatelessWidget {
  final String caption;
  final String value;

  const _InfoItem({required this.caption, required this.value});

  static const TextStyle _captionStyle = TextStyle(
    fontSize: 12,
    fontWeight: FontWeight.w500,
  );
  static const TextStyle _valueStyle = TextStyle(
    fontSize: 16,
    fontWeight: FontWeight.w700,
  );

  @override
  Widget build(BuildContext context) {
    final onSurfaceVariant = Theme.of(context).colorScheme.onSurfaceVariant;
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        Text(caption, style: _captionStyle.copyWith(color: onSurfaceVariant)),
        const SizedBox(height: 2),
        Text(value, style: _valueStyle),
      ],
    );
  }
}

/// The "HABILITADO" eligibility chip. Only [CredencialActive] ever renders
/// this card (decision 1523/fail-closed), so the chip simply states the
/// eligible fact — there is no "disabled" variant to design for here. The
/// icon + word carry the meaning (never color alone, for color-blind
/// accessibility); `theme.dart` has no dedicated success token and adding
/// one to `BrandColors` is not justified for a single chip.
class _StatusChip extends StatelessWidget {
  const _StatusChip();

  static const TextStyle _textStyle = TextStyle(
    fontSize: 13,
    fontWeight: FontWeight.w700,
  );
  static const double _iconSize = 16;
  static const EdgeInsets _padding =
      EdgeInsets.symmetric(horizontal: 12, vertical: 6);

  @override
  Widget build(BuildContext context) {
    final primary = Theme.of(context).colorScheme.primary;
    return Container(
      padding: _padding,
      decoration: BoxDecoration(
        color: primary.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.verified, size: _iconSize, color: primary),
          const SizedBox(width: 6),
          Text('HABILITADO', style: _textStyle.copyWith(color: primary)),
        ],
      ),
    );
  }
}

/// The large, centered photo — the protagonist of the card (design D-UI: the
/// user explicitly asked for the photo "centered and large, using as much
/// of the screen as possible"). Portrait (3:4) aspect, not the mockup's
/// landscape crop — player photos are themselves portrait, so a landscape
/// crop would cut forehead/chin.
class _Photo extends StatelessWidget {
  final Uint8List photoBytes;
  final String fullName;

  const _Photo({required this.photoBytes, required this.fullName});

  @override
  Widget build(BuildContext context) {
    final outlineVariant = Theme.of(context).colorScheme.outlineVariant;
    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: outlineVariant),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.1),
            blurRadius: 12,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(16),
        child: Semantics(
          image: true,
          label: 'Foto de $fullName',
          child: Image.memory(
            photoBytes,
            fit: BoxFit.cover,
            // Slightly above center: player photos are usually framed with
            // headroom above and torso below, so biasing the crop upward
            // keeps the face centered instead of the whole upper body.
            alignment: const Alignment(0, -0.4),
            filterQuality: FilterQuality.high,
            gaplessPlayback: true,
          ),
        ),
      ),
    );
  }
}

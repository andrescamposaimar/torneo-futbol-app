import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../models/credencial.dart';
import '../services/credencial_state.dart';
import 'rotating_code_view.dart';

/// Renders the actual credential — photo, full name, DNI, age, `caracter`,
/// team/list badge and the rotating liveness code (spec "Credential Payload
/// and Display" + "Rotating Liveness Code").
///
/// Pure/presentational: it never touches the network, the repository, or the
/// filesystem — [photoBytes] must already be the verified bytes carried by
/// [CredencialActive] (design D5; decision 1523: a card with valid styling
/// must NEVER render without verified photo bytes, so this widget does not
/// accept a nullable/placeholder photo at all — there is no valid state to
/// render a [CredencialActive] without bytes already verified against
/// `credential.photo.sha256` by [CredencialController]). This keeps the
/// card trivially testable and matches the app's container/presentational
/// split (`ProdeIdentityCard` builds its own content inline instead, but this
/// card's per-state banners and multiple optional fields warrant the
/// separation here).
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

  @override
  Widget build(BuildContext context) {
    final primary = Theme.of(context).colorScheme.primary;
    final age = _ageFromBirthDate(credential.birthDate, now());

    return Card(
      color: Colors.white,
      elevation: 2,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: BorderSide(color: Colors.grey.shade200),
      ),
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (stale)
              _banner(
                  context,
                  Icons.cloud_off,
                  'Sin conexión — '
                  'mostrando la última credencial verificada.'),
            if (replacement == CredencialReplacementStatus.pending)
              _banner(context, Icons.hourglass_top,
                  'Tu foto nueva está en revisión.'),
            if (replacement == CredencialReplacementStatus.rejected)
              _banner(
                  context,
                  Icons.error_outline,
                  'Tu foto nueva fue rechazada. La comisión se pondrá en '
                  'contacto.'),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _photo(),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        credential.fullName,
                        style: const TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.bold,
                        ),
                        overflow: TextOverflow.ellipsis,
                      ),
                      const SizedBox(height: 4),
                      Text('DNI: ${credential.dni}'),
                      if (age != null) Text('Edad: $age años'),
                      if (credential.caracter != null)
                        Text(credential.caracter!),
                      if (credential.team != null) ...[
                        const SizedBox(height: 6),
                        _teamBadge(credential.team!, primary),
                      ],
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),
            Center(
              child: RotatingCodeView(
                seed: credential.codeSeed,
                stepSeconds: credential.code.step,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _banner(BuildContext context, IconData icon, String text) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        children: [
          Icon(icon, size: 18, color: Theme.of(context).colorScheme.error),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              text,
              style: TextStyle(
                fontSize: 12,
                color: Theme.of(context).colorScheme.error,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _photo() {
    const size = 72.0;
    return ClipRRect(
      borderRadius: BorderRadius.circular(8),
      child: Image.memory(
        photoBytes,
        width: size,
        height: size,
        fit: BoxFit.cover,
        gaplessPlayback: true,
      ),
    );
  }

  Widget _teamBadge(CredencialTeam team, Color primary) {
    final color = switch (team.kind) {
      CredencialTeamKind.team => primary,
      CredencialTeamKind.waitingList => Colors.orange.shade800,
      CredencialTeamKind.notRegistered => Colors.grey.shade700,
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        team.name,
        style: TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w600,
          color: color,
        ),
        overflow: TextOverflow.ellipsis,
      ),
    );
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

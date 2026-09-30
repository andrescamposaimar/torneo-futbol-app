import 'dart:typed_data';

import '../models/credencial.dart';

// ---------------------------------------------------------------------------
// UI-facing state machine for "Mi Credencial"
// ---------------------------------------------------------------------------

/// Sealed state consumed by [CredencialScreen]/[CredencialController]
/// (design "Flutter states"; spec `player-credential`).
///
/// Distinct from the wire-level [CredencialCardState]: this type folds in the
/// offline/expiry/error cases the screen actually needs to render, and splits
/// `no_photo` into three mutually exclusive UI states based on the
/// accompanying `photo_request` ([CredencialNoPhoto], [CredencialPendingPhoto],
/// [CredencialRejectedPhoto]) instead of carrying a nested nullable field.
sealed class CredencialUiState {
  const CredencialUiState();
}

/// Nothing resolved yet — the first frame after the screen opens, before
/// either a cache read or the network GET has completed.
final class CredencialLoading extends CredencialUiState {
  const CredencialLoading();

  @override
  String toString() => 'CredencialLoading()';
}

/// The Prode session could not be revalidated (design D6: an unrecoverable
/// 401 wipes the cache and requires re-login before any credential is shown
/// again — spec "Revalidation blocked by a revoked session").
final class CredencialNotSignedIn extends CredencialUiState {
  const CredencialNotSignedIn();

  @override
  String toString() => 'CredencialNotSignedIn()';
}

/// A pending or rejected photo upload accompanying an already-active
/// credential (design "Flutter states": `Active{..., replacement}`) — the
/// currently approved photo keeps backing the credential either way (spec
/// "Replacement upload while an approved photo exists").
enum CredencialReplacementStatus { none, pending, rejected }

/// Eligible player with an approved photo — the actual credential card.
///
/// [stale] is true when the last revalidation attempt failed (design D6:
/// "network/5xx -> keep (last verified)") — this data is only as fresh as
/// [credential]'s own `issuedAt` (the server stamps `issued_at` = now on
/// every successful GET, so it doubles as "last verified at"; no separate
/// timestamp field is needed).
///
/// [photoBytes] is required and must already be verified against
/// [credential]'s `photo.sha256` by whoever constructs this state
/// (`CredencialController` — see its class docblock). Decision 1523 (non-
/// negotiable): a card with valid styling must NEVER render without
/// verified photo bytes, not even for one frame — this is enforced BY
/// CONSTRUCTION here rather than by [CredencialScreen] re-reading the photo
/// file asynchronously at render time (verify-report 1575, slice 3b, NEW
/// WARNING 1: a `FutureBuilder`-based re-read has an unavoidable first-frame
/// window where the valid-styled card renders before the read resolves).
final class CredencialActive extends CredencialUiState {
  final Credencial credential;
  final CredencialReplacementStatus replacement;
  final bool stale;
  final Uint8List photoBytes;

  const CredencialActive({
    required this.credential,
    required this.photoBytes,
    this.replacement = CredencialReplacementStatus.none,
    this.stale = false,
  });

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CredencialActive &&
          runtimeType == other.runtimeType &&
          credential == other.credential &&
          replacement == other.replacement &&
          stale == other.stale;

  @override
  int get hashCode => Object.hash(runtimeType, credential, replacement, stale);

  @override
  String toString() =>
      'CredencialActive(credential: $credential, replacement: $replacement, '
      'stale: $stale, photoBytes: ${photoBytes.length} bytes)';
}

/// Eligible player, no photo ever uploaded and none pending (spec "No photo
/// yet").
final class CredencialNoPhoto extends CredencialUiState {
  const CredencialNoPhoto();

  @override
  String toString() => 'CredencialNoPhoto()';
}

/// Eligible player, a photo upload is under review (spec "First upload").
final class CredencialPendingPhoto extends CredencialUiState {
  const CredencialPendingPhoto();

  @override
  String toString() => 'CredencialPendingPhoto()';
}

/// Eligible player, the only photo ever uploaded was rejected. No reason is
/// ever shown (spec "Rejection Closes the Request Without Publishing").
final class CredencialRejectedPhoto extends CredencialUiState {
  const CredencialRejectedPhoto();

  @override
  String toString() => 'CredencialRejectedPhoto()';
}

/// `estado` is exactly "Inhabilitado" (spec "Blocked player loses credential
/// on next online open").
final class CredencialBlocked extends CredencialUiState {
  const CredencialBlocked();

  @override
  String toString() => 'CredencialBlocked()';
}

/// The authenticated identity has no matching `sp_player` (spec
/// "Authenticated identity has no matching sp_player").
final class CredencialNotAPlayer extends CredencialUiState {
  const CredencialNotAPlayer();

  @override
  String toString() => 'CredencialNotAPlayer()';
}

/// The cached credential's hard 1-year expiry was reached while offline
/// (spec "Hard expiry reached offline").
final class CredencialExpired extends CredencialUiState {
  const CredencialExpired();

  @override
  String toString() => 'CredencialExpired()';
}

/// The revalidation GET could not complete AND there is no cached credential
/// to fall back to.
final class CredencialOfflineNoCache extends CredencialUiState {
  const CredencialOfflineNoCache();

  @override
  String toString() => 'CredencialOfflineNoCache()';
}

/// A fresh `active` response was received, but a verified photo for the
/// CURRENT approved `sha256` could not be obtained: the download failed, or
/// the downloaded bytes did not match the server's declared hash.
///
/// Decision 1523 (non-negotiable): a card with valid styling — rotating
/// code, name, DNI, team — must NEVER render without a verified photo, since
/// the whole point of the credential is letting venue security compare the
/// holder's face against a TRUSTED photo. This is therefore a distinct,
/// deliberately non-valid state, not a variant of [CredencialActive] with a
/// placeholder image: it carries no rotating code and no valid-card chrome.
/// A previously-verified photo for a DIFFERENT (now-replaced) `sha256` is
/// never substituted here either — it may be a rejected/replaced face.
final class CredencialPhotoUnavailable extends CredencialUiState {
  const CredencialPhotoUnavailable();

  @override
  String toString() => 'CredencialPhotoUnavailable()';
}

/// An unexpected failure that is not a recognized network/auth outcome (e.g.
/// a successful response this client cannot make sense of).
final class CredencialError extends CredencialUiState {
  final String message;

  const CredencialError(this.message);

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CredencialError &&
          runtimeType == other.runtimeType &&
          message == other.message;

  @override
  int get hashCode => Object.hash(runtimeType, message);

  @override
  String toString() => 'CredencialError(message: $message)';
}

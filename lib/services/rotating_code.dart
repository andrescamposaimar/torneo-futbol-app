import 'dart:convert';
import 'dart:math';
import 'dart:typed_data';

import 'package:crypto/crypto.dart' as crypto;

/// The rotating liveness code (spec "Rotating Liveness Code"; design D4).
///
/// This is a byte-for-byte Dart port of the server's
/// `EntreRedes\Credencial\Code\RotatingCode::code()`
/// (`wordpress_plugins/entre-redes-credencial/src/Code/RotatingCode.php`) —
/// TOTP-SHA256, RFC 6238 dynamic truncation generalised to a 32-byte
/// HMAC-SHA256 digest instead of RFC 4226's 20-byte SHA-1 one, [step] = 30s,
/// [digits] = 6.
///
/// Deliberately does NOT port `seedFor()`: that derivation needs the
/// server-only `credencial_code_secret` option and is never computed on the
/// client — the app only ever receives the resulting `code_seed` string over
/// GET and caches it (design D5), then calls [code] locally on every tick
/// with no network call (spec: "Code rotates while offline").
///
/// Test vectors are shared verbatim with the PHP
/// `Code\RotatingCodeTest::test_code_matches_shared_vectors` — both sides
/// must keep reproducing the identical values.
abstract final class RotatingCode {
  static const String alg = 'SHA256';
  static const int step = 30;
  static const int digits = 6;

  /// Computes the 6-digit code for [seed] (a base64url string, unpadded —
  /// the same shape the server's `code_seed` field always has) at
  /// [timestampSeconds] (a Unix epoch, UTC).
  static String code(String seed, int timestampSeconds) {
    final key = _base64UrlDecode(seed);
    final counter = timestampSeconds ~/ step;
    final counterBytes = _packCounterBigEndian(counter);

    final hmac = crypto.Hmac(crypto.sha256, key).convert(counterBytes).bytes;

    final offset = hmac[hmac.length - 1] & 0x0f;
    final binary = ((hmac[offset] & 0x7f) << 24) |
        ((hmac[offset + 1] & 0xff) << 16) |
        ((hmac[offset + 2] & 0xff) << 8) |
        (hmac[offset + 3] & 0xff);

    final modulus = pow(10, digits).toInt();
    final otp = binary % modulus;

    return otp.toString().padLeft(digits, '0');
  }

  /// Mirrors PHP's `pack('J', $counter)` — an 8-byte big-endian unsigned
  /// integer.
  static Uint8List _packCounterBigEndian(int counter) {
    final bytes = ByteData(8);
    bytes.setUint64(0, counter, Endian.big);
    return bytes.buffer.asUint8List();
  }

  /// Mirrors PHP's `base64UrlDecode()`: restores the `=` padding the server
  /// strips before decoding via the URL-safe alphabet.
  static Uint8List _base64UrlDecode(String encoded) {
    final padLength = (4 - encoded.length % 4) % 4;
    final padded = encoded + ('=' * padLength);
    return base64Url.decode(padded);
  }
}

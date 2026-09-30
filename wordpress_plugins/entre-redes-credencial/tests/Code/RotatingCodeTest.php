<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Code;

use EntreRedes\Credencial\Code\RotatingCode;
use PHPUnit\Framework\TestCase;

/**
 * RotatingCode has two independent, stateless responsibilities (design D4):
 *
 *   - seedFor(): derives the per-credential `code_seed` the client caches —
 *     `b64url(HMAC-SHA256($secret, "code-seed|v1|" . $credentialId))`.
 *   - code(): TOTP-SHA256, 30s step, 6 digits, RFC 6238 dynamic truncation,
 *     computed FROM that seed — the same algorithm the Flutter client (slice
 *     3a) must reproduce byte-for-byte with no network call per tick (spec
 *     "Rotating Liveness Code"). Every vector below was cross-checked against
 *     an independent Python reference (hmac+hashlib), not just against this
 *     class's own output, and is kept here as the SHARED fixture slice 3a's
 *     Dart implementation must match.
 */
class RotatingCodeTest extends TestCase {

    private const SEED = 'c2hhcmVkLXRlc3QtdmVjdG9yLXNlZWQtMDAx';

    public function test_code_matches_shared_vectors(): void {
        $this->assertSame( '371289', RotatingCode::code( self::SEED, 0 ) );
        $this->assertSame( '267226', RotatingCode::code( self::SEED, 59 ) );
        $this->assertSame( '438525', RotatingCode::code( self::SEED, 60 ) );
        $this->assertSame( '169218', RotatingCode::code( self::SEED, 1_700_000_000 ) );
    }

    public function test_code_is_stable_within_the_same_30s_step(): void {
        $this->assertSame(
            RotatingCode::code( self::SEED, 1_700_000_015 ),
            RotatingCode::code( self::SEED, 1_700_000_030 ),
            'Both instants fall in the same 30s window (counter 56666667) — the code must not change mid-window.'
        );
    }

    public function test_code_is_always_six_digits_zero_padded(): void {
        for ( $ts = 0; $ts < 3000; $ts += 30 ) {
            $this->assertMatchesRegularExpression( '/^\d{6}$/', RotatingCode::code( self::SEED, $ts ) );
        }
    }

    public function test_seed_for_matches_shared_vectors(): void {
        $this->assertSame(
            'Er2tte121cnFFsA8kjrO_6dBKCtFlReqinJOlnBUH4Y',
            RotatingCode::seedFor( 'test-secret-abc', '11111111-1111-1111-1111-111111111111' )
        );
        $this->assertSame(
            '4v3Y2BFabxf7YjXFZEqSjzdyxGkZBhfOoCiDfvGE_0E',
            RotatingCode::seedFor( 'another-secret', '22222222-2222-2222-2222-222222222222' )
        );
    }

    public function test_seed_for_is_base64url_with_no_padding(): void {
        $seed = RotatingCode::seedFor( 'a-secret', 'some-credential-id' );

        $this->assertDoesNotMatchRegularExpression( '/[+\/=]/', $seed, 'code_seed must be URL-safe and unpadded.' );
    }
}

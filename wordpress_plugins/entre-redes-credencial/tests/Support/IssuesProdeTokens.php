<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Support;

use Firebase\JWT\JWT;

/**
 * Shared prode access-token fixture, used by every test that has to sign a
 * token this plugin will verify (TokenVerifierTest, CredencialAuthorizerTest).
 *
 * This payload shape IS the contract with entre-redes-prode's own
 * Auth\JwtService (see TokenVerifier's class docblock) — keeping it as ONE
 * copy is what stops the two suites from silently drifting into testing two
 * different contracts. Copied from entre-redes-cambios/tests/Support/IssuesProdeTokens.php.
 *
 * Using classes must declare `private string $privateKeyPem;` themselves
 * (set in their own setUp(), since key generation is per-test-class, not
 * shared state this trait should own).
 */
trait IssuesProdeTokens {

    /**
     * A fixed instant as a Unix epoch, which is what TokenVerifier takes.
     *
     * The signature uses an epoch on purpose: `exp` in a JWT is an epoch, so
     * comparing epoch to epoch leaves no timezone to misread. Tests spell the
     * instant out in UTC here only for readability.
     */
    private static function utc( string $utcDatetime ): int {
        return ( new \DateTimeImmutable( $utcDatetime, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function issueToken( array $overrides = [], ?string $signingKey = null ): string {
        $payload = array_merge(
            [
                'iss'       => 'http://example.com/wp-json/entre-redes/v1/prode',
                'aud'       => 'tenant-1',
                'sub'       => '42',
                'typ'       => 'prode_access',
                'sv'        => 3,
                'player_id' => 777,
                'iat'       => strtotime( '2026-09-26 12:00:00 UTC' ),
                'exp'       => strtotime( '2026-09-26 12:15:00 UTC' ),
            ],
            $overrides
        );

        return JWT::encode( $payload, $signingKey ?? $this->privateKeyPem, 'RS256', 'test-kid' );
    }
}

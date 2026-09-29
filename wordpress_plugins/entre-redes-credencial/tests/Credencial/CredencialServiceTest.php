<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Credencial;

use EntreRedes\Credencial\Code\RotatingCode;
use EntreRedes\Credencial\Credencial\CredencialService;
use EntreRedes\Credencial\Credencial\IssuanceRepository;
use EntreRedes\Credencial\Migrations\InitialSchema;
use EntreRedes\Credencial\Observability\InMemoryEventLog;
use EntreRedes\Credencial\Player\PlayerReader;
use EntreRedes\Credencial\Player\TeamResolver;
use PHPUnit\Framework\TestCase;

/**
 * CredencialService orchestrates PlayerReader + TeamResolver +
 * IssuanceRepository + RotatingCode into the GET response's `state` (design
 * D14/D15, spec "Eligibility Determination" / "Approved Photo Gate" /
 * "Credential Payload and Display" / "Rotating Liveness Code").
 *
 * Uses the REAL PlayerReader and IssuanceRepository against the shared
 * SQLite shim (same convention as entre-redes-cambios's controller tests —
 * repositories are exercised for real, only TeamResolver, the one seam this
 * plugin does not own the implementation of, is faked).
 */
class CredencialServiceTest extends TestCase {

    private const SECRET = 'test-secret';
    private const NOW    = 1_800_000_000;

    private CredencialService $service;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_issuance" );

        $this->service = new CredencialService(
            new PlayerReader(),
            $this->fakeTeamResolver( [ 'id' => 5, 'name' => 'Boca Juniors' ] ),
            new IssuanceRepository( $wpdb, new InMemoryEventLog() ),
            self::SECRET
        );
    }

    protected function tearDown(): void {
        $GLOBALS['wp_test_posts']               = [];
        $GLOBALS['wp_test_postmeta']             = [];
        $GLOBALS['wp_test_post_thumbnail_urls']  = [];

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_issuance" );
    }

    private function fakeTeamResolver( ?array $team ): TeamResolver {
        return new class( $team ) implements TeamResolver {
            public function __construct( private readonly ?array $team ) {
            }

            public function resolve( int $playerId ): ?array {
                return $this->team;
            }
        };
    }

    private function seedEligiblePlayerWithPhoto( int $id, array $post = [], array $meta = [] ): void {
        $GLOBALS['wp_test_posts'][ $id ] = array_merge(
            [ 'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => 'Jugador ' . $id, 'post_date' => '2000-01-01 00:00:00' ],
            $post
        );
        foreach ( array_merge( [ 'dni' => '30111222', 'caracter' => 'Padre Alumno' ], $meta ) as $key => $value ) {
            $GLOBALS['wp_test_postmeta'][ $id ][ $key ] = [ $value ];
        }
        $GLOBALS['wp_test_post_thumbnail_urls'][ $id ] = 'https://example.com/photo.jpg';
    }

    public function test_no_matching_sp_player_is_not_a_player(): void {
        $state = $this->service->resolve( 999, 1, self::NOW );

        $this->assertSame( 'not_a_player', $state->toArray()['state'] );
    }

    public function test_blocked_estado_returns_blocked_with_no_credential(): void {
        $this->seedEligiblePlayerWithPhoto( 1, [], [ 'estado' => 'Inhabilitado' ] );

        $state = $this->service->resolve( 1, 1, self::NOW )->toArray();

        $this->assertSame( 'blocked', $state['state'] );
        $this->assertNull( $state['credential'] );
    }

    public function test_eligible_without_a_photo_is_no_photo(): void {
        $GLOBALS['wp_test_posts'][1] = [
            'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => 'Jugador 1', 'post_date' => '2000-01-01 00:00:00',
        ];
        // Deliberately no wp_test_post_thumbnail_urls[1] set.

        $state = $this->service->resolve( 1, 1, self::NOW )->toArray();

        $this->assertSame( 'no_photo', $state['state'] );
        $this->assertNull( $state['credential'] );
    }

    public function test_active_player_gets_a_full_credential_payload(): void {
        $this->seedEligiblePlayerWithPhoto( 1 );

        $state = $this->service->resolve( 1, 42, self::NOW )->toArray();

        $this->assertSame( 'active', $state['state'] );
        $credential = $state['credential'];

        $this->assertSame( 1, $credential['player_id'] );
        $this->assertSame( 'Jugador 1', $credential['full_name'] );
        $this->assertSame( '30111222', $credential['dni'] );
        $this->assertSame( 'Padre Alumno', $credential['caracter'] );
        $this->assertSame( 'https://example.com/photo.jpg', $credential['photo']['url'] );
        $this->assertSame( [ 'id' => 5, 'name' => 'Boca Juniors', 'kind' => 'team' ], $credential['team'] );
        $this->assertSame(
            [ 'alg' => RotatingCode::ALG, 'step' => RotatingCode::STEP, 'digits' => RotatingCode::DIGITS ],
            $credential['code']
        );
        $this->assertSame(
            RotatingCode::seedFor( self::SECRET, $credential['id'] ),
            $credential['code_seed']
        );
    }

    public function test_credential_id_is_stable_across_repeated_resolutions(): void {
        $this->seedEligiblePlayerWithPhoto( 1 );

        $first  = $this->service->resolve( 1, 42, self::NOW )->toArray()['credential']['id'];
        $second = $this->service->resolve( 1, 42, self::NOW + 30 )->toArray()['credential']['id'];

        $this->assertSame( $first, $second );
    }

    public function test_team_unavailable_renders_credential_without_a_team_badge(): void {
        global $wpdb;

        $this->service = new CredencialService(
            new PlayerReader(),
            $this->fakeTeamResolver( null ),
            new IssuanceRepository( $wpdb, new InMemoryEventLog() ),
            self::SECRET
        );
        $this->seedEligiblePlayerWithPhoto( 1 );

        $state = $this->service->resolve( 1, 42, self::NOW )->toArray();

        $this->assertSame( 'active', $state['state'] );
        $this->assertNull( $state['credential']['team'] );
    }

    public function test_empty_caracter_renders_without_failing(): void {
        $this->seedEligiblePlayerWithPhoto( 1, [], [ 'caracter' => '' ] );

        $state = $this->service->resolve( 1, 42, self::NOW )->toArray();

        $this->assertSame( 'active', $state['state'] );
        $this->assertNull( $state['credential']['caracter'] );
    }

    public function test_out_of_range_birth_date_renders_null_without_failing(): void {
        $this->seedEligiblePlayerWithPhoto( 1, [ 'post_date' => '2020-01-01 00:00:00' ] ); // < 18yo at self::NOW

        $state = $this->service->resolve( 1, 42, self::NOW )->toArray();

        $this->assertSame( 'active', $state['state'] );
        $this->assertNull( $state['credential']['birth_date'] );
    }
}

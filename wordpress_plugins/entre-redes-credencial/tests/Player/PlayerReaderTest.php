<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Player;

use EntreRedes\Credencial\Player\PlayerReader;
use PHPUnit\Framework\TestCase;

/**
 * PlayerReader is the read model for player-credential eligibility (design
 * D14): existence/type, `estado` (blocks on exactly "Inhabilitado", trimmed,
 * case-insensitive), `dni`, `caracter` (one of 5 exact values or null — spec
 * precondition), and a birth date derived from `post_date`, nulled outside
 * [18, 100] years old at $now (never an error — spec "Caracter is empty").
 *
 * All facts read directly via get_post()/get_post_meta() — no ACF field-key
 * lookups (design P2).
 */
class PlayerReaderTest extends TestCase {

    private PlayerReader $reader;

    protected function setUp(): void {
        $this->reader = new PlayerReader();
    }

    protected function tearDown(): void {
        $GLOBALS['wp_test_posts']    = [];
        $GLOBALS['wp_test_postmeta'] = [];
    }

    private function seedPlayer( int $id, array $post = [], array $meta = [] ): void {
        $GLOBALS['wp_test_posts'][ $id ] = array_merge(
            [ 'post_type' => 'sp_player', 'post_status' => 'publish', 'post_title' => 'Jugador ' . $id, 'post_date' => '2000-01-01 00:00:00' ],
            $post
        );
        foreach ( $meta as $key => $value ) {
            $GLOBALS['wp_test_postmeta'][ $id ][ $key ] = [ $value ];
        }
    }

    private static function utc( string $datetime ): int {
        return ( new \DateTimeImmutable( $datetime, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
    }

    public function test_no_matching_sp_player_resolves_to_null(): void {
        $this->assertNull( $this->reader->resolve( 999, self::utc( '2026-01-01 00:00:00' ) ) );
    }

    public function test_a_post_of_another_type_is_not_a_player(): void {
        $this->seedPlayer( 1, [ 'post_type' => 'sp_team' ] );

        $this->assertNull( $this->reader->resolve( 1, self::utc( '2026-01-01 00:00:00' ) ) );
    }

    public function test_eligible_player_with_empty_estado(): void {
        $this->seedPlayer( 1, [ 'post_title' => 'Juan Perez' ], [ 'dni' => '30111222', 'caracter' => 'Padre Alumno' ] );

        $record = $this->reader->resolve( 1, self::utc( '2026-01-01 00:00:00' ) );

        $this->assertNotNull( $record );
        $this->assertSame( 1, $record->playerId() );
        $this->assertSame( 'Juan Perez', $record->fullName() );
        $this->assertSame( '30111222', $record->dni() );
        $this->assertSame( 'Padre Alumno', $record->caracterOrNull() );
        $this->assertFalse( $record->isBlocked() );
    }

    /** @dataProvider blockingEstadoProvider */
    public function test_estado_inhabilitado_blocks_regardless_of_case_or_padding( string $estado ): void {
        $this->seedPlayer( 1, [], [ 'estado' => $estado ] );

        $this->assertTrue( $this->reader->resolve( 1, self::utc( '2026-01-01 00:00:00' ) )->isBlocked() );
    }

    public static function blockingEstadoProvider(): array {
        return [
            [ 'Inhabilitado' ],
            [ 'inhabilitado' ],
            [ 'INHABILITADO' ],
            [ '  Inhabilitado  ' ],
        ];
    }

    /** @dataProvider nonBlockingEstadoProvider */
    public function test_anything_else_is_eligible( string $estado ): void {
        $this->seedPlayer( 1, [], [ 'estado' => $estado ] );

        $this->assertFalse( $this->reader->resolve( 1, self::utc( '2026-01-01 00:00:00' ) )->isBlocked() );
    }

    public static function nonBlockingEstadoProvider(): array {
        return [ [ '' ], [ 'Habilitado' ], [ 'algo-inesperado' ] ];
    }

    public function test_caracter_must_exactly_match_one_of_the_five_values(): void {
        $this->seedPlayer( 1, [], [ 'caracter' => 'padre alumno' ] ); // wrong case, not exact

        $this->assertNull( $this->reader->resolve( 1, self::utc( '2026-01-01 00:00:00' ) )->caracterOrNull() );
    }

    public function test_caracter_empty_is_null_without_failing(): void {
        $this->seedPlayer( 1 );

        $record = $this->reader->resolve( 1, self::utc( '2026-01-01 00:00:00' ) );

        $this->assertNull( $record->caracterOrNull() );
    }

    public function test_birth_date_within_bounds_is_reported(): void {
        $this->seedPlayer( 1, [ 'post_date' => '2000-06-15 00:00:00' ] );

        // Exactly 25 at $now.
        $record = $this->reader->resolve( 1, self::utc( '2025-06-16 00:00:00' ) );

        $this->assertSame( '2000-06-15', $record->birthDateOrNull() );
    }

    public function test_birth_date_under_18_is_null(): void {
        $this->seedPlayer( 1, [ 'post_date' => '2015-01-01 00:00:00' ] );

        $record = $this->reader->resolve( 1, self::utc( '2026-01-01 00:00:00' ) ); // 11 years old

        $this->assertNull( $record->birthDateOrNull() );
    }

    public function test_birth_date_over_100_is_null(): void {
        $this->seedPlayer( 1, [ 'post_date' => '1900-01-01 00:00:00' ] );

        $record = $this->reader->resolve( 1, self::utc( '2026-01-01 00:00:00' ) ); // 126 years old

        $this->assertNull( $record->birthDateOrNull() );
    }
}

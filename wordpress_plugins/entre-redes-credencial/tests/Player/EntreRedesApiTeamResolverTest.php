<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Player;

use EntreRedes\Credencial\Player\EntreRedesApiTeamResolver;
use PHPUnit\Framework\TestCase;

/**
 * EntreRedesApiTeamResolver never calls entre-redes-api's real
 * obtener_equipo_desde_rest() in a unit test — it is injected as a callable,
 * exactly the seam design D14's own docblock requires ("this spec does not
 * prescribe the data source"). The function_exists() guard itself (used when
 * no callable is injected) is covered by
 * test_falls_back_to_null_when_no_resolver_function_is_injected_and_none_exists,
 * which relies on this test suite running standalone, so
 * obtener_equipo_desde_rest() is never actually defined here.
 */
class EntreRedesApiTeamResolverTest extends TestCase {

    public function test_resolves_id_and_name_from_the_injected_function(): void {
        $resolver = new EntreRedesApiTeamResolver(
            static fn ( int $playerId ): array => [ 42, 'Equipo Rojo', 'https://example.com/escudo.png' ]
        );

        $this->assertSame( [ 'id' => 42, 'name' => 'Equipo Rojo' ], $resolver->resolve( 7 ) );
    }

    public function test_returns_null_when_the_injected_function_returns_an_empty_array(): void {
        $resolver = new EntreRedesApiTeamResolver( static fn ( int $playerId ): array => [] );

        $this->assertNull( $resolver->resolve( 7 ) );
    }

    public function test_returns_null_when_team_id_is_not_positive(): void {
        $resolver = new EntreRedesApiTeamResolver(
            static fn ( int $playerId ): array => [ 0, 'Sin Equipo', '' ]
        );

        $this->assertNull( $resolver->resolve( 7 ) );
    }

    public function test_returns_null_when_team_name_is_empty(): void {
        $resolver = new EntreRedesApiTeamResolver(
            static fn ( int $playerId ): array => [ 5, '', '' ]
        );

        $this->assertNull( $resolver->resolve( 7 ) );
    }

    public function test_falls_back_to_null_when_no_resolver_function_is_injected_and_none_exists(): void {
        $this->assertFalse(
            function_exists( 'obtener_equipo_desde_rest' ),
            'This test assumes entre-redes-api\'s function is never loaded in this unit-test process.'
        );

        $resolver = new EntreRedesApiTeamResolver();

        $this->assertNull( $resolver->resolve( 7 ) );
    }
}

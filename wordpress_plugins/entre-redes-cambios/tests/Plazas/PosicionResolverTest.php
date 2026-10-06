<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas;

use EntreRedes\Cambios\Plazas\PosicionResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PosicionResolver — pure resolution logic over
 * `wp_get_object_terms()`'s shimmed return shape (see tests/wp-shim.php's own
 * docblock for `$wp_test_position_terms`, the data-driven global these tests
 * seed).
 *
 * The behavior under test is deliberately a literal mirror of
 * `entre-redes-api`'s own `/jugadores` "main position" choice — see
 * PosicionResolver's own class docblock for exactly what is being mirrored
 * and why.
 */
class PosicionResolverTest extends TestCase {

    protected function tearDown(): void {
        global $wp_test_position_terms;
        $wp_test_position_terms = [];
    }

    public function test_a_single_recognized_position_resolves_to_its_name(): void {
        global $wp_test_position_terms;
        $wp_test_position_terms = [ 800 => [ 3 ] ]; // 3 => Arquero

        $resultado = ( new PosicionResolver() )->resolverParaIds( [ 800 ] );

        $this->assertSame( [ 800 => 'Arquero' ], $resultado );
    }

    public function test_a_player_with_no_terms_at_all_falls_back_to_sin_posicion(): void {
        global $wp_test_position_terms;
        $wp_test_position_terms = [];

        $resultado = ( new PosicionResolver() )->resolverParaIds( [ 800 ] );

        $this->assertSame( [ 800 => PosicionResolver::SIN_POSICION ], $resultado );
    }

    public function test_only_unrecognized_term_ids_fall_back_to_sin_posicion(): void {
        global $wp_test_position_terms;
        // 999 is not a key in PosicionResolver::POS_MAP — e.g. "capitán" or
        // some other sp_position term entre-redes-api's own $pos_map also
        // does not recognize as a playing position.
        $wp_test_position_terms = [ 800 => [ 999 ] ];

        $resultado = ( new PosicionResolver() )->resolverParaIds( [ 800 ] );

        $this->assertSame( [ 800 => PosicionResolver::SIN_POSICION ], $resultado );
    }

    /**
     * *** THE EXACT TIE-BREAK THIS CLASS EXISTS TO MIRROR ***
     * A player holding MORE THAN ONE recognized `sp_position` term resolves
     * to whichever one `wp_get_object_terms()` returns FIRST for that
     * player — never the lowest term id, never alphabetical by name. Here
     * term id 8 (Mediocampista) is seeded BEFORE term id 3 (Arquero) in the
     * stub's own return order, so Mediocampista must win even though 3 < 8 —
     * proving the loop picks "first in returned order", exactly like
     * `entre-redes-api`'s own `foreach ($positions_array as $pid) { ...
     * break; }` does.
     */
    public function test_multiple_positions_the_first_one_in_returned_order_wins_not_the_lowest_id(): void {
        global $wp_test_position_terms;
        $wp_test_position_terms = [ 800 => [ 8, 3 ] ]; // Mediocampista, then Arquero

        $resultado = ( new PosicionResolver() )->resolverParaIds( [ 800 ] );

        $this->assertSame( [ 800 => 'Mediocampista' ], $resultado );
    }

    /**
     * Same tie-break, reversed order — proves the previous test was not a
     * coincidence of array iteration, but genuinely order-dependent.
     */
    public function test_multiple_positions_reversed_order_flips_the_winner(): void {
        global $wp_test_position_terms;
        $wp_test_position_terms = [ 800 => [ 3, 8 ] ]; // Arquero, then Mediocampista

        $resultado = ( new PosicionResolver() )->resolverParaIds( [ 800 ] );

        $this->assertSame( [ 800 => 'Arquero' ], $resultado );
    }

    /**
     * A player's unrecognized term ids before a recognized one are simply
     * skipped — the loop walks the WHOLE list looking for the first
     * recognized id, not just the very first element.
     */
    public function test_an_unrecognized_term_before_a_recognized_one_is_skipped(): void {
        global $wp_test_position_terms;
        $wp_test_position_terms = [ 800 => [ 999, 9 ] ]; // unrecognized, then Delantero

        $resultado = ( new PosicionResolver() )->resolverParaIds( [ 800 ] );

        $this->assertSame( [ 800 => 'Delantero' ], $resultado );
    }

    /**
     * Batched across several players in ONE call — each player's own
     * position resolves independently, proving the grouping-by-object_id
     * step does not cross-contaminate between players.
     */
    public function test_resolves_several_players_independently_in_one_call(): void {
        global $wp_test_position_terms;
        $wp_test_position_terms = [
            800 => [ 3 ],    // Arquero
            801 => [ 9 ],    // Delantero
            802 => [],       // no terms -> Sin Posicion
        ];

        $resultado = ( new PosicionResolver() )->resolverParaIds( [ 800, 801, 802 ] );

        $this->assertSame( [
            800 => 'Arquero',
            801 => 'Delantero',
            802 => PosicionResolver::SIN_POSICION,
        ], $resultado );
    }

    /**
     * Every id passed in MUST be present in the result, even one this
     * resolver could not find any terms for at all — a caller must never
     * have to guess whether a missing key means "unresolved" or "not
     * requested".
     */
    public function test_every_requested_id_is_present_in_the_result_even_with_no_seeded_data(): void {
        global $wp_test_position_terms;
        $wp_test_position_terms = [];

        $resultado = ( new PosicionResolver() )->resolverParaIds( [ 800, 801 ] );

        $this->assertArrayHasKey( 800, $resultado );
        $this->assertArrayHasKey( 801, $resultado );
    }

    public function test_an_empty_list_of_ids_returns_an_empty_map(): void {
        $resultado = ( new PosicionResolver() )->resolverParaIds( [] );

        $this->assertSame( [], $resultado );
    }

    // -------------------------------------------------------------------------
    // esPosicionDeArquero() / esPosicionDelArqueroTitular() — the two
    // deliberately distinct goalkeeper predicates behind
    // Dictamen\Reglas\ArqueroNoOcupaPlazaDeCampo. See PosicionResolver's own
    // docblocks for each method.
    // -------------------------------------------------------------------------

    public function test_es_posicion_de_arquero_is_true_for_the_titular_goalkeeper_position(): void {
        $this->assertTrue( PosicionResolver::esPosicionDeArquero( PosicionResolver::POSICION_ARQUERO ) );
    }

    /**
     * THE candidate definition includes the BACKUP goalkeeper too — the
     * process owner confirmed explicitly that a backup goalkeeper is a
     * goalkeeper for this rule.
     */
    public function test_es_posicion_de_arquero_is_true_for_the_backup_goalkeeper_position(): void {
        $this->assertTrue( PosicionResolver::esPosicionDeArquero( PosicionResolver::POSICION_ARQUERO_SUPLENTE ) );
    }

    public function test_es_posicion_de_arquero_is_false_for_a_field_position(): void {
        $this->assertFalse( PosicionResolver::esPosicionDeArquero( 'Defensor' ) );
        $this->assertFalse( PosicionResolver::esPosicionDeArquero( PosicionResolver::SIN_POSICION ) );
    }

    public function test_es_posicion_del_arquero_titular_is_true_only_for_the_titular_goalkeeper_position(): void {
        $this->assertTrue( PosicionResolver::esPosicionDelArqueroTitular( PosicionResolver::POSICION_ARQUERO ) );
    }

    /**
     * *** THE INVARIANT THIS WHOLE RULE DEPENDS ON ***
     * A plaza whose titular resolves as "Arquero Sup." (term 125) must be
     * treated as a FIELD plaza by this predicate — NOT as "the goalkeeper's
     * plaza" — even though that SAME position name makes a CANDIDATE a
     * goalkeeper under esPosicionDeArquero() above. Unifying the two
     * predicates would silently create up to 13 extra "goal plazas" in
     * season 2026, breaking the one-titular-goalkeeper-per-team invariant
     * that makes "the goal" identifiable at all. See PosicionResolver's own
     * class docblock for the full reasoning.
     */
    public function test_es_posicion_del_arquero_titular_is_false_for_the_backup_goalkeeper_position(): void {
        $this->assertFalse( PosicionResolver::esPosicionDelArqueroTitular( PosicionResolver::POSICION_ARQUERO_SUPLENTE ) );
    }

    public function test_es_posicion_del_arquero_titular_is_false_for_a_field_position(): void {
        $this->assertFalse( PosicionResolver::esPosicionDelArqueroTitular( 'Delantero' ) );
        $this->assertFalse( PosicionResolver::esPosicionDelArqueroTitular( PosicionResolver::SIN_POSICION ) );
    }
}

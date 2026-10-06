<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\Reglas\ArqueroNoOcupaPlazaDeCampo;
use EntreRedes\Cambios\Tests\Support\BuildsDictamenFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ArqueroNoOcupaPlazaDeCampo — see that class's own docblock
 * for the full rule and the two deliberately distinct "goalkeeper" /
 * "goalkeeper's plaza" definitions these tests pin.
 */
class ArqueroNoOcupaPlazaDeCampoTest extends TestCase {
    use BuildsDictamenFixtures;

    public function test_fails_when_a_titular_goalkeeper_is_entrante_for_a_field_plaza(): void {
        $ctx = $this->ctxFavorableSustitucion( [
            'entranteEsArquero' => true,
            'plazaEsDelArquero' => false,
        ] );

        $motivo = ( new ArqueroNoOcupaPlazaDeCampo() )->evaluate( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'arquero_no_ocupa_plaza_de_campo', $motivo->codigo() );
    }

    /**
     * THE candidate definition includes a BACKUP goalkeeper (term 125,
     * "Arquero Sup.") — the process owner confirmed this explicitly. This
     * rule itself only ever reads `entranteEsArquero()`'s boolean (which
     * Plazas\PosicionResolver::esPosicionDeArquero() already folds term 3
     * AND term 125 into), so this test pins that a backup goalkeeper is
     * refused the SAME WAY a titular goalkeeper is — the actual term-125
     * resolution into `entranteEsArquero() === true` is pinned separately,
     * at PosicionResolverTest and DictamenContextAssemblerTest.
     */
    public function test_fails_when_entrante_resolved_as_a_backup_goalkeeper_is_refused_the_same_way_as_a_titular_goalkeeper(): void {
        $ctx = $this->ctxFavorableSustitucion( [
            'entranteEsArquero' => true, // what a term-125 resolution produces
            'plazaEsDelArquero' => false,
        ] );

        $motivo = ( new ArqueroNoOcupaPlazaDeCampo() )->evaluate( $ctx );

        $this->assertNotNull( $motivo, 'A backup goalkeeper (entranteEsArquero=true, same as a titular goalkeeper) must also be refused for a field plaza.' );
        $this->assertSame( 'arquero_no_ocupa_plaza_de_campo', $motivo->codigo() );
    }

    /**
     * THE ASYMMETRY, PINNED: a field player taking over the goalkeeper's OWN
     * plaza is explicitly legitimate today (the pending "exención del arco"
     * direction) — this rule must never block it, regardless of
     * plazaEsDelArquero().
     */
    public function test_does_not_fail_when_a_field_player_is_entrante_for_the_goalkeepers_plaza(): void {
        $ctx = $this->ctxFavorableSustitucion( [
            'entranteEsArquero' => false,
            'plazaEsDelArquero' => true,
        ] );

        $this->assertNull( ( new ArqueroNoOcupaPlazaDeCampo() )->evaluate( $ctx ) );
    }

    /**
     * Same asymmetry, for a field plaza — the default, uneventful case:
     * a field player entering a field plaza is never this rule's concern.
     */
    public function test_does_not_fail_when_a_field_player_is_entrante_for_a_field_plaza(): void {
        $ctx = $this->ctxFavorableSustitucion( [
            'entranteEsArquero' => false,
            'plazaEsDelArquero' => false,
        ] );

        $this->assertNull( ( new ArqueroNoOcupaPlazaDeCampo() )->evaluate( $ctx ) );
    }

    public function test_does_not_fail_when_a_goalkeeper_is_entrante_for_the_goalkeepers_plaza(): void {
        $ctx = $this->ctxFavorableSustitucion( [
            'entranteEsArquero' => true,
            'plazaEsDelArquero' => true,
        ] );

        $this->assertNull( ( new ArqueroNoOcupaPlazaDeCampo() )->evaluate( $ctx ) );
    }

    /**
     * A `regreso` never carries an entrante — DictamenContext::entranteEsArquero()
     * is always false by convention for one (see that accessor's own
     * docblock) — so this rule never fires for one, regardless of
     * plazaEsDelArquero().
     */
    public function test_does_not_apply_to_a_regreso(): void {
        $ctx = $this->ctxFavorableRegreso( [
            'entranteEsArquero' => false,
            'plazaEsDelArquero' => true,
        ] );

        $this->assertNull( ( new ArqueroNoOcupaPlazaDeCampo() )->evaluate( $ctx ) );
    }
}

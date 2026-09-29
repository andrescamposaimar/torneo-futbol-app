<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Credencial;

use EntreRedes\Credencial\Credencial\TeamKind;
use PHPUnit\Framework\TestCase;

/**
 * TeamKind::fromName() — design D15 ("team.kind from the name prefix").
 * Tolerant of leading/trailing whitespace and dash variants (regular hyphen,
 * en dash U+2013, em dash U+2014) because real list names in production use
 * inconsistent dashes (see engram note "Ligas 2026 Clausura con guion
 * largo" — Zona B/C use U+2013 elsewhere in this codebase's own data).
 */
class TeamKindTest extends TestCase {

    public function test_regular_team_name_is_kind_team(): void {
        $this->assertSame( TeamKind::TEAM, TeamKind::fromName( 'Boca Juniors' ) );
    }

    public function test_waiting_list_prefix_is_kind_waiting_list(): void {
        $this->assertSame( TeamKind::WAITING_LIST, TeamKind::fromName( 'Lista de Espera 2026' ) );
    }

    public function test_not_registered_prefix_is_kind_not_registered(): void {
        $this->assertSame( TeamKind::NOT_REGISTERED, TeamKind::fromName( 'Lista de No Inscriptos 2026' ) );
    }

    public function test_prefix_match_is_case_insensitive(): void {
        $this->assertSame( TeamKind::WAITING_LIST, TeamKind::fromName( 'LISTA DE ESPERA 2026' ) );
        $this->assertSame( TeamKind::NOT_REGISTERED, TeamKind::fromName( 'lista de no inscriptos 2026' ) );
    }

    public function test_tolerant_of_leading_and_trailing_whitespace(): void {
        $this->assertSame( TeamKind::WAITING_LIST, TeamKind::fromName( '   Lista de Espera 2026  ' ) );
    }

    public function test_tolerant_of_en_dash_between_words(): void {
        $this->assertSame( TeamKind::WAITING_LIST, TeamKind::fromName( "Lista\u{2013}de Espera 2026" ) );
    }

    public function test_tolerant_of_em_dash_and_hyphen_between_words(): void {
        $this->assertSame( TeamKind::WAITING_LIST, TeamKind::fromName( "Lista\u{2014}de Espera 2026" ) );
        $this->assertSame( TeamKind::WAITING_LIST, TeamKind::fromName( 'Lista-de Espera 2026' ) );
    }

    public function test_tolerant_of_collapsed_multiple_spaces(): void {
        $this->assertSame( TeamKind::WAITING_LIST, TeamKind::fromName( 'Lista   de    Espera 2026' ) );
    }

    public function test_empty_name_is_kind_team(): void {
        $this->assertSame( TeamKind::TEAM, TeamKind::fromName( '' ) );
    }
}

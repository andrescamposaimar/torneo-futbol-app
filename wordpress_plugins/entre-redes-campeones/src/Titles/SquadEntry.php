<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Titles;

/**
 * Value object representing one row of campeones_plantel.
 *
 * This is a deliberate departure from the sibling entre-redes-prode plugin,
 * which has no value objects at all — do not "simplify" this back to a raw
 * array. The governing convention here is this plugin's own design, which is
 * value-object-heavy: PlayerKey, LinkResolution, RegisteredPlayer and
 * ParsedBlock all arrive in later slices, and TitleRecord already set the
 * precedent for campeones_titulo.
 *
 * A value object is a type boundary, not decoration. A review of this
 * repository found five wpdb reads that omitted ARRAY_A and would have
 * returned stdClass in production; TitleRecord::fromRow(array $row) under
 * declare(strict_types=1) turns exactly that mistake into a loud TypeError
 * instead of letting a wrong type propagate silently. SquadEntry::fromRow()
 * earns its place the same way.
 */
final class SquadEntry {

    public function __construct(
        public readonly int $tituloId,
        public readonly int $orden,
        public readonly string $jugadorNombre,
        public readonly bool $esCapitan = false,
        public readonly string $estadoVinculo = 'sin_candidato',
        public readonly ?int $jugadorId = null,
        public readonly string $jugadorNombreNorm = '',
        public readonly ?string $candidatosJson = null,
        public readonly ?int $id = null
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow( array $row ): self {
        return new self(
            (int) $row['titulo_id'],
            (int) $row['orden'],
            (string) $row['jugador_nombre'],
            (bool) $row['es_capitan'],
            (string) $row['estado_vinculo'],
            null === $row['jugador_id'] ? null : (int) $row['jugador_id'],
            (string) $row['jugador_nombre_norm'],
            null === $row['candidatos_json'] ? null : (string) $row['candidatos_json'],
            (int) $row['id']
        );
    }
}

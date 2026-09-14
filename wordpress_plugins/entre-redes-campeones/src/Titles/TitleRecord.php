<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Titles;

/**
 * Value object representing one row of campeones_titulo.
 */
final class TitleRecord {

    public function __construct(
        public readonly int $id,
        public readonly int $anio,
        public readonly string $zona,
        public readonly string $posicion,
        public readonly string $equipoNombre,
        public readonly string $createdAt,
        public readonly string $updatedAt
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow( array $row ): self {
        return new self(
            (int) $row['id'],
            (int) $row['anio'],
            (string) $row['zona'],
            (string) $row['posicion'],
            (string) $row['equipo_nombre'],
            (string) $row['created_at'],
            (string) $row['updated_at']
        );
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen;

/**
 * A `Dictamen`, frozen at a moment in time, in the exact shape
 * `Solicitudes\SolicitudRepository` persists into `cambios_solicitud`'s
 * `dictamen_original` / `dictamen_aplicado` columns.
 *
 * *** WHY THIS EXISTS SEPARATELY FROM `Dictamen` ITSELF ***
 * `Dictamen` (see that class's docblock) is a pure in-memory value —
 * `Motivo[]`, nothing else — with no notion of WHEN it was produced and no
 * serialization contract. A persisted solicitud needs both: the moment the
 * dictamen was evaluated (`evaluadoAt`), and a stable JSON shape that
 * survives a round trip through `TEXT` columns. Rather than teach `Dictamen`
 * itself about timestamps or JSON — which no other consumer of that class
 * needs — this wraps one immutably.
 *
 * *** TWO SNAPSHOTS, ONE PURPOSE ***
 * `SolicitudRepository::crear()` stores one of these as `dictamen_original`
 * — what was true the moment the solicitud was made. `::publicarLote()`
 * re-evaluates the dictamen against the CURRENT database state right before
 * applying the change, and stores that second evaluation as
 * `dictamen_aplicado` — what was actually true when the change was applied,
 * which is the answer that matters when someone later asks "why was this
 * approved". See `SolicitudRepository`'s own class docblock, "RE-EVALUATING
 * BEFORE APPLYING", for the full reasoning.
 */
final class DictamenSnapshot {

    private bool $procede;

    /** @var array<int, array{codigo: string, mensaje: string, datos: array<string, mixed>}> */
    private array $motivos;

    private string $evaluadoAt;

    /**
     * @param array<int, array{codigo: string, mensaje: string, datos: array<string, mixed>}> $motivos
     */
    private function __construct( bool $procede, array $motivos, string $evaluadoAt ) {
        $this->procede    = $procede;
        $this->motivos    = $motivos;
        $this->evaluadoAt = $evaluadoAt;
    }

    public static function fromDictamen( Dictamen $dictamen, string $evaluadoAt ): self {
        $motivos = array_map(
            static fn ( Motivo $motivo ): array => [
                'codigo'  => $motivo->codigo(),
                'mensaje' => $motivo->mensaje(),
                'datos'   => $motivo->datos(),
            ],
            $dictamen->motivos()
        );

        return new self( $dictamen->procede(), $motivos, $evaluadoAt );
    }

    /**
     * @throws \RuntimeException When $json does not decode to this class's
     *         own shape — a corrupted or hand-edited `dictamen_original` /
     *         `dictamen_aplicado` column is a data problem this class
     *         refuses to guess its way past.
     */
    public static function fromJson( string $json ): self {
        $decoded = json_decode( $json, true );

        if (
            ! is_array( $decoded )
            || ! array_key_exists( 'procede', $decoded )
            || ! array_key_exists( 'motivos', $decoded )
            || ! array_key_exists( 'evaluado_at', $decoded )
            || ! is_array( $decoded['motivos'] )
        ) {
            throw new \RuntimeException(
                'DictamenSnapshot::fromJson(): malformed snapshot — expected keys procede, motivos, evaluado_at.'
            );
        }

        return new self( (bool) $decoded['procede'], $decoded['motivos'], (string) $decoded['evaluado_at'] );
    }

    public function procede(): bool {
        return $this->procede;
    }

    /** @return array<int, array{codigo: string, mensaje: string, datos: array<string, mixed>}> */
    public function motivos(): array {
        return $this->motivos;
    }

    /** @return string[] Every motivo's `codigo`, in the order they were recorded. */
    public function motivoCodigos(): array {
        return array_map( static fn ( array $motivo ): string => $motivo['codigo'], $this->motivos );
    }

    public function evaluadoAt(): string {
        return $this->evaluadoAt;
    }

    /** @return array{procede: bool, motivos: array<int, array{codigo: string, mensaje: string, datos: array<string, mixed>}>, evaluado_at: string} */
    public function toArray(): array {
        return [
            'procede'     => $this->procede,
            'motivos'     => $this->motivos,
            'evaluado_at' => $this->evaluadoAt,
        ];
    }

    /** @throws \JsonException */
    public function toJson(): string {
        return json_encode( $this->toArray(), JSON_THROW_ON_ERROR );
    }
}

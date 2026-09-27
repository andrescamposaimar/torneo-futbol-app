<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen;

/**
 * The engine's output: a DICTAMEN, never an approval — see MotorDeDictamen's
 * class docblock for why `procede()` is named that way and what it does NOT
 * authorize.
 *
 * `procede()` is exactly "no motivos" — there is no separate flag that could
 * drift from the motivos list, so a Dictamen can never claim `procede() ===
 * true` while also holding a motivo, or vice versa.
 *
 * `fechasFaltantesParaLiberacion()` is the one derived datum this slice's
 * spec asks for by name: "cuántas fechas faltan para que la plaza se libere,
 * si ese fue el motivo". It is read generically off `Motivo::datos()` — see
 * that class's docblock — rather than by importing
 * Reglas\RegresoSoloConMinimoCumplido here, so Dictamen never needs to know
 * which concrete Regla produced the motivo it is reading.
 */
final class Dictamen {

    /** @var Motivo[] */
    private array $motivos;

    /**
     * @param Motivo[] $motivos
     */
    private function __construct( array $motivos ) {
        $this->motivos = array_values( $motivos );
    }

    /**
     * @param Motivo[] $motivos Every motivo MotorDeDictamen's rules produced,
     *        already joined — never truncated to the first one. An empty
     *        array yields a favorable dictamen.
     */
    public static function desde( array $motivos ): self {
        return new self( $motivos );
    }

    /**
     * True exactly when there are zero motivos. THIS IS A DICTAMEN, NOT AN
     * APPROVAL — see MotorDeDictamen's class docblock. `true` here means
     * "nothing in the ruleset objects", never "go ahead and apply this
     * automatically".
     */
    public function procede(): bool {
        return empty( $this->motivos );
    }

    /** @return Motivo[] Every motivo found, in the order rules were evaluated. */
    public function motivos(): array {
        return $this->motivos;
    }

    /**
     * The first motivo matching $codigo, or null when none was reported.
     */
    public function motivo( string $codigo ): ?Motivo {
        foreach ( $this->motivos as $motivo ) {
            if ( $codigo === $motivo->codigo() ) {
                return $motivo;
            }
        }

        return null;
    }

    /**
     * How many resolved fechas are still missing before the plaza liberates
     * — null when no motivo reported that datum (either the dictamen
     * procede, or the failing motivo was unrelated to the mínimo), and also
     * null when a motivo reported the count as INDETERMINATE (see
     * Reglas\RegresoSoloConMinimoCumplido's docblock for when that happens).
     * A caller must therefore treat null as "no usable number", never as "0
     * missing" — those are different facts.
     */
    public function fechasFaltantesParaLiberacion(): ?int {
        foreach ( $this->motivos as $motivo ) {
            $datos = $motivo->datos();

            if ( array_key_exists( 'fechasFaltantes', $datos ) ) {
                return null === $datos['fechasFaltantes'] ? null : (int) $datos['fechasFaltantes'];
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen;

/**
 * A single reason a Regla did not clear a SolicitudDeCambio.
 *
 * THE ONE PLACE SPANISH LEAKS INTO THIS SLICE: `mensaje` is UI copy read
 * verbatim by the subcomisión, not code — see class docblock convention in
 * the feature's other classes for why everything else (class/method names,
 * docblocks) stays in English. `codigo` stays a stable, English-ish,
 * machine-comparable string (never translated, never re-derived from the
 * message) so logs and tests can key off it regardless of how `mensaje` is
 * later reworded.
 *
 * `datos` carries whatever a human-facing consumer (or Dictamen itself, e.g.
 * `Dictamen::fechasFaltantesParaLiberacion()`) needs to derive WITHOUT
 * parsing `mensaje`'s Spanish prose — today only
 * Reglas\RegresoSoloConMinimoCumplido sets `['fechasFaltantes' => int|null]`,
 * but any Regla may add its own keys the same way.
 */
final class Motivo {

    private string $codigo;
    private string $mensaje;

    /** @var array<string, mixed> */
    private array $datos;

    /**
     * @param array<string, mixed> $datos
     */
    public function __construct( string $codigo, string $mensaje, array $datos = [] ) {
        $this->codigo  = $codigo;
        $this->mensaje = $mensaje;
        $this->datos   = $datos;
    }

    public function codigo(): string {
        return $this->codigo;
    }

    public function mensaje(): string {
        return $this->mensaje;
    }

    /** @return array<string, mixed> */
    public function datos(): array {
        return $this->datos;
    }
}

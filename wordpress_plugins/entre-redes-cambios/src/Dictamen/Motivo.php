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
 *
 * *** `conMovimiento()` — ATTRIBUTING A MOTIVO TO ITS LEG (0.1.16) ***
 * `DictamenPipeline::evaluateGrupo()` unions motivos from TWO independently-
 * evaluated `DictamenContext`s (the goal leg and the field leg of a grouped
 * goalkeeper reassignment) into one `Dictamen` — see that method's own
 * docblock. Several codigos (`plaza_sin_ocupacion_vigente`,
 * `fuera_de_plazo`, `entrante_es_el_saliente`…) can come from EITHER leg, so
 * a process owner reading the pooled list cannot tell which movement is
 * actually the problem. `conMovimiento()` tags a COPY of this Motivo with
 * which leg produced it — `'arco'` or `'campo'`, see
 * `DictamenPipeline::evaluateGrupo()` for the exact values — in `datos`,
 * never by rewriting `mensaje` (which stays whatever the originating Regla
 * wrote, untouched, so a Regla never needs to know it might be running as
 * part of a grouped request). No Regla ever calls this itself; only the
 * pipeline does, after each leg's Dictamen already exists.
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

    /**
     * Returns a COPY of this Motivo with `datos['movimiento']` set to
     * $movimiento — see class docblock, "ATTRIBUTING A MOTIVO TO ITS LEG".
     * `codigo`/`mensaje` are carried over verbatim; any OTHER key already in
     * `datos` is preserved.
     */
    public function conMovimiento( string $movimiento ): self {
        return new self( $this->codigo, $this->mensaje, array_merge( $this->datos, [ 'movimiento' => $movimiento ] ) );
    }

    /**
     * Which leg of a grouped request produced this Motivo — `'arco'` or
     * `'campo'` (see `DictamenPipeline::evaluateGrupo()`) — or `null` for an
     * ordinary, non-grouped evaluation, or a grouped motivo that predates
     * `conMovimiento()` ever being called on it.
     */
    public function movimiento(): ?string {
        $movimiento = $this->datos['movimiento'] ?? null;

        return is_string( $movimiento ) ? $movimiento : null;
    }
}

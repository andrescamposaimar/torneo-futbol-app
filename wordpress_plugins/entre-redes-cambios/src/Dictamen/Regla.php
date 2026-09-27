<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen;

/**
 * A single, independent rule of the dictamen ruleset.
 *
 * Every implementation is a PURE function of $ctx — no DB, no clock, no
 * shared mutable state between rules — which is what makes MotorDeDictamen's
 * "run every rule, join every motivo" contract possible: no rule needs to
 * know whether another rule already failed, and none may short-circuit the
 * others.
 */
interface Regla {

    /**
     * @return Motivo|null The reason this rule rejects the solicitud, or
     *         null when it has no objection. Never throws for a business
     *         rejection — a Motivo IS the rejection; an exception here would
     *         mean something the rule cannot evaluate at all (a caller/
     *         context-assembly bug), not a failed rule.
     */
    public function evaluar( ContextoDeDictamen $ctx ): ?Motivo;
}

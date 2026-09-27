<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen;

/**
 * Runs every injected Regla against a ContextoDeDictamen and joins every
 * motivo they report into one Dictamen.
 *
 * *** THIS ENGINE DICTAMINATES, IT DOES NOT APPROVE ***
 * Per the reglamento, the subcomisión is never obligated to provide a
 * reemplazo, and the process owner approves ALWAYS — even when a solicitud
 * clears every rule here. So this class does not return `true`/`false` for
 * something else to execute automatically; it returns a Dictamen a human
 * reads before deciding. That is exactly why the favorable outcome is named
 * `procede()`, not `aprobado()` — "aprobar" is the human's verb, and this
 * class's output must never look like it did that job already.
 *
 * *** NEVER SHORT-CIRCUITS ***
 * evaluar() runs every rule, always, and collects every motivo — a captain
 * reading a rejected solicitud must see every reason at once, not fix one
 * and resubmit only to be told about the next. This is the single behavior
 * this whole slice's test suite exists to protect (see
 * MotorDeDictamenTest::test_a_solicitud_that_violates_three_rules_reports_all_three_motivos()).
 *
 * Adding a new rule to the ruleset is exactly "add another element to the
 * constructor's array" — this class has no knowledge of any rule's
 * identity, order dependency, or short-circuit condition.
 */
final class MotorDeDictamen {

    /** @var Regla[] */
    private array $reglas;

    /**
     * @param Regla[] $reglas Every rule this dictamen must run — order does
     *        not matter, since none of them may depend on another's result
     *        (see class docblock, "NEVER SHORT-CIRCUITS").
     */
    public function __construct( array $reglas ) {
        $this->reglas = $reglas;
    }

    public function evaluar( ContextoDeDictamen $ctx ): Dictamen {
        $motivos = [];

        foreach ( $this->reglas as $regla ) {
            $motivo = $regla->evaluar( $ctx );

            if ( null !== $motivo ) {
                $motivos[] = $motivo;
            }
        }

        return Dictamen::desde( $motivos );
    }
}

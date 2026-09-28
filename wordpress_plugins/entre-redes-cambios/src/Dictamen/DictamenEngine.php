<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen;

/**
 * Runs every injected Regla against a DictamenContext and joins every
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
 * evaluate() runs every rule, always, and collects every motivo — a captain
 * reading a rejected solicitud must see every reason at once, not fix one
 * and resubmit only to be told about the next. This is the single behavior
 * this whole slice's test suite exists to protect (see
 * DictamenEngineTest::test_a_solicitud_that_violates_three_rules_reports_all_three_motivos()).
 *
 * Adding a new rule to the ruleset is exactly "add another element to the
 * constructor's array" — this class has no knowledge of any rule's
 * identity, order dependency, or short-circuit condition.
 *
 * *** A RULE THAT THROWS NEVER TAKES THE OTHERS' MOTIVOS DOWN WITH IT ***
 * Regla's own contract says a rule must never throw for a business
 * rejection — a Motivo IS the rejection (see Regla's class docblock). But a
 * rule can still throw for something IT cannot evaluate at all: corrupted
 * business data reaching a value object's constructor (e.g.
 * Reglas\PuntajeDentroDelTecho building `Puntaje::fromHalfPoints()` off a
 * `puntaje_techo` that is out of range), not a caller/context-assembly bug
 * this engine could have prevented. Letting that exception propagate out of
 * evaluate() would abort the whole loop and silently discard every motivo
 * the PRECEDING rules already collected — "cut on the first problem",
 * exactly the short-circuit this class's other docblock section forbids,
 * just wearing an exception instead of a `return`. So every rule runs inside
 * its own try/catch: a throwing rule becomes a Motivo (fail closed — an
 * unevaluable rule must never read as "no objection"), tagged with a stable
 * code and the offending rule's class name so the failure is diagnosable,
 * and every other rule still runs to completion.
 */
final class DictamenEngine {

    private const CODE_ERROR_REGLA = 'error_al_evaluar_regla';

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

    public function evaluate( DictamenContext $ctx ): Dictamen {
        $motivos = [];

        foreach ( $this->reglas as $regla ) {
            $motivo = $this->evaluateOne( $regla, $ctx );

            if ( null !== $motivo ) {
                $motivos[] = $motivo;
            }
        }

        return Dictamen::from( $motivos );
    }

    /**
     * Runs a single Regla, fail-closed: an uncaught \Throwable becomes a
     * Motivo instead of aborting evaluate()'s loop — see class docblock, "A
     * RULE THAT THROWS NEVER TAKES THE OTHERS' MOTIVOS DOWN WITH IT".
     */
    private function evaluateOne( Regla $regla, DictamenContext $ctx ): ?Motivo {
        try {
            return $regla->evaluate( $ctx );
        } catch ( \Throwable $e ) {
            return new Motivo(
                self::CODE_ERROR_REGLA,
                sprintf(
                    'No se pudo evaluar una regla del dictamen (%s): %s.',
                    get_class( $regla ),
                    $e->getMessage()
                ),
                [
                    'regla'     => get_class( $regla ),
                    'excepcion' => get_class( $e ),
                    'mensaje'   => $e->getMessage(),
                ]
            );
        }
    }
}

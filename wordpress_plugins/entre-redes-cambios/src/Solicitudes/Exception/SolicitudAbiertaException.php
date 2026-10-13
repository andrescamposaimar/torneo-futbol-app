<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Solicitudes\Exception;

use EntreRedes\Cambios\Dictamen\Dictamen;
use EntreRedes\Cambios\Dictamen\Reglas\EntranteSinSolicitudAbierta;
use EntreRedes\Cambios\Dictamen\Reglas\PlazaSinSolicitudAbierta;

/**
 * `SolicitudRepository` REFUSED to create a solicitud because its dictamen
 * carries `plaza_con_solicitud_abierta` or `entrante_con_solicitud_abierta`
 * (0.1.21). Nothing was written.
 *
 * WHY THESE TWO CODES, AND ONLY THESE, ARE A REFUSAL: every other rule is a
 * JUDGEMENT the process owner is entitled to overrule (a ceiling, a plazo, a
 * position), so a request that violates one is still stored `pendiente` with
 * its motivos — see `Rest\SolicitudesController`'s docblock, "A DICTAMEN THAT
 * DOES NOT PROCEDE IS NOT AN ERROR". A duplicate is not a judgement, it is a
 * state the system must not be able to represent: a stored duplicate would
 * itself be "open" (holding the plaza and the player it names), and
 * `SolicitudRepository::publicarLote()` aborts the WHOLE lote when one row's
 * fresh dictamen does not `procede`, so one duplicate would stop every other
 * team's change from publishing that Friday. Do NOT widen this to "any
 * failing dictamen".
 *
 * Carries the single refusing code, never the other team's request: the
 * caller turns it into a 409 for the captain.
 */
class SolicitudAbiertaException extends \RuntimeException {

    private string $codigo;

    private function __construct( string $codigo ) {
        parent::__construct( "SolicitudRepository refused to create the solicitud: {$codigo}." );
        $this->codigo = $codigo;
    }

    /**
     * Null when the dictamen carries neither refusing code. When it carries
     * both, the PLAZA code wins — it is the one the captain can see and act
     * on (the entrante may belong to another team's request).
     */
    public static function desdeDictamen( Dictamen $dictamen ): ?self {
        foreach ( [ PlazaSinSolicitudAbierta::CODE, EntranteSinSolicitudAbierta::CODE ] as $codigo ) {
            if ( null !== $dictamen->motivo( $codigo ) ) {
                return new self( $codigo );
            }
        }

        return null;
    }

    public function codigo(): string {
        return $this->codigo;
    }
}

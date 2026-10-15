<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Solicitudes\Exception;

/**
 * A `reasignacion_arquero` titular does not hold EXACTLY ONE vigent field
 * plaza in the team (none: already moved or never seated; several: should not
 * happen, but the use case refuses to guess which one is vacated).
 *
 * Still a `\RuntimeException`, so the REST route's generic handler treats it
 * exactly as before (a logged 500); only the admin page tells it apart, to
 * name the player. Carries the player id, never a message meant for users.
 */
class TitularSinPlazaDeCampoException extends \RuntimeException {

    private int $titularPlayerId;

    public function __construct( string $mensaje, int $titularPlayerId ) {
        parent::__construct( $mensaje );
        $this->titularPlayerId = $titularPlayerId;
    }

    public function titularPlayerId(): int {
        return $this->titularPlayerId;
    }
}

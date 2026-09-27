<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen;

/**
 * Immutable input DTO for the dictamen engine: a single request to change who
 * occupies a plaza, on a given fecha.
 *
 * TWO SHAPES, ONE CLASS: a `sustitucion` (someone new replaces whoever
 * currently occupies the plaza) always carries an `entrantePlayerId`; a
 * `regreso` (the plaza's PERMANENT titular — see
 * Plazas\PlazaRepository::closeOcupacionByRegresoTitular() — comes back)
 * never does, because who returns is not a choice this request makes: it is
 * always `cambios_plaza.titular_player_id`, read later from the plaza row
 * itself. Allowing a caller to construct a `regreso` WITH an entrante would
 * let a request silently name someone other than the titular as "who
 * returns", which is not a thing that can happen under this domain model.
 * The private constructor plus the two named constructors below make that
 * combination unrepresentable rather than merely undocumented.
 *
 * THE INSTANT IS AN EPOCH, NOT A FORMATTED STRING — same reasoning as
 * Auth\TokenVerifier::verify()'s `$nowTimestamp`: an epoch has no timezone to
 * misread. `Dictamen\Reglas\SolicitudEnPlazo` is the one rule that reads this
 * value, and it does so by converting it to a UTC civil string with
 * `gmdate()` before comparing against `DictamenContext::plazosUtc()` — see
 * that rule's docblock for why the comparison frame must be UTC on both
 * sides.
 */
final class SolicitudDeCambio {

    public const TIPO_SUSTITUCION = 'sustitucion';
    public const TIPO_REGRESO     = 'regreso';

    private int $seasonId;
    private int $teamId;
    private int $plazaId;
    private ?int $entrantePlayerId;
    private int $fechaId;
    private string $tipo;
    private int $instanteEpoch;

    private function __construct(
        int $seasonId,
        int $teamId,
        int $plazaId,
        ?int $entrantePlayerId,
        int $fechaId,
        string $tipo,
        int $instanteEpoch
    ) {
        $this->seasonId         = $seasonId;
        $this->teamId           = $teamId;
        $this->plazaId          = $plazaId;
        $this->entrantePlayerId = $entrantePlayerId;
        $this->fechaId          = $fechaId;
        $this->tipo             = $tipo;
        $this->instanteEpoch    = $instanteEpoch;
    }

    /**
     * A request that a NEW player ($entrantePlayerId) take over the plaza
     * currently occupied by whoever is vigent — see
     * Dictamen\Reglas\EntranteNoEsElSaliente for why "vigent" here, not
     * necessarily the titular, is exactly what makes "el cambio de cambio"
     * fall under the same rules as a first-time substitution.
     */
    public static function sustitucion(
        int $seasonId,
        int $teamId,
        int $plazaId,
        int $entrantePlayerId,
        int $fechaId,
        int $instanteEpoch
    ): self {
        return new self( $seasonId, $teamId, $plazaId, $entrantePlayerId, $fechaId, self::TIPO_SUSTITUCION, $instanteEpoch );
    }

    /**
     * A request that the plaza's PERMANENT titular come back — never a
     * suplente intermedio, regardless of who is vigent when this is
     * evaluated. See class docblock for why this named constructor takes no
     * entrante.
     */
    public static function regreso(
        int $seasonId,
        int $teamId,
        int $plazaId,
        int $fechaId,
        int $instanteEpoch
    ): self {
        return new self( $seasonId, $teamId, $plazaId, null, $fechaId, self::TIPO_REGRESO, $instanteEpoch );
    }

    public function seasonId(): int {
        return $this->seasonId;
    }

    public function teamId(): int {
        return $this->teamId;
    }

    public function plazaId(): int {
        return $this->plazaId;
    }

    /**
     * Null for a `regreso` — see class docblock. Never null for a
     * `sustitucion`.
     */
    public function entrantePlayerId(): ?int {
        return $this->entrantePlayerId;
    }

    public function fechaId(): int {
        return $this->fechaId;
    }

    public function tipo(): string {
        return $this->tipo;
    }

    public function isSustitucion(): bool {
        return self::TIPO_SUSTITUCION === $this->tipo;
    }

    public function isRegreso(): bool {
        return self::TIPO_REGRESO === $this->tipo;
    }

    /**
     * Unix epoch of the moment this solicitud was made. See class docblock
     * for why this is an epoch and not a formatted string.
     */
    public function instanteEpoch(): int {
        return $this->instanteEpoch;
    }
}

<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Player;

/**
 * The concrete TeamResolver this plugin ships: reads entre-redes-api's own
 * `obtener_equipo_desde_rest( int $player_id ): array` — the SAME function
 * `/jugadores` already uses to fill `equipo_id`/`equipo_nombre` — so a
 * player's team on the credential can never disagree with what the rest of
 * the app already shows for them.
 *
 * `obtener_equipo_desde_rest()` returns `[equipo_id, equipo_nombre,
 * escudo_url]` (a plain 3-tuple, not an associative array — see
 * entre-redes-api/entre-redes-api.php:165). This class narrows that to the
 * `{id, name}` shape TeamResolver promises; the shield URL is not part of
 * this plugin's payload.
 *
 * THE FUNCTION_EXISTS GUARD IS NOT OPTIONAL: entre-redes-credencial has no
 * hard dependency on entre-redes-api being active. If it is not, every
 * player simply renders without a team badge (spec scenario "Team or list
 * name unavailable") instead of a fatal "call to undefined function".
 *
 * $resolverFn IS INJECTED, never hardcoded to the global function name
 * directly inside resolve() — the only way to unit-test this class without
 * loading the real entre-redes-api plugin (which this plugin's test suite
 * deliberately never does; see EntreRedesApiTeamResolverTest's own
 * docblock). Defaults to the global function when it exists, and to "always
 * unavailable" when it does not — production behavior either way, decided
 * once, at construction, never per call.
 */
final class EntreRedesApiTeamResolver implements TeamResolver {

    /** @var (callable(int): array)|null */
    private $resolverFn;

    /**
     * @param (callable(int): array)|null $resolverFn Defaults to
     *        `obtener_equipo_desde_rest` when it is defined, or to "always
     *        unavailable" otherwise.
     */
    public function __construct( ?callable $resolverFn = null ) {
        if ( null !== $resolverFn ) {
            $this->resolverFn = $resolverFn;
        } elseif ( function_exists( 'obtener_equipo_desde_rest' ) ) {
            $this->resolverFn = 'obtener_equipo_desde_rest';
        } else {
            $this->resolverFn = null;
        }
    }

    public function resolve( int $playerId ): ?array {
        if ( null === $this->resolverFn ) {
            return null;
        }

        $result = ( $this->resolverFn )( $playerId );

        [ $teamId, $teamName ] = [ $result[0] ?? 0, $result[1] ?? '' ];
        $teamId   = (int) $teamId;
        $teamName = (string) $teamName;

        if ( $teamId <= 0 || '' === $teamName ) {
            return null;
        }

        return [ 'id' => $teamId, 'name' => $teamName ];
    }
}

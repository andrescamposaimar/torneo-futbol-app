<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\PlazosCalculator;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\Exception\FechaCountUnavailableException;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;

/**
 * Builds a `DictamenContext` from the real database for a given
 * `SolicitudDeCambio` — the piece slice 3's README explicitly left out
 * ("Assembling DictamenContext from the real database ... is slice 4's
 * job"). Nothing here decides whether a solicitud is favorable; this class
 * only loads the facts `DictamenEngine`'s rules need, zero business logic.
 *
 * *** THE CONTRACT THAT GOVERNS THIS WHOLE CLASS ***
 * Every query this class runs (directly, or via the repositories it calls)
 * MUST throw on failure, never silently produce an empty collection. See
 * `Plazas\PlazaRepository`'s class docblock, "READ FAILURES MUST NEVER READ
 * AS 'NO ROWS'", and README's "Contracts for slice 4" point 1: an empty
 * `entranteOcupacionesEnOtrasPlazas()` or `entrantePlazasConCierreTruncado()`
 * reads to `Reglas\EntranteDisponible` / `Reglas\EntranteNoBloqueado` as
 * "confirmado, sin conflicto" — a query that fails silently would become a
 * silent approval, not a rejection. If the plaza or the fecha named by the
 * solicitud does not exist at all, this class throws rather than assembling
 * a half-built context: a `Regla` can never distinguish "no conflict" from
 * "I could not look".
 *
 * *** WHY listOcupaciones() ITSELF WAS NOT CHANGED ***
 * The plaza's OWN chain (`DictamenContext::ocupaciones()`, read via
 * `PlazaRepository::listOcupaciones()`, unchanged from slice 2) is not
 * covered by the same throw-on-failure discipline as the two NEW repository
 * queries this assembler also calls. That is deliberate, not an oversight:
 * an empty `ocupaciones()` chain does NOT read as "no conflict" anywhere in
 * this ruleset — `DictamenContext::vigente()` returns null for an empty
 * chain, and `Reglas\PlazaConOcupacionVigente` treats "no vigent link" as
 * its own REJECTING motivo (`plaza_sin_ocupacion_vigente`). A silently
 * failed fetch of the plaza's own chain therefore already fails CLOSED by
 * construction of the existing ruleset, unlike the two new queries this
 * class was specifically written to guard.
 *
 * *** THE PLAZOS FRAME IS UTC, ON PURPOSE ***
 * `Calendario\PlazosCalculator::computeUtc()` is used here, NEVER
 * `compute()` — `SolicitudDeCambio::instanteEpoch()` is an absolute Unix
 * epoch, and `Reglas\SolicitudEnPlazo` compares it (via `gmdate()`) against
 * `DictamenContext::plazosUtc()` in the UTC frame. Passing `compute()`'s
 * civil strings here would silently reintroduce the exact timezone bug
 * `PlazosCalculator`'s own class docblock warns about — the fecha's
 * `play_date` is loaded once, via `FechaRepository::findById()`, and fed to
 * `computeUtc()` together with `Settings::plazosOffsets()` and
 * `Settings::timezone()`.
 *
 * *** THE SANITY CAP ON THE INJECTED COUNTER ***
 * `Plazas\CadenaResolver`'s own class docblock is explicit that an INFLATED
 * resolved-fechas count (the callable lying UPWARD) is NOT something that
 * class can detect on its own — it has no season total to compare against.
 * "Bounded is the caller's responsibility", and this assembler is that
 * caller: `boundedCountResolvedFechasSinceFn()` wraps
 * `FechaRepository::countResolvedFechasSince()` with the season's own total
 * of resolved fechas (`countTotalResolvedFechas()`) and throws
 * `Plazas\Exception\FechaCountUnavailableException` the instant a count
 * exceeds that total — an answer that is, by construction, impossible.
 * `CadenaResolver` (and `Reglas\EntranteNoBloqueado`, which calls the
 * injected callable directly for its `tope_tres_fechas` policy) already
 * translate that exception into a fail-closed answer; this wrapper is what
 * makes that translation reachable at all.
 */
final class DictamenContextAssembler {

    private const META_KEY_METRICS = 'sp_metrics';

    private PlazaRepository $plazaRepository;
    private FechaRepository $fechaRepository;
    private Settings $settings;
    private \wpdb $wpdb;
    private EventLog $eventLog;

    public function __construct(
        PlazaRepository $plazaRepository,
        FechaRepository $fechaRepository,
        Settings $settings,
        \wpdb $wpdb,
        EventLog $eventLog
    ) {
        $this->plazaRepository = $plazaRepository;
        $this->fechaRepository = $fechaRepository;
        $this->settings        = $settings;
        $this->wpdb            = $wpdb;
        $this->eventLog        = $eventLog;
    }

    /**
     * @throws \RuntimeException When the plaza or the fecha named by
     *         $solicitud does not exist, or when any underlying query fails
     *         — see class docblock.
     */
    public function assemble( SolicitudDeCambio $solicitud ): DictamenContext {
        $plaza = $this->plazaRepository->findPlaza( $solicitud->plazaId() );

        if ( null === $plaza ) {
            $this->eventLog->record( 'contexto.fallido', [
                'operacion' => 'assemble',
                'motivo'    => 'la plaza no existe',
                'plaza_id'  => $solicitud->plazaId(),
                'season_id' => $solicitud->seasonId(),
            ] );

            throw new \RuntimeException(
                "DictamenContextAssembler::assemble(): plaza {$solicitud->plazaId()} does not exist — "
                . 'refusing to assemble a half-built context.'
            );
        }

        $fecha = $this->fechaRepository->findById( $solicitud->fechaId() );

        if ( null === $fecha ) {
            $this->eventLog->record( 'contexto.fallido', [
                'operacion' => 'assemble',
                'motivo'    => 'la fecha no existe',
                'fecha_id'  => $solicitud->fechaId(),
                'season_id' => $solicitud->seasonId(),
            ] );

            throw new \RuntimeException(
                "DictamenContextAssembler::assemble(): fecha {$solicitud->fechaId()} does not exist — "
                . 'refusing to assemble a half-built context.'
            );
        }

        $ocupaciones = $this->plazaRepository->listOcupaciones( $solicitud->plazaId() );

        $entrantePlayerId                 = $solicitud->entrantePlayerId();
        $entrantePuntaje                  = null;
        $entranteOcupacionesEnOtrasPlazas = [];
        $entrantePlazasConCierreTruncado  = [];

        if ( null !== $entrantePlayerId ) {
            $entrantePuntaje = $this->resolveEntrantePuntaje( $entrantePlayerId );

            $entranteOcupacionesEnOtrasPlazas = $this->plazaRepository->listOcupacionesVigentesDeJugador(
                $solicitud->seasonId(),
                $entrantePlayerId,
                $solicitud->plazaId()
            );

            $entrantePlazasConCierreTruncado = $this->otherPlazasOnly(
                $this->plazaRepository->listPlazasConCierreTruncadoDeJugador( $solicitud->seasonId(), $entrantePlayerId ),
                $solicitud->plazaId()
            );
        }

        $plazosUtc = PlazosCalculator::computeUtc(
            (string) $fecha['play_date'],
            $this->settings->plazosOffsets(),
            $this->settings->timezone()
        );

        return new DictamenContext(
            $solicitud,
            $plaza,
            $ocupaciones,
            $entrantePuntaje,
            $entranteOcupacionesEnOtrasPlazas,
            $entrantePlazasConCierreTruncado,
            $plazosUtc,
            $this->boundedCountResolvedFechasSinceFn( $solicitud->seasonId() )
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * `PlazaRepository::listPlazasConCierreTruncadoDeJugador()` has no
     * "excluding this plaza" parameter of its own (unlike
     * `listOcupacionesVigentesDeJugador()`) — see that method's docblock.
     * `Reglas\EntranteNoBloqueado` only cares about OTHER plazas (the
     * entrante's own trunca history on the CURRENT plaza, if any, is already
     * covered by `Reglas\RegresoSoloConMinimoCumplido` /
     * `Reglas\PlazaConOcupacionVigente` reading `DictamenContext::ocupaciones()`
     * directly), so this filters the current plaza's own chain out here,
     * once, rather than teaching the repository method a parameter only
     * this one caller needs.
     *
     * @param array<int, array<int, array<string, mixed>>> $chains
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function otherPlazasOnly( array $chains, int $currentPlazaId ): array {
        return array_values( array_filter(
            $chains,
            static function ( array $chain ) use ( $currentPlazaId ): bool {
                $chainPlazaId = isset( $chain[0]['plaza_id'] ) ? (int) $chain[0]['plaza_id'] : null;

                return $chainPlazaId !== $currentPlazaId;
            }
        ) );
    }

    /**
     * The entrante's puntaje, read from `sp_metrics` postmeta on the
     * player's post — raw `$wpdb`, never `get_post_meta()`, consistent with
     * every other query in this feature (see `Plazas\PlazaRepository`'s
     * class docblock: `wp_postmeta` is a WordPress core table this plugin
     * reads directly, exactly like every `cambios_*` table it owns).
     *
     * *** TWO KEYS, BOTH CASE VARIANTS OF THE SAME METRIC ***
     * `entre-redes-api.php` itself reads this same value under TWO different
     * keys depending on which endpoint — `'puntaje'`
     * (`entre_redes_get_jugador_por_id()`) and `'Puntaje'` (the
     * partido-lineup endpoint). Both are tried here, lowercase first.
     *
     * *** NEVER DEFAULTS TO 0 *** A puntaje of 0 is not one of the torneo's
     * 9 valid discrete values (1..5 in 0.5 steps) — treating a missing value
     * as 0 would fabricate a fact about a real person. When NEITHER key is
     * present (or the value found is empty), this returns `null` — a
     * legitimate missing-data outcome `Reglas\PuntajeDentroDelTecho` already
     * converts into its own BLOCKING motivo (`entrante_puntaje_indeterminado`,
     * see that rule's docblock, "A MISSING PUNTAJE IS NEVER READ AS 'NO
     * OBJECTION'") — so unlike the two collection queries above, a missing
     * puntaje already fails CLOSED by itself; this method logs the gap
     * (`entrante.puntaje_no_encontrado`) for an operator to go fix the data,
     * but does not need to throw to stay safe.
     *
     * A value that IS present but is not one of the 9 valid puntajes is a
     * different problem entirely — corrupted business data, not a gap — and
     * `Puntaje::fromDecimal()` throws for it, deliberately uncaught here:
     * fail loud is correct for that case (see class docblock's general
     * contract).
     *
     * @throws \InvalidArgumentException When the stored value is not one of
     *         the 9 valid puntajes — propagated from `Puntaje::fromDecimal()`.
     */
    private function resolveEntrantePuntaje( int $entrantePlayerId ): ?Puntaje {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$p}postmeta WHERE post_id = %d AND meta_key = %s LIMIT 1",
                $entrantePlayerId,
                self::META_KEY_METRICS
            )
        );

        $metrics = $this->decodeMetrics( $raw );
        $value   = null;

        if ( null !== $metrics ) {
            $value = $metrics['puntaje'] ?? ( $metrics['Puntaje'] ?? null );
        }

        if ( null === $value || '' === $value ) {
            $this->eventLog->record( 'entrante.puntaje_no_encontrado', [
                'player_id' => $entrantePlayerId,
            ] );

            return null;
        }

        return Puntaje::fromDecimal( is_string( $value ) ? $value : (string) $value );
    }

    /**
     * WordPress serializes an array meta_value via PHP's native
     * `serialize()` — this reimplements just enough of `maybe_unserialize()`
     * to read `sp_metrics` back without depending on WordPress at all,
     * consistent with reading `wp_postmeta` via raw `$wpdb` in the first
     * place. Anything that does not decode to an array (missing row,
     * malformed blob) collapses into `null` — indistinguishable, for THIS
     * purpose, from a genuinely absent one; see resolveEntrantePuntaje()'s
     * docblock for why that is safe here.
     *
     * @return array<string, mixed>|null
     */
    private function decodeMetrics( ?string $raw ): ?array {
        if ( null === $raw || '' === $raw ) {
            return null;
        }

        $decoded = @unserialize( $raw, [ 'allowed_classes' => false ] );

        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * See class docblock, "THE SANITY CAP ON THE INJECTED COUNTER".
     */
    private function boundedCountResolvedFechasSinceFn( int $seasonId ): callable {
        $totalResueltas = $this->countTotalResolvedFechas( $seasonId );

        return function ( int $fechaId ) use ( $seasonId, $totalResueltas ): int {
            $count = $this->fechaRepository->countResolvedFechasSince( $seasonId, $fechaId );

            if ( $count > $totalResueltas ) {
                $this->eventLog->record( 'contador.fechas_resueltas_inflado', [
                    'season_id'       => $seasonId,
                    'fecha_id'        => $fechaId,
                    'count'           => $count,
                    'total_resueltas' => $totalResueltas,
                ] );

                throw new FechaCountUnavailableException(
                    sprintf(
                        "countResolvedFechasSince() returned %d for fecha_id %d, more than the season's own "
                            . 'total of %d resolved fechas — impossible, the counter itself must be broken.',
                        $count,
                        $fechaId,
                        $totalResueltas
                    )
                );
            }

            return $count;
        };
    }

    private function countTotalResolvedFechas( int $seasonId ): int {
        $fechas = $this->fechaRepository->listBySeason( $seasonId );

        return count( array_filter(
            $fechas,
            static fn ( array $f ): bool => in_array( (string) ( $f['estado'] ?? '' ), [ 'jugada', 'dirimida' ], true )
        ) );
    }
}

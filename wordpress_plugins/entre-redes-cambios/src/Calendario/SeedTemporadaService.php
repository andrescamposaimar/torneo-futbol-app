<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Calendario;

/**
 * Seeds the full calendar of jornadas for a season from its raw partidos.
 *
 * Constructor takes an injectable `$fetcherFn` closure — same pattern as
 * entre-redes-prode's SeedFechaService injecting its `$resolverFn` — so tests
 * never need real WordPress or network access: a stub closure returning
 * canned partidos is enough.
 *
 * IMPORTANT: the fetcher is NOT expected to return the whole season's
 * fixture. The committee loads partidos week by week — postponing a jornada
 * forces re-running the whole not-yet-played fixture, so they deliberately
 * avoid loading it all upfront (see domain notes in FechaRepository's class
 * docblock). `$fetcherFn` returns whatever partidos have been loaded into
 * SportsPress SO FAR, and this class must be safe to run — repeatedly,
 * incrementally — every time a new batch shows up.
 *
 * Grouping algorithm:
 *   1. Group the season's partidos by calendar day (play_date = date part of
 *      kickoff) — this IS the jornada; SportsPress has no round taxonomy (see
 *      domain fact #1 in the slice task description).
 *   2. Sort play_dates ascending.
 *   3. For each day group, resolve `torneo_label` (and `torneo_liga_ids`) from
 *      which liga_ids are present on that play_date, via an injected liga_id
 *      -> torneo_label map — never hardcoded term ids in this class (they
 *      belong to Settings or the caller, since they are Clasificacion /
 *      Apertura / Clausura's actual WordPress term ids and differ per
 *      season).
 *   4. Delegate the day group to FechaRepository::upsertFecha(), which
 *      resolves the day's identity from its match_ids (NOT its play_date —
 *      see FechaRepository's docblock) — so a day group whose matches
 *      already belong to an existing fecha MOVES that fecha instead of
 *      creating a duplicate.
 *   5. Once every day group has been upserted, call
 *      FechaRepository::recalcularOrden() exactly once for the whole season.
 *      This class deliberately does NOT assign `orden` or `numero_en_torneo`
 *      itself anymore: with fecha_id (not `orden`) as the stable identity,
 *      recomputing `orden` from scratch on every run is what keeps a
 *      late-arriving or postponed fecha correctly ordered — see
 *      FechaRepository's class docblock, "INVARIANT A FUTURE SLICE MUST NOT
 *      BREAK".
 *
 * Idempotent: re-running with the same (or a superset of) partidos re-derives
 * the exact same day groups and delegates persistence to
 * FechaRepository::upsertFecha() + recalcularOrden(), whose own
 * SELECT-then-insert guards and estado_origen rule prevent duplicate rows and
 * protect manually-set estados.
 */
final class SeedTemporadaService {

    private FechaRepository $repository;

    /** @var callable */
    private $fetcherFn;

    /** @var array<int, string> */
    private array $ligaToTorneoLabel;

    /**
     * @param callable $fetcherFn Returns array<int, array{match_id:int,
     *        liga_id:int, zona?:string, kickoff:string,
     *        tiene_resultado?:bool|int}> for the partidos loaded so far. In
     *        production: a closure wrapping the /partidos API client. In
     *        tests: a stub closure returning canned data.
     * @param array<int, string> $ligaToTorneoLabel Maps a liga_id (sp_league
     *        term id) to its torneo_label ('Clasificacion' | 'Apertura' |
     *        'Clausura'). Comes from the caller/Settings — never hardcoded
     *        here, since term ids are season-specific WordPress data, not a
     *        constant of this class. Zonas are re-derived between phases, so
     *        new liga_ids can appear mid-season — see resolveTorneoLabel().
     */
    public function __construct( FechaRepository $repository, callable $fetcherFn, array $ligaToTorneoLabel ) {
        $this->repository        = $repository;
        $this->fetcherFn         = $fetcherFn;
        $this->ligaToTorneoLabel = $ligaToTorneoLabel;
    }

    /**
     * Run the seed pipeline for one season, over whatever partidos the
     * fetcher currently returns. Safe to call repeatedly and incrementally
     * (see class docblock).
     *
     * @return array<int, array{fecha_id:int, play_date:string, orden:int, numero_en_torneo:int, torneo_label:string, status:string}>
     *         `status` is one of 'creada' (new fecha), 'postergada' (existing
     *         fecha whose play_date just advanced) or 'sin_cambios'.
     */
    public function seed( int $seasonId, string $now ): array {
        $partidos = ( $this->fetcherFn )();

        $byDay = [];
        foreach ( $partidos as $partido ) {
            $playDate = substr( (string) $partido['kickoff'], 0, 10 );
            $byDay[ $playDate ][] = $partido;
        }

        // Ascending 'Y-m-d' string sort is also chronological sort — no
        // DateTime parsing needed.
        ksort( $byDay );

        $pending = [];

        foreach ( $byDay as $playDate => $dayPartidos ) {
            $ligaIds = array_values( array_unique( array_map(
                static fn( array $m ): int => (int) $m['liga_id'],
                $dayPartidos
            ) ) );
            sort( $ligaIds );

            $torneoLabel = $this->resolveTorneoLabel( $ligaIds );

            $matchIds        = array_values( array_unique( array_map(
                static fn( array $m ): int => (int) $m['match_id'],
                $dayPartidos
            ) ) );
            $existingFechaId = $this->repository->findFechaIdByMatchIds( $matchIds );

            $existingPlayDate = null;
            if ( null !== $existingFechaId ) {
                $existingRow      = $this->repository->findById( $existingFechaId );
                $existingPlayDate = $existingRow['play_date'] ?? null;
            }

            $fecha = [
                'season_id'        => $seasonId,
                // Placeholders — recalcularOrden(), called once below after
                // every day group in this run has been upserted, is the sole
                // authority for these two columns. See class docblock.
                // `orden` and `numero_en_torneo` are intentionally NOT set here:
                // FechaRepository assigns a provisional, collision-free `orden`
                // on insert and recalcularOrden() below writes the real values.
                'torneo_liga_ids'  => implode( ',', $ligaIds ),
                'torneo_label'     => $torneoLabel,
                'play_date'        => $playDate,
                // The DEFAULT state, derived from the partidos themselves.
                // upsertFecha() only applies it when estado_origen is still
                // 'derivado', so a human ruling is never overwritten.
                'estado'           => EstadoDeriver::derive( $dayPartidos, $playDate, $now ),
            ];

            $fechaId = $this->repository->upsertFecha( $fecha, $dayPartidos );

            if ( null === $existingFechaId ) {
                $status = 'creada';
            } elseif ( null !== $existingPlayDate && $playDate > $existingPlayDate ) {
                $status = 'postergada';
            } else {
                $status = 'sin_cambios';
            }

            $pending[] = [
                'fecha_id'     => $fechaId,
                'play_date'    => $playDate,
                'torneo_label' => $torneoLabel,
                'status'       => $status,
            ];
        }

        $this->repository->recalcularOrden( $seasonId );

        $created = [];
        foreach ( $pending as $item ) {
            $row = $this->repository->findById( $item['fecha_id'] );

            $created[] = [
                'fecha_id'         => $item['fecha_id'],
                'play_date'        => $item['play_date'],
                'orden'            => (int) ( $row['orden'] ?? 0 ),
                'numero_en_torneo' => (int) ( $row['numero_en_torneo'] ?? 0 ),
                'torneo_label'     => $item['torneo_label'],
                'status'           => $item['status'],
            ];
        }

        return $created;
    }

    /**
     * @param array<int, int> $ligaIds
     */
    private function resolveTorneoLabel( array $ligaIds ): string {
        foreach ( $ligaIds as $ligaId ) {
            if ( isset( $this->ligaToTorneoLabel[ $ligaId ] ) ) {
                return $this->ligaToTorneoLabel[ $ligaId ];
            }
        }

        throw new \InvalidArgumentException(
            'Cannot resolve torneo_label: none of liga_ids [' . implode( ',', $ligaIds ) . '] is in the '
            . 'injected map. This usually means a new liga_id appeared that has not been mapped yet — '
            . 'zonas are re-derived between phases (Clasificacion, Apertura, Clausura), so new liga_ids '
            . 'can show up mid-season and the injected ligaToTorneoLabel map must be updated to include them.'
        );
    }
}

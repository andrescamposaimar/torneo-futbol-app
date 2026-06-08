<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Fecha;

/**
 * Backfills the team-meta snapshot columns (home_team, away_team, zona,
 * home_escudo, away_escudo) on prode_fecha_matches rows that were seeded before
 * v0.5.2 — i.e. rows with an empty home_team snapshot.
 *
 * Why this exists:
 *   Until v0.5.2 team names were resolved live at read time from
 *   /partidos-programados (ADR-G0-2). That endpoint drops matches once they are
 *   played, so any already-locked fecha (e.g. Fecha 1) rendered with empty team
 *   names and missing escudos. The played-matches endpoint /partidos still lists
 *   those matches with full meta, so we read them once and persist a snapshot.
 *
 * The dispatcher is constructor-injected so tests can supply canned payloads
 * without a real WP REST runtime; the production default (BackfillMatchMetaCron)
 * wraps rest_do_request to GET /entre-redes/v1/partidos?fecha={date}.
 *
 * Idempotent: only rows with an empty/null home_team are touched, and a match
 * whose live payload has no usable name is left untouched (so a later run can
 * still fill it once the data is available).
 */
class BackfillMatchMetaService {

    private \wpdb $wpdb;

    /** @var callable(string):array<int, array<string, mixed>> */
    private $partidosByDateFn;

    /**
     * @param \wpdb    $wpdb
     * @param callable $partidosByDateFn  fn(string $playDate): array<int, item>
     *                                     where each item mirrors the /partidos
     *                                     row shape (id, equipo_local, ...).
     */
    public function __construct( \wpdb $wpdb, callable $partidosByDateFn ) {
        $this->wpdb             = $wpdb;
        $this->partidosByDateFn = $partidosByDateFn;
    }

    /**
     * Fill empty snapshots. Returns the number of rows updated.
     */
    public function run(): int {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        // Rows lacking a snapshot (seeded before v0.5.2).
        $rows = $wpdb->get_results(
            "SELECT id, match_id, match_kickoff
               FROM {$p}prode_fecha_matches
              WHERE home_team = '' OR home_team IS NULL",
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return 0;
        }

        // Group rows by play-date (Y-m-d) so we hit /partidos once per date.
        $byDate = [];
        foreach ( $rows as $row ) {
            $date              = substr( (string) $row['match_kickoff'], 0, 10 );
            $byDate[ $date ][] = $row;
        }

        $updated = 0;
        foreach ( $byDate as $date => $dateRows ) {
            $map = $this->buildMap( ( $this->partidosByDateFn )( (string) $date ) );

            foreach ( $dateRows as $row ) {
                $matchId = (int) $row['match_id'];
                if ( ! isset( $map[ $matchId ] ) ) {
                    continue; // Not in the played-matches payload — leave for a later run.
                }

                $item     = $map[ $matchId ];
                $homeTeam = (string) ( $item['equipo_local'] ?? '' );
                if ( '' === $homeTeam ) {
                    continue; // No usable name — do not overwrite with emptiness.
                }

                $result = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$p}prode_fecha_matches
                            SET home_team = %s, away_team = %s, zona = %s,
                                home_escudo = %s, away_escudo = %s
                          WHERE id = %d",
                        $homeTeam,
                        (string) ( $item['equipo_visitante'] ?? '' ),
                        (string) ( $item['liga'] ?? '' ),
                        (string) ( $item['escudo_local'] ?? '' ),
                        (string) ( $item['escudo_visitante'] ?? '' ),
                        (int) $row['id']
                    )
                );

                if ( false !== $result ) {
                    $updated++;
                }
            }
        }

        return $updated;
    }

    /**
     * Index a /partidos payload by match id.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function buildMap( array $items ): array {
        $map = [];
        foreach ( $items as $item ) {
            if ( empty( $item['id'] ) ) {
                continue;
            }
            $map[ (int) $item['id'] ] = $item;
        }
        return $map;
    }
}

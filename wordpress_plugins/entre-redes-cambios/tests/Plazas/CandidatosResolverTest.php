<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas;

use EntreRedes\Cambios\Dictamen\BloqueoReemplazoPolicy;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\CandidatosResolver;
use EntreRedes\Cambios\Plazas\CandidatosSeccion;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for CandidatosResolver against the in-memory SQLite
 * shim — real PlazaRepository, plus ad hoc `wp_term_relationships` /
 * `wp_term_taxonomy` / `wp_postmeta` tables (WordPress core tables this
 * plugin's own test schema does not otherwise create — see
 * DictamenContextAssemblerTest for the same pattern applied to `wp_postmeta`
 * alone) and `wp_posts` via wp-shim.php's wp_test_create_posts_table().
 *
 * `wp_term_relationships` / `wp_term_taxonomy` are used ONLY for the real
 * `sp_season` taxonomy (season registration). Team membership (`sp_team`)
 * is NOT a taxonomy in production — it is a `postmeta` row, `meta_key =
 * 'sp_team'`, `meta_value` = the team's post id — so `seedEquipoMembership()`
 * below seeds `wp_postmeta` directly rather than a term relationship. An
 * earlier version of this fixture seeded `sp_team` as a taxonomy term,
 * which encoded the exact wrong assumption the production bug made and
 * would have kept passing against a resolver that never matched a single
 * real row.
 */
class CandidatosResolverTest extends TestCase {

    private const SEASON_ID = 359;
    private const OTHER_SEASON_ID = 360;

    private InMemoryEventLog $eventLog;
    private PlazaRepository $plazaRepository;
    private CandidatosResolver $resolver;

    /** @var callable(int): int */
    private $countResolvedFechasSinceFn;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->query( "DELETE FROM {$p}cambios_ocupacion" );
        $wpdb->query( "DELETE FROM {$p}cambios_plaza" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );

        // Schema owned by wp-shim.php's wp_test_create_posts_table() — see
        // its docblock for why.
        wp_test_create_posts_table( $wpdb );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}term_relationships (
                object_id INTEGER,
                term_taxonomy_id INTEGER
            )"
        );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}term_taxonomy (
                term_taxonomy_id INTEGER PRIMARY KEY,
                term_id INTEGER,
                taxonomy TEXT
            )"
        );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}postmeta (
                meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                meta_key TEXT,
                meta_value TEXT
            )"
        );
        $wpdb->query( "DELETE FROM {$p}term_relationships" );
        $wpdb->query( "DELETE FROM {$p}term_taxonomy" );
        $wpdb->query( "DELETE FROM {$p}postmeta" );

        // ONE sp_season taxonomy row per season, term_taxonomy_id == term_id
        // == season_id for simplicity — nothing in this test cares about the
        // distinction, only the plugin's own raw JOIN does.
        $wpdb->insert( $p . 'term_taxonomy', [ 'term_taxonomy_id' => self::SEASON_ID, 'term_id' => self::SEASON_ID, 'taxonomy' => 'sp_season' ] );
        $wpdb->insert( $p . 'term_taxonomy', [ 'term_taxonomy_id' => self::OTHER_SEASON_ID, 'term_id' => self::OTHER_SEASON_ID, 'taxonomy' => 'sp_season' ] );

        $this->eventLog        = new InMemoryEventLog();
        $this->plazaRepository = new PlazaRepository( $wpdb, $this->eventLog );
        $this->resolver        = new CandidatosResolver( $wpdb, $this->plazaRepository, $this->eventLog );

        $this->countResolvedFechasSinceFn = static fn ( int $fechaId ): int => 10;

        $this->seedFecha( 1, self::SEASON_ID );
    }

    protected function tearDown(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_ocupacion" );
        $wpdb->query( "DELETE FROM {$p}cambios_plaza" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );
        $wpdb->query( "DELETE FROM {$p}posts" );
        $wpdb->query( "DELETE FROM {$p}term_relationships" );
        $wpdb->query( "DELETE FROM {$p}term_taxonomy" );
        $wpdb->query( "DELETE FROM {$p}postmeta" );
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function seedFecha( int $fechaId, int $seasonId, string $playDate = '2026-05-30' ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'cambios_fecha',
            [
                'id'                 => $fechaId,
                'season_id'          => $seasonId,
                'orden'              => $fechaId,
                'torneo_liga_ids'    => '1',
                'torneo_label'       => 'Apertura',
                'numero_en_torneo'   => $fechaId,
                'play_date'          => $playDate,
                'play_date_original' => $playDate,
                'estado'             => 'programada',
                'created_at'         => '2026-01-01 00:00:00',
                'updated_at'         => '2026-01-01 00:00:00',
            ]
        );
    }

    /**
     * @param array<string, mixed>|null $metrics Null = no `sp_metrics` row
     *        and no `caracter` row at all. A `caracter` key, if present, is
     *        written as its own un-serialized ACF postmeta row — NOT nested
     *        inside `sp_metrics` — matching how JugadorMetricasReader now
     *        reads it (see its class docblock, "Reads TWO independent
     *        postmeta values").
     */
    private function seedPlayer( int $playerId, int $seasonId, ?array $metrics ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( $p . 'posts', [ 'ID' => $playerId, 'post_type' => 'sp_player', 'post_status' => 'publish' ] );
        $wpdb->insert( $p . 'term_relationships', [ 'object_id' => $playerId, 'term_taxonomy_id' => $seasonId ] );

        $this->seedMetricas( $playerId, $metrics );
    }

    private function plaza( int $puntajeTechoHalfPoints = 6 /* 3.0 */ ): int {
        return $this->plazaRepository->openPlaza(
            self::SEASON_ID,
            100,
            700,
            Puntaje::fromHalfPoints( $puntajeTechoHalfPoints ),
            1,
            '2026-03-01 10:00:00'
        );
    }

    /**
     * A player with NO `sp_season` registration at all — the exact shape
     * paraSeccion()'s padrón-wide population must still include (unlike
     * playerIdsRegistradosEnTemporada()'s season-scoped query).
     */
    private function seedPlayerSinTemporada( int $playerId, ?array $metrics ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( $p . 'posts', [ 'ID' => $playerId, 'post_type' => 'sp_player', 'post_status' => 'publish' ] );

        $this->seedMetricas( $playerId, $metrics );
    }

    /** @param array<string, mixed>|null $metrics */
    private function seedMetricas( int $playerId, ?array $metrics ): void {
        if ( null === $metrics ) {
            return;
        }

        global $wpdb;
        $p = $wpdb->prefix;

        if ( array_key_exists( 'caracter', $metrics ) ) {
            $wpdb->insert( $p . 'postmeta', [ 'post_id' => $playerId, 'meta_key' => 'caracter', 'meta_value' => (string) $metrics['caracter'] ] );
            unset( $metrics['caracter'] );
        }

        $wpdb->insert( $p . 'postmeta', [ 'post_id' => $playerId, 'meta_key' => 'sp_metrics', 'meta_value' => serialize( $metrics ) ] );
    }

    /**
     * Seeds team membership the way production actually stores it: ordinary
     * `postmeta`, `meta_key = 'sp_team'`, `meta_value` = the team's post id
     * — NOT a taxonomy term relationship. There is no `sp_team` taxonomy in
     * production (confirmed via `GET /wp-json/wp/v2/taxonomies`), so a prior
     * version of this fixture that seeded `wp_term_relationships` /
     * `wp_term_taxonomy` rows encoded the exact wrong assumption the
     * production bug made.
     */
    private function seedEquipoMembership( int $playerId, int $teamId ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'postmeta', [ 'post_id' => $playerId, 'meta_key' => 'sp_team', 'meta_value' => (string) $teamId ] );
    }

    // -------------------------------------------------------------------------
    // paraPlaza() — pool membership
    // -------------------------------------------------------------------------

    public function test_excludes_the_plazas_own_current_vigent_occupant(): void {
        $plazaId = $this->plaza();
        // 700 is the plaza's own titular/vigent occupant (see plaza()).
        $this->seedPlayer( 700, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '3' ] );
        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '3' ] );

        $plaza      = $this->plazaRepository->findPlaza( $plazaId );
        $candidatos = $this->resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );

        $ids = array_map( static fn ( $c ) => $c->playerId(), $candidatos );

        $this->assertNotContains( 700, $ids );
        $this->assertContains( 800, $ids );
    }

    public function test_excludes_players_registered_in_a_different_season(): void {
        $plazaId = $this->plaza();
        $this->seedPlayer( 800, self::OTHER_SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '3' ] );

        $plaza      = $this->plazaRepository->findPlaza( $plazaId );
        $candidatos = $this->resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );

        $this->assertSame( [], $candidatos );
    }

    // -------------------------------------------------------------------------
    // Viability
    // -------------------------------------------------------------------------

    public function test_a_padre_within_techo_and_free_is_viable(): void {
        $plazaId = $this->plaza( 6 ); // techo 3.0
        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );

        $plaza      = $this->plazaRepository->findPlaza( $plazaId );
        $candidatos = $this->resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );

        $this->assertCount( 1, $candidatos );
        $this->assertTrue( $candidatos[0]->esPadre() );
        $this->assertTrue( $candidatos[0]->viable() );
        $this->assertNull( $candidatos[0]->motivoNoViable() );
        $this->assertSame( 2.5, $candidatos[0]->puntaje()->toDecimal() );
    }

    public function test_a_candidate_over_the_techo_is_not_viable(): void {
        $plazaId = $this->plaza( 6 ); // techo 3.0
        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '5' ] );

        $plaza      = $this->plazaRepository->findPlaza( $plazaId );
        $candidatos = $this->resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );

        $this->assertFalse( $candidatos[0]->viable() );
        $this->assertSame( 'puntaje_excede_techo', $candidatos[0]->motivoNoViable() );
    }

    public function test_a_candidate_with_no_resolvable_puntaje_is_not_viable(): void {
        $plazaId = $this->plaza();
        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo' ] ); // no puntaje key

        $plaza      = $this->plazaRepository->findPlaza( $plazaId );
        $candidatos = $this->resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );

        $this->assertFalse( $candidatos[0]->viable() );
        $this->assertSame( 'puntaje_indeterminado', $candidatos[0]->motivoNoViable() );
    }

    /**
     * THE production incident this fix closes (2026-10-04,
     * `entre_redes_cambios_ultimo_error`): a stored puntaje of "0" means "sin
     * calificar", never a legitimate rating of zero — see
     * JugadorMetricasReader's own class docblock. Before the fix, this
     * player's puntaje threw \InvalidArgumentException out of
     * JugadorMetricasReader::resolveMuchos(), which paraPlaza() never
     * catches — aborting the WHOLE candidate list for a single bad row. Must
     * resolve exactly like a missing puntaje: not viable,
     * 'puntaje_indeterminado', no exception.
     */
    public function test_a_candidate_with_stored_puntaje_zero_is_indeterminado_not_an_exception(): void {
        $plazaId = $this->plaza();
        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '0' ] );

        $plaza      = $this->plazaRepository->findPlaza( $plazaId );
        $candidatos = $this->resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );

        $this->assertFalse( $candidatos[0]->viable() );
        $this->assertSame( 'puntaje_indeterminado', $candidatos[0]->motivoNoViable() );
        $this->assertNull( $candidatos[0]->puntaje() );
    }

    public function test_a_candidate_occupying_another_vigent_plaza_is_not_viable(): void {
        $plazaId = $this->plaza();
        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );

        // 800 already vigently occupies a SECOND plaza this season.
        $this->plazaRepository->openPlaza( self::SEASON_ID, 200, 800, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );

        $plaza      = $this->plazaRepository->findPlaza( $plazaId );
        $candidatos = $this->resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );

        $candidato800 = current( array_filter( $candidatos, static fn ( $c ) => 800 === $c->playerId() ) );
        $this->assertFalse( $candidato800->viable() );
        $this->assertSame( 'ocupa_otra_plaza_vigente', $candidato800->motivoNoViable() );
    }

    /**
     * THE case that motivated the definition of "viable" (see
     * CandidatosResolver's own class docblock and the task brief): a padre
     * with a puntaje that fits the techo, but BLOCKED elsewhere by a trunca
     * closure, must NOT count as viable — otherwise a non-padre entrante
     * would be blocked by Reglas\PrioridadDePadresRespetada against a padre
     * who could not actually take the plaza either, leaving the team unable
     * to change anyone.
     */
    public function test_a_padre_blocked_by_a_trunca_closure_elsewhere_is_not_viable(): void {
        $this->seedFecha( 4, self::SEASON_ID, '2026-04-01' );
        $this->seedFecha( 7, self::SEASON_ID, '2026-05-01' );

        $plazaId = $this->plaza();
        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );

        // 800 left ANOTHER plaza 'trunca' — only 2 resolved fechas have
        // passed since (< 3, TOPE_TRES_FECHAS keeps them blocked).
        $otraPlazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 200, 111, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->plazaRepository->succeedOcupacion( $otraPlazaId, 800, 4, 'reemplazada', '2026-04-01 10:00:00' );
        $this->plazaRepository->succeedOcupacion( $otraPlazaId, 999, 7, 'trunca', '2026-05-01 10:00:00' );

        $plaza = $this->plazaRepository->findPlaza( $plazaId );
        $countResolvedFechasSinceFn = static fn ( int $fechaId ): int => 7 === $fechaId ? 2 : 10;

        $candidatos = $this->resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $countResolvedFechasSinceFn );

        $candidato800 = current( array_filter( $candidatos, static fn ( $c ) => 800 === $c->playerId() ) );
        $this->assertFalse( $candidato800->viable() );
        $this->assertSame( 'bloqueado_por_cierre_truncado', $candidato800->motivoNoViable() );
        $this->assertSame( 0, $this->resolver->contarPadresViables( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $countResolvedFechasSinceFn ) );
    }

    /**
     * `BloqueoReemplazoEvaluator::isBlocked()` has a
     * `BloqueoReemplazoPolicy::hastaLiberacionDePlaza()` branch that the test
     * above never exercises (it only ever passes `topeTresFechas()`) — this
     * drives the candidate pool with the OTHER policy reading, so this
     * branch is reached through CandidatosResolver too, not only through
     * BloqueoReemplazoEvaluatorTest's own direct unit tests.
     */
    public function test_a_padre_blocked_under_hasta_liberacion_de_plaza_is_not_viable_before_the_other_plaza_liberates(): void {
        $this->seedFecha( 4, self::SEASON_ID, '2026-04-01' );
        $this->seedFecha( 7, self::SEASON_ID, '2026-05-01' );

        $plazaId = $this->plaza();
        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );

        // 800 left ANOTHER plaza 'trunca' at fecha 7; its new vigent
        // occupant (999) has NOT yet cleared the mínimo since fecha 7 — the
        // other plaza itself has not liberated, so under
        // hastaLiberacionDePlaza() 800 stays blocked regardless of how many
        // fechas passed since 800's OWN closure.
        $otraPlazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->plazaRepository->succeedOcupacion( $otraPlazaId, 800, 4, 'reemplazada', '2026-04-01 10:00:00' );
        $this->plazaRepository->succeedOcupacion( $otraPlazaId, 999, 7, 'trunca', '2026-05-01 10:00:00' );

        $plaza                      = $this->plazaRepository->findPlaza( $plazaId );
        $countResolvedFechasSinceFn = static fn ( int $fechaId ): int => 7 === $fechaId ? 2 : 10;

        $candidatos = $this->resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::hastaLiberacionDePlaza(), $countResolvedFechasSinceFn );

        $candidato800 = current( array_filter( $candidatos, static fn ( $c ) => 800 === $c->playerId() ) );
        $this->assertFalse( $candidato800->viable() );
        $this->assertSame( 'bloqueado_por_cierre_truncado', $candidato800->motivoNoViable() );
    }

    public function test_a_padre_becomes_viable_under_hasta_liberacion_de_plaza_once_the_other_plaza_liberates(): void {
        $this->seedFecha( 4, self::SEASON_ID, '2026-04-01' );
        $this->seedFecha( 7, self::SEASON_ID, '2026-05-01' );

        $plazaId = $this->plaza();
        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );

        $otraPlazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->plazaRepository->succeedOcupacion( $otraPlazaId, 800, 4, 'reemplazada', '2026-04-01 10:00:00' );
        $this->plazaRepository->succeedOcupacion( $otraPlazaId, 999, 7, 'trunca', '2026-05-01 10:00:00' );

        $plaza = $this->plazaRepository->findPlaza( $plazaId );
        // Now 999 (the other plaza's vigent occupant since fecha 7) HAS
        // cleared the mínimo — the other plaza has liberated, so
        // hastaLiberacionDePlaza() unblocks every 'trunca' ex-occupant of
        // that plaza at once, 800 included.
        $countResolvedFechasSinceFn = static fn ( int $fechaId ): int => 7 === $fechaId ? 3 : 10;

        $candidatos = $this->resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::hastaLiberacionDePlaza(), $countResolvedFechasSinceFn );

        $candidato800 = current( array_filter( $candidatos, static fn ( $c ) => 800 === $c->playerId() ) );
        $this->assertTrue( $candidato800->viable() );
        $this->assertNull( $candidato800->motivoNoViable() );
    }

    // -------------------------------------------------------------------------
    // contarPadresViables()
    // -------------------------------------------------------------------------

    public function test_contar_padres_viables_counts_only_viable_padres(): void {
        $plazaId = $this->plaza( 6 ); // techo 3.0

        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] ); // viable padre
        $this->seedPlayer( 801, self::SEASON_ID, [ 'caracter' => 'Padre de Alumno', 'puntaje' => '3' ] ); // viable padre
        $this->seedPlayer( 802, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '5' ] ); // padre pero excede techo
        $this->seedPlayer( 803, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2,5' ] ); // viable pero NO padre

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $this->assertSame(
            2,
            $this->resolver->contarPadresViables( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn )
        );
    }

    // -------------------------------------------------------------------------
    // FIX 1 (BLOCKER) — a failed candidate-pool read must never read as
    // "zero candidates"
    // -------------------------------------------------------------------------

    /**
     * A `\wpdb` subclass whose get_results() sets $wpdb->last_error and
     * returns [] whenever the SQL contains $mustContain — same pattern as
     * `PlazaRepositoryTest::wpdbThatFailsGetResults()`, which this mirrors.
     */
    private function wpdbThatFailsGetResults( \wpdb $real, string $mustContain ): \wpdb {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $real );

        return new class( $pdo, $real->prefix, $mustContain ) extends \wpdb {
            private string $mustContain;

            public function __construct( \PDO $pdo, string $prefix, string $mustContain ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix      = $prefix;
                $this->mustContain = $mustContain;
            }

            public function get_results( string $sql, string $output = OBJECT ): array {
                if ( str_contains( $sql, $this->mustContain ) ) {
                    $this->last_error = 'simulated get_results failure for test';
                    return [];
                }

                return parent::get_results( $sql, $output );
            }
        };
    }

    /**
     * THE blocker this fix closes: before it, a failed
     * `playerIdsRegistradosEnTemporada()` read degraded via `$rows ?: []`
     * into "zero candidates" — this proves it now throws instead.
     */
    public function test_para_plaza_throws_when_the_player_ids_query_fails(): void {
        global $wpdb;

        $plazaId = $this->plaza();
        $plaza   = $this->plazaRepository->findPlaza( $plazaId );

        $failingWpdb     = $this->wpdbThatFailsGetResults( $wpdb, 'sp_season' );
        $failingEventLog = new InMemoryEventLog();
        $failingResolver = new CandidatosResolver( $failingWpdb, $this->plazaRepository, $failingEventLog );

        $this->expectException( \RuntimeException::class );

        $failingResolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );
    }

    public function test_para_plaza_records_a_lectura_fallida_event_before_throwing(): void {
        global $wpdb;

        $plazaId = $this->plaza();
        $plaza   = $this->plazaRepository->findPlaza( $plazaId );

        $failingWpdb     = $this->wpdbThatFailsGetResults( $wpdb, 'sp_season' );
        $failingEventLog = new InMemoryEventLog();
        $failingResolver = new CandidatosResolver( $failingWpdb, $this->plazaRepository, $failingEventLog );

        try {
            $failingResolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );
            $this->fail( 'Expected RuntimeException.' );
        } catch ( \RuntimeException $e ) {
            // expected
        }

        $this->assertTrue( $failingEventLog->has( 'lectura.fallida' ) );
        $this->assertSame( 'playerIdsRegistradosEnTemporada', $failingEventLog->last()['contexto']['operacion'] );
        $this->assertNotNull( $failingEventLog->last()['contexto']['last_error'] ?? null );
    }

    /**
     * THE end-to-end proof of WHY this matters: with the padre-priority
     * policy ON and a non-padre entrante, a failing candidate-pool read must
     * NEVER produce a silent approval — it must surface as a propagated
     * failure. contarPadresViables() is the exact method
     * Reglas\PrioridadDePadresRespetada consults for its `padresViables <= 0
     * -> approve` shortcut (see that rule's own docblock); before FIX 1, the
     * failed read below would have silently become `0`, and this scenario
     * would have wrongly approved a non-padre entrante while a viable padre
     * genuinely existed.
     */
    public function test_contar_padres_viables_never_silently_approves_when_the_read_fails(): void {
        global $wpdb;

        $plazaId = $this->plaza( 6 ); // techo 3.0

        // A viable padre genuinely exists in the season roster — if the read
        // failure below were swallowed into "zero candidates", this
        // scenario would wrongly report 0 padres viables instead of failing.
        $this->seedPlayer( 900, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $failingWpdb     = $this->wpdbThatFailsGetResults( $wpdb, 'sp_season' );
        $failingEventLog = new InMemoryEventLog();
        $failingResolver = new CandidatosResolver( $failingWpdb, $this->plazaRepository, $failingEventLog );

        try {
            $failingResolver->contarPadresViables( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );
            $this->fail( 'contarPadresViables() must propagate the read failure, never silently answer 0.' );
        } catch ( \RuntimeException $e ) {
            // expected: the failure surfaced instead of becoming a silent
            // "no padres viables" that would have approved the non-padre
            // entrante.
            $this->assertInstanceOf( \RuntimeException::class, $e );
        }
    }

    // -------------------------------------------------------------------------
    // paraSeccion() — the two widened screen sections
    // -------------------------------------------------------------------------

    private const LISTA_ESPERA_TEAM_ID = 500;

    public function test_para_seccion_rejects_an_invalid_seccion(): void {
        $plazaId = $this->plaza();
        $plaza   = $this->plazaRepository->findPlaza( $plazaId );

        $this->expectException( \InvalidArgumentException::class );

        $this->resolver->paraSeccion( $plaza, 'no_existe', self::LISTA_ESPERA_TEAM_ID, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );
    }

    public function test_lista_espera_seccion_returns_only_the_teams_players(): void {
        $plazaId = $this->plaza( 10 ); // techo 5.0 — nobody excluded by techo here

        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );
        $this->seedEquipoMembership( 800, self::LISTA_ESPERA_TEAM_ID );

        // Season-registered, but NOT on the lista de espera team — must NOT
        // appear in this section.
        $this->seedPlayer( 801, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2,5' ] );

        $plaza      = $this->plazaRepository->findPlaza( $plazaId );
        $candidatos = $this->resolver->paraSeccion(
            $plaza,
            CandidatosSeccion::LISTA_ESPERA,
            self::LISTA_ESPERA_TEAM_ID,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn
        );

        $ids = array_map( static fn ( $c ) => $c->playerId(), $candidatos );

        $this->assertSame( [ 800 ], $ids );
    }

    public function test_lista_espera_seccion_excludes_the_plazas_own_current_vigent_occupant(): void {
        $plazaId = $this->plaza( 10 );

        // 700 is the plaza's own titular/vigent occupant (see plaza()).
        $this->seedPlayer( 700, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );
        $this->seedEquipoMembership( 700, self::LISTA_ESPERA_TEAM_ID );

        $plaza      = $this->plazaRepository->findPlaza( $plazaId );
        $candidatos = $this->resolver->paraSeccion(
            $plaza,
            CandidatosSeccion::LISTA_ESPERA,
            self::LISTA_ESPERA_TEAM_ID,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn
        );

        $this->assertSame( [], $candidatos );
    }

    /**
     * THE widened-pool behavior the process owner asked for: a padrón
     * completo candidate who carries NO `sp_season` registration at all for
     * the current season must still appear here — unlike paraPlaza()'s own
     * season-scoped population (see test_excludes_players_registered_in_a_different_season()
     * above, which paraPlaza() keeps unchanged).
     */
    public function test_padron_completo_seccion_includes_a_player_with_no_season_registration(): void {
        $plazaId = $this->plaza( 10 );

        $this->seedPlayerSinTemporada( 900, [ 'caracter' => 'Padre Ex-Alumno', 'puntaje' => '2,5' ] );

        $plaza      = $this->plazaRepository->findPlaza( $plazaId );
        $candidatos = $this->resolver->paraSeccion(
            $plaza,
            CandidatosSeccion::PADRON_COMPLETO,
            self::LISTA_ESPERA_TEAM_ID,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn
        );

        $ids = array_map( static fn ( $c ) => $c->playerId(), $candidatos );
        $this->assertContains( 900, $ids );
    }

    public function test_padron_completo_seccion_excludes_the_lista_de_espera_teams_players(): void {
        $plazaId = $this->plaza( 10 );

        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );
        $this->seedEquipoMembership( 800, self::LISTA_ESPERA_TEAM_ID );

        $this->seedPlayer( 801, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2,5' ] );

        $plaza      = $this->plazaRepository->findPlaza( $plazaId );
        $candidatos = $this->resolver->paraSeccion(
            $plaza,
            CandidatosSeccion::PADRON_COMPLETO,
            self::LISTA_ESPERA_TEAM_ID,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn
        );

        $ids = array_map( static fn ( $c ) => $c->playerId(), $candidatos );

        $this->assertNotContains( 800, $ids, 'Lista de espera members must never appear in padron_completo.' );
        $this->assertContains( 801, $ids );
    }

    // -------------------------------------------------------------------------
    // Ceiling filter runs BEFORE the per-candidate viability queries
    // -------------------------------------------------------------------------

    /**
     * A `PlazaRepository` subclass that counts every call to the two
     * per-candidate viability queries evaluarCandidatos() runs AFTER the
     * ceiling filter — real behavior otherwise (delegates to `parent::`), so
     * this proves the actual query COUNT, not just the resulting viability
     * verdicts.
     */
    private function countingPlazaRepository(): object {
        global $wpdb;
        $eventLog = $this->eventLog;

        return new class( $wpdb, $eventLog ) extends PlazaRepository {
            public int $vigentesCalls = 0;
            public int $truncadoCalls = 0;

            public function listOcupacionesVigentesDeJugador( int $seasonId, int $playerId, ?int $excluyendoPlazaId = null ): array {
                ++$this->vigentesCalls;

                return parent::listOcupacionesVigentesDeJugador( $seasonId, $playerId, $excluyendoPlazaId );
            }

            public function listPlazasConCierreTruncadoDeJugador( int $seasonId, int $playerId ): array {
                ++$this->truncadoCalls;

                return parent::listPlazasConCierreTruncadoDeJugador( $seasonId, $playerId );
            }
        };
    }

    /**
     * THE measured claim this slice's task brief asks for: at a realistic
     * ceiling, candidates whose puntaje exceeds the techo cost ZERO calls to
     * either per-candidate viability query — only the candidates who clear
     * the techo do. 5 candidates seeded, puntajes 2 / 2,5 / 3 / 4 / 5; with
     * techo 2,5 only 2 of them clear it, so the viability queries must run
     * exactly 2 times each, never 5.
     */
    public function test_ceiling_filter_runs_before_the_per_candidate_viability_queries(): void {
        global $wpdb;

        $countingPlazaRepository = $this->countingPlazaRepository();
        $resolver                = new CandidatosResolver( $wpdb, $countingPlazaRepository, $this->eventLog );

        $plazaId = $countingPlazaRepository->openPlaza(
            self::SEASON_ID,
            100,
            700,
            Puntaje::fromDecimal( 2.5 ), // techo 2,5
            1,
            '2026-03-01 10:00:00'
        );

        $this->seedPlayer( 801, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2' ] );   // clears techo
        $this->seedPlayer( 802, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2,5' ] ); // clears techo (boundary)
        $this->seedPlayer( 803, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '3' ] );   // excluded by techo
        $this->seedPlayer( 804, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '4' ] );   // excluded by techo
        $this->seedPlayer( 805, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '5' ] );   // excluded by techo

        $plaza      = $countingPlazaRepository->findPlaza( $plazaId );
        $candidatos = $resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );

        $this->assertCount( 5, $candidatos, 'Every candidate must still be reported, only 3 as non-viable by techo.' );

        $viableIds = array_map(
            static fn ( $c ) => $c->playerId(),
            array_filter( $candidatos, static fn ( $c ) => $c->viable() )
        );
        $this->assertSame( [ 801, 802 ], array_values( $viableIds ) );

        $this->assertSame(
            2,
            $countingPlazaRepository->vigentesCalls,
            'listOcupacionesVigentesDeJugador() must run ONLY for the 2 candidates within techo, never for all 5.'
        );
        $this->assertSame(
            2,
            $countingPlazaRepository->truncadoCalls,
            'listPlazasConCierreTruncadoDeJugador() must run ONLY for the 2 candidates within techo, never for all 5.'
        );
    }

    /**
     * Same proof, at a WIDER ceiling: raising techo from 2,5 to 5,0 admits 2
     * more candidates into the N+1 — a direct measurement of "a 2.5 plaza
     * costs roughly a fifth of a 5 plaza" (this slice's task brief), not an
     * assertion that it does.
     */
    public function test_a_wider_techo_measurably_costs_more_viability_queries(): void {
        global $wpdb;

        $countingPlazaRepository = $this->countingPlazaRepository();
        $resolver                = new CandidatosResolver( $wpdb, $countingPlazaRepository, $this->eventLog );

        $plazaId = $countingPlazaRepository->openPlaza(
            self::SEASON_ID,
            100,
            700,
            Puntaje::fromDecimal( 5.0 ), // techo 5,0 — admits everyone below
            1,
            '2026-03-01 10:00:00'
        );

        $this->seedPlayer( 801, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2' ] );
        $this->seedPlayer( 802, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2,5' ] );
        $this->seedPlayer( 803, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '3' ] );
        $this->seedPlayer( 804, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '4' ] );
        $this->seedPlayer( 805, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '5' ] );

        $plaza = $countingPlazaRepository->findPlaza( $plazaId );
        $resolver->paraPlaza( $plaza, BloqueoReemplazoPolicy::topeTresFechas(), $this->countResolvedFechasSinceFn );

        $this->assertSame( 5, $countingPlazaRepository->vigentesCalls );
        $this->assertSame( 5, $countingPlazaRepository->truncadoCalls );
    }

    // -------------------------------------------------------------------------
    // buscarPaginado() — pagination, search, puntaje filter
    // -------------------------------------------------------------------------

    private function seedManyPlayers( int $count, int $startId = 1000 ): void {
        for ( $i = 0; $i < $count; $i++ ) {
            $this->seedPlayer( $startId + $i, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2,5' ] );
        }
    }

    public function test_buscar_paginado_rejects_an_invalid_seccion(): void {
        $plazaId = $this->plaza();
        $plaza   = $this->plazaRepository->findPlaza( $plazaId );

        $this->expectException( \InvalidArgumentException::class );

        $this->resolver->buscarPaginado(
            $plaza,
            'no_existe',
            self::LISTA_ESPERA_TEAM_ID,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            1,
            10
        );
    }

    /**
     * THE correctness test this slice's task brief names explicitly: no
     * candidate must ever be duplicated across pages or silently skipped,
     * and the ordering must be stable (player_id ascending, never puntaje —
     * see buscarPaginado()'s own docblock, "WHY player_id, NEVER puntaje").
     */
    public function test_buscar_paginado_pages_through_the_whole_list_exactly_once_with_no_duplicates(): void {
        $plazaId = $this->plaza( 10 ); // techo 5.0 — nobody excluded by techo here
        $this->seedManyPlayers( 25 );

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $seen = [];
        for ( $page = 1; $page <= 3; $page++ ) {
            $resultado = $this->resolver->buscarPaginado(
                $plaza,
                null,
                null,
                BloqueoReemplazoPolicy::topeTresFechas(),
                $this->countResolvedFechasSinceFn,
                $page,
                10
            );

            $this->assertSame( 25, $resultado['total'], "total must stay 25 regardless of which page (page {$page}) is requested." );

            foreach ( $resultado['candidatos'] as $c ) {
                $seen[] = $c->playerId();
            }
        }

        $this->assertCount( 25, $seen, 'Every candidate must appear exactly once across all pages.' );
        $this->assertCount( 25, array_unique( $seen ), 'No candidate must be duplicated across pages.' );
        $this->assertSame( range( 1000, 1024 ), $seen, 'Pages must be stably ordered by player_id ascending.' );
    }

    /**
     * An out-of-range page is a normal empty page, never an error — see
     * buscarPaginado()'s own docblock, @param $page.
     */
    public function test_buscar_paginado_out_of_range_page_is_an_empty_page_not_an_error(): void {
        $plazaId = $this->plaza( 10 );
        $this->seedManyPlayers( 5 );

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $resultado = $this->resolver->buscarPaginado(
            $plaza,
            null,
            null,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            99,
            10
        );

        $this->assertSame( [], $resultado['candidatos'] );
        $this->assertSame( 5, $resultado['total'], 'total must still report the real population size, not 0.' );
    }

    /**
     * THE measured claim buscarPaginado() exists for: the per-candidate
     * viability queries (evaluarViabilidadDentroDelTecho(), via
     * PlazaRepository::listOcupacionesVigentesDeJugador() /
     * ::listPlazasConCierreTruncadoDeJugador()) must run ONLY for the
     * returned page, never the whole population — 10 candidates seeded, a
     * page of 3 requested, so each must run exactly 3 times, never 10. Same
     * counting-double pattern as
     * test_ceiling_filter_runs_before_the_per_candidate_viability_queries().
     */
    public function test_buscar_paginado_runs_the_viability_queries_only_for_the_returned_page(): void {
        global $wpdb;

        $countingPlazaRepository = $this->countingPlazaRepository();
        $resolver                = new CandidatosResolver( $wpdb, $countingPlazaRepository, $this->eventLog );

        $plazaId = $countingPlazaRepository->openPlaza(
            self::SEASON_ID,
            100,
            700,
            Puntaje::fromDecimal( 5.0 ), // techo 5,0 — admits everyone below
            1,
            '2026-03-01 10:00:00'
        );

        for ( $i = 0; $i < 10; $i++ ) {
            $this->seedPlayer( 2000 + $i, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2,5' ] );
        }

        $plaza = $countingPlazaRepository->findPlaza( $plazaId );

        $resultado = $resolver->buscarPaginado(
            $plaza,
            null,
            null,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            1,
            3
        );

        $this->assertCount( 3, $resultado['candidatos'] );
        $this->assertSame( 10, $resultado['total'] );
        $this->assertSame(
            3,
            $countingPlazaRepository->vigentesCalls,
            'listOcupacionesVigentesDeJugador() must run ONLY for the 3 candidates on this page, never all 10.'
        );
        $this->assertSame(
            3,
            $countingPlazaRepository->truncadoCalls,
            'listPlazasConCierreTruncadoDeJugador() must run ONLY for the 3 candidates on this page, never all 10.'
        );
    }

    /**
     * THE other correctness bug this slice's task brief names explicitly: a
     * search term matching a player who would otherwise fall on a LATER page
     * must still be found — because $search narrows the population BEFORE
     * pagination (see buscarPaginado()'s own docblock), not after.
     */
    public function test_buscar_paginado_search_finds_a_player_on_a_later_page(): void {
        global $wpdb;

        $plazaId = $this->plaza( 10 );
        $this->seedManyPlayers( 20 ); // ids 1000..1019, no title set

        // Seeded LAST (highest id) — would land on page 2 of a 10-per-page
        // listing if search ran only AFTER pagination instead of narrowing
        // the population first.
        $this->seedPlayer( 9999, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2,5' ] );
        $wpdb->update( $wpdb->prefix . 'posts', [ 'post_title' => 'Zapata Buscado' ], [ 'ID' => 9999 ] );

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $resultado = $this->resolver->buscarPaginado(
            $plaza,
            null,
            null,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            1,
            10,
            'Zapata'
        );

        $this->assertSame( 1, $resultado['total'] );
        $this->assertCount( 1, $resultado['candidatos'] );
        $this->assertSame( 9999, $resultado['candidatos'][0]->playerId() );
    }

    /**
     * A literal '%' or '_' typed into the search box must be matched AS
     * TEXT, never as a SQL LIKE wildcard — otherwise a captain typing '%'
     * would match every player in the population instead of getting an
     * empty (or genuinely matching) result. Before this fix,
     * playerIdsRegistradosEnTemporada() interpolated $search into the LIKE
     * pattern unescaped, so '%' matched everything.
     */
    public function test_buscar_paginado_search_escapes_like_wildcards(): void {
        $plazaId = $this->plaza( 10 );
        $this->seedManyPlayers( 5 ); // ids 1000..1004.
        $this->seedPlayer( 9000, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2,5' ] );

        global $wpdb;
        // Every "other" player gets a REAL, non-null, non-matching title —
        // `seedManyPlayers()` itself leaves post_title NULL, and `NULL LIKE
        // '%...%'` is NULL (falsy) regardless of escaping, which would make
        // this test pass even with the bug still present (nothing for an
        // unescaped '%' to wildcard-match against). An actual title is what
        // an unescaped '%' would incorrectly match against.
        foreach ( range( 1000, 1004 ) as $otroId ) {
            $wpdb->update( $wpdb->prefix . 'posts', [ 'post_title' => "Jugador Distinto $otroId" ], [ 'ID' => $otroId ] );
        }
        $wpdb->update( $wpdb->prefix . 'posts', [ 'post_title' => '100% Seguro' ], [ 'ID' => 9000 ] );

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $resultado = $this->resolver->buscarPaginado(
            $plaza,
            null,
            null,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            1,
            10,
            '%'
        );

        $this->assertSame(
            1,
            $resultado['total'],
            "A literal '%' search must match only the player whose name contains a literal '%', "
            . 'not every player in the population.'
        );
        $this->assertSame( 9000, $resultado['candidatos'][0]->playerId() );
    }

    /**
     * Same concern as test_buscar_paginado_search_escapes_like_wildcards(),
     * for the OTHER `LIKE` wildcard: a literal '_' typed into the search box
     * must match only a title that actually contains '_', never act as the
     * single-character wildcard and match everything else too. As with that
     * test, every "other" player gets a REAL, non-matching title — a NULL
     * title would pass this test even with wildcard escaping completely
     * broken, since `NULL LIKE '%...%'` is never true regardless of
     * escaping.
     */
    public function test_buscar_paginado_search_escapes_like_underscore_wildcard(): void {
        $plazaId = $this->plaza( 10 );
        $this->seedManyPlayers( 5 ); // ids 1000..1004.
        $this->seedPlayer( 9001, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2,5' ] );

        global $wpdb;
        foreach ( range( 1000, 1004 ) as $otroId ) {
            $wpdb->update( $wpdb->prefix . 'posts', [ 'post_title' => "Jugador Distinto $otroId" ], [ 'ID' => $otroId ] );
        }
        $wpdb->update( $wpdb->prefix . 'posts', [ 'post_title' => 'Zapata_Suplente' ], [ 'ID' => 9001 ] );

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $resultado = $this->resolver->buscarPaginado(
            $plaza,
            null,
            null,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            1,
            10,
            '_'
        );

        $this->assertSame(
            1,
            $resultado['total'],
            "A literal '_' search must match only the player whose name contains a literal '_', "
            . 'not every player in the population (the single-character wildcard would otherwise '
            . 'match every title of at least one character).'
        );
        $this->assertSame( 9001, $resultado['candidatos'][0]->playerId() );
    }

    /**
     * Pins the EXACT `LIKE ... ESCAPE` clause this class emits, at the SQL
     * level — the gap the behavioural wildcard tests above cannot close.
     * Both `ESCAPE '\'` (the production bug) and `ESCAPE '!'` (the fix)
     * return IDENTICAL rows under this plugin's SQLite-backed test shim —
     * SQLite does not give backslash any special meaning inside a string
     * literal, so it happily accepts and correctly evaluates `ESCAPE '\'`.
     * MySQL does not: its own string-literal parser treats `\'` as an
     * escaped quote, so that clause never closes the literal and the query
     * fails as a syntax error in production (see CandidatosResolver's
     * escapeLikeTerm() docblock for the documented MySQL behavior this is
     * based on). No assertion that runs against this SQLite shim can
     * exercise MySQL's parser directly — this test proves only that the
     * generated SQL TEXT uses `ESCAPE '!'`, which is what makes the clause
     * engine-agnostic; it does NOT prove the query runs correctly against a
     * real MySQL server, which would require an actual MySQL integration
     * test this suite does not have.
     */
    public function test_buscar_paginado_search_pins_escape_character_in_generated_sql(): void {
        global $wpdb;
        $wpdb->queries = [];

        $plazaId = $this->plaza( 10 );
        $plaza   = $this->plazaRepository->findPlaza( $plazaId );

        $this->resolver->buscarPaginado(
            $plaza,
            null,
            null,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            1,
            10,
            'Zapata'
        );

        $likeQueries = array_values( array_filter(
            $wpdb->queries,
            static fn ( string $sql ): bool => str_contains( $sql, 'post_title LIKE' )
        ) );

        $this->assertNotEmpty( $likeQueries, 'Expected a post_title LIKE query to run when $search is non-empty.' );

        foreach ( $likeQueries as $sql ) {
            $this->assertStringContainsString(
                "ESCAPE '!'",
                $sql,
                "The generated LIKE clause must use ESCAPE '!'. If this fails because the clause "
                . "reverted to ESCAPE '\\'' (a backslash), that change passes every OTHER test in "
                . 'this suite under the SQLite shim while breaking on real MySQL — see this test\'s '
                . 'own docblock.'
            );
            $this->assertStringNotContainsString(
                'ESCAPE \'\\',
                $sql,
                'A backslash must never be the LIKE escape character — see escapeLikeTerm() docblock.'
            );
        }
    }

    /**
     * $puntajesFiltro (the app's puntaje chips) excludes non-matching
     * candidates from the POPULATION entirely — including one whose puntaje
     * is unresolvable, which can never match a specific requested value.
     */
    public function test_buscar_paginado_puntajes_filtro_excludes_non_matching_puntajes(): void {
        $plazaId = $this->plaza( 10 );
        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2,5' ] );
        $this->seedPlayer( 801, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '4' ] );
        $this->seedPlayer( 802, self::SEASON_ID, [ 'caracter' => 'Invitado' ] ); // sin puntaje

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $resultado = $this->resolver->buscarPaginado(
            $plaza,
            null,
            null,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            1,
            10,
            '',
            [ 2.5 ]
        );

        $this->assertSame( 1, $resultado['total'] );
        $this->assertSame( [ 800 ], array_map( static fn ( $c ) => $c->playerId(), $resultado['candidatos'] ) );
    }

    /**
     * COMPOSITION: $puntajesFiltro and pagination must combine correctly —
     * the filter narrows the POPULATION first (see `buscarPaginado()`'s own
     * docblock), and pagination then slices THAT narrowed population, never
     * the other way around. A larger population is seeded here so a
     * regression that paginated BEFORE filtering (returning an empty or
     * wrong page 2) would be caught — the single-page tests above
     * (`test_buscar_paginado_puntajes_filtro_excludes_non_matching_puntajes`)
     * never exercise a second page at all.
     */
    public function test_buscar_paginado_puntajes_filtro_combined_with_page_2(): void {
        $plazaId = $this->plaza( 10 );

        // 15 matching players (ids 2000..2014) interleaved with 5
        // non-matching ones (ids 2100..2104) — the non-matching ones must
        // never count towards the filtered population's total or its
        // pagination.
        for ( $i = 0; $i < 15; $i++ ) {
            $this->seedPlayer( 2000 + $i, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2,5' ] );
        }
        for ( $i = 0; $i < 5; $i++ ) {
            $this->seedPlayer( 2100 + $i, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '4' ] );
        }

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $resultado = $this->resolver->buscarPaginado(
            $plaza,
            null,
            null,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            2,
            10,
            '',
            [ 2.5 ]
        );

        // Page 1 (ids 2000..2009) already consumed the first 10 of the 15
        // matching players — page 2 must return exactly the remaining 5
        // (ids 2010..2014), never bleed in a non-matching player or wrap
        // around to page 1's players again.
        $this->assertSame( 15, $resultado['total'] );
        $this->assertSame(
            [ 2010, 2011, 2012, 2013, 2014 ],
            array_map( static fn ( $c ) => $c->playerId(), $resultado['candidatos'] )
        );
    }

    public function test_buscar_paginado_seccion_padron_completo_still_excludes_lista_de_espera(): void {
        $plazaId = $this->plaza( 10 );

        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Padre Activo', 'puntaje' => '2,5' ] );
        $this->seedEquipoMembership( 800, self::LISTA_ESPERA_TEAM_ID );

        $this->seedPlayer( 801, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2,5' ] );

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $resultado = $this->resolver->buscarPaginado(
            $plaza,
            CandidatosSeccion::PADRON_COMPLETO,
            self::LISTA_ESPERA_TEAM_ID,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            1,
            10
        );

        $ids = array_map( static fn ( $c ) => $c->playerId(), $resultado['candidatos'] );
        $this->assertNotContains( 800, $ids );
        $this->assertContains( 801, $ids );
    }

    // -------------------------------------------------------------------------
    // buscarPaginado() — ordering (puntaje DESC, nombre ASC, player_id ASC)
    // -------------------------------------------------------------------------

    /**
     * THE correctness test the task brief names explicitly: a population
     * spanning SEVERAL puntajes and names must page through, across multiple
     * `?page=` calls, in EXACTLY the global order `puntaje DESC, nombre ASC,
     * player_id ASC` predicts — no candidate duplicated, none skipped. This
     * is the test that would catch a non-total sort key (see
     * `buscarPaginado()`'s own docblock, "THE SORT KEY") — a key that is
     * only stable WITHIN a page, but not across pages, would show up here as
     * a duplicate or a gap in $seen.
     *
     * Two unrated candidates (306, 307) are seeded ALONGSIDE the rated ones
     * specifically to prove the exclusion filter (see class docblock, "AN
     * UNRESOLVABLE PUNTAJE IS NOT A CANDIDATE AT ALL — IN buscarPaginado()
     * ONLY") holds even mid-population, not only at the edges: `total` must
     * count only the 5 rated candidates, and neither 306 nor 307 may ever
     * appear on any page, regardless of how many pages are requested.
     */
    public function test_buscar_paginado_pages_through_a_mixed_population_in_the_exact_global_order(): void {
        $plazaId = $this->plaza( 10 ); // techo 5.0 — nobody excluded by techo here
        global $wpdb;
        $p = $wpdb->prefix;

        $this->seedPlayer( 301, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '5' ] );
        $this->seedPlayer( 302, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '5' ] );
        $this->seedPlayer( 303, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '4' ] );
        $this->seedPlayer( 304, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '4' ] );
        $this->seedPlayer( 305, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2,5' ] );
        $this->seedPlayer( 306, self::SEASON_ID, [ 'caracter' => 'Invitado' ] ); // sin puntaje — must be excluded
        $this->seedPlayer( 307, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '0' ] ); // "sin calificar" — must be excluded too

        foreach ( [ 301 => 'Beta', 302 => 'Alfa', 303 => 'Delta', 304 => 'Charlie', 305 => 'Echo', 306 => 'Zulu', 307 => 'Aaa' ] as $id => $nombre ) {
            $wpdb->update( "{$p}posts", [ 'post_title' => $nombre ], [ 'ID' => $id ] );
        }

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $seen = [];
        for ( $page = 1; $page <= 2; $page++ ) {
            $resultado = $this->resolver->buscarPaginado(
                $plaza,
                null,
                null,
                BloqueoReemplazoPolicy::topeTresFechas(),
                $this->countResolvedFechasSinceFn,
                $page,
                3
            );

            $this->assertSame( 5, $resultado['total'], "total must count only the 5 rated candidates, regardless of which page (page {$page}) is requested." );

            foreach ( $resultado['candidatos'] as $c ) {
                $seen[] = $c->playerId();
            }
        }

        $this->assertCount( 5, array_unique( $seen ), 'No rated candidate must be duplicated or skipped across pages.' );
        $this->assertNotContains( 306, $seen, 'An unrated candidate (no puntaje key at all) must never appear on any page.' );
        $this->assertNotContains( 307, $seen, 'A candidate whose stored puntaje is "0" ("sin calificar") must never appear on any page.' );
        $this->assertSame(
            [ 302, 301, 304, 303, 305 ],
            $seen,
            'Expected global order over the RATED population only: puntaje 5 (Alfa before Beta), '
            . 'puntaje 4 (Charlie before Delta), then puntaje 2.5 (Echo) — no unrated candidate present at all.'
        );
    }

    public function test_buscar_paginado_orders_same_puntaje_candidates_by_name(): void {
        $plazaId = $this->plaza( 10 );
        global $wpdb;
        $p = $wpdb->prefix;

        $this->seedPlayer( 900, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '3' ] );
        $this->seedPlayer( 901, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '3' ] );
        $wpdb->update( "{$p}posts", [ 'post_title' => 'Zapata' ], [ 'ID' => 900 ] );
        $wpdb->update( "{$p}posts", [ 'post_title' => 'Alvarez' ], [ 'ID' => 901 ] );

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $resultado = $this->resolver->buscarPaginado(
            $plaza,
            null,
            null,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            1,
            10
        );

        $this->assertSame(
            [ 901, 900 ],
            array_map( static fn ( $c ) => $c->playerId(), $resultado['candidatos'] ),
            'Candidates tied on puntaje must break the tie alphabetically: Alvarez before Zapata.'
        );
    }

    public function test_buscar_paginado_orders_same_puntaje_and_name_candidates_by_player_id(): void {
        $plazaId = $this->plaza( 10 );
        global $wpdb;
        $p = $wpdb->prefix;

        $this->seedPlayer( 955, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '3' ] );
        $this->seedPlayer( 950, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '3' ] );
        $wpdb->update( "{$p}posts", [ 'post_title' => 'Gomez' ], [ 'ID' => 955 ] );
        $wpdb->update( "{$p}posts", [ 'post_title' => 'Gomez' ], [ 'ID' => 950 ] );

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $resultado = $this->resolver->buscarPaginado(
            $plaza,
            null,
            null,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            1,
            10
        );

        $this->assertSame(
            [ 950, 955 ],
            array_map( static fn ( $c ) => $c->playerId(), $resultado['candidatos'] ),
            'Candidates tied on BOTH puntaje and name must still break the tie deterministically, '
            . 'by player_id ascending — never left to an unstable PHP sort.'
        );
    }

    /**
     * A naive byte-wise comparison of raw UTF-8 puts every accented letter
     * AFTER every unaccented one (an accented character's continuation bytes
     * are numerically greater than ASCII `z`) — this pins that
     * `claveOrdenNombre()`'s accent fold avoids exactly that, for three real
     * surnames from this padrón's own population (see
     * `Puntaje`/`JugadorMetricasReader`'s surrounding docblocks for other
     * production data this plugin verifies against real names).
     */
    public function test_buscar_paginado_sorts_accented_spanish_names_as_expected(): void {
        $plazaId = $this->plaza( 10 );
        global $wpdb;
        $p = $wpdb->prefix;

        // 710..712 — NOT 700..702: player 700 is THIS fixture's own titular
        // (see plaza()'s own `openPlaza( ..., 700, ... )` call), so it is
        // always excluded as the plaza's incumbent, never a candidate.
        $this->seedPlayer( 710, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '3' ] );
        $this->seedPlayer( 711, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '3' ] );
        $this->seedPlayer( 712, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '3' ] );

        $wpdb->update( "{$p}posts", [ 'post_title' => 'Rodríguez' ], [ 'ID' => 710 ] );
        $wpdb->update( "{$p}posts", [ 'post_title' => 'Pérez' ], [ 'ID' => 711 ] );
        $wpdb->update( "{$p}posts", [ 'post_title' => 'Gómez' ], [ 'ID' => 712 ] );

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $resultado = $this->resolver->buscarPaginado(
            $plaza,
            null,
            null,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            1,
            10
        );

        $this->assertSame(
            [ 712, 711, 710 ],
            array_map( static fn ( $c ) => $c->playerId(), $resultado['candidatos'] ),
            'Gómez, Pérez, Rodríguez must sort as a Spanish reader expects (accent-folded '
            . 'alphabetical: Gómez, Pérez, Rodríguez), never pushed after unaccented names by raw byte order.'
        );
    }

    /**
     * UPDATED from #144's original behavior (where an unrated candidate
     * sorted last via a dedicated `-1` sentinel in `ordenarCandidatos()`): a
     * player with no resolvable puntaje is no longer a candidate at all —
     * there is nowhere for them to "sort" because they never enter the
     * population in the first place (see class docblock, "AN UNRESOLVABLE
     * PUNTAJE IS NOT A CANDIDATE AT ALL — IN buscarPaginado() ONLY"). This
     * asserts the unrated candidate is ABSENT and `total` reflects only the
     * rated one, never that they appear last.
     */
    public function test_buscar_paginado_excludes_candidates_with_no_puntaje_instead_of_sorting_them_last(): void {
        $plazaId = $this->plaza( 10 );

        $this->seedPlayer( 600, self::SEASON_ID, [ 'caracter' => 'Invitado' ] ); // sin puntaje
        $this->seedPlayer( 601, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '1' ] ); // el mas bajo valido

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $resultado = $this->resolver->buscarPaginado(
            $plaza,
            null,
            null,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            1,
            10
        );

        $this->assertSame( 1, $resultado['total'], 'total must count only the rated candidate.' );
        $this->assertSame(
            [ 601 ],
            array_map( static fn ( $c ) => $c->playerId(), $resultado['candidatos'] ),
            'A candidate with no resolvable puntaje must be absent entirely — never present and sorted last.'
        );
    }

    /**
     * THE EXACT production failure shape, reproduced at the resolver level
     * (where `Rest\PlazasController::listarCandidatos()`'s own top-level
     * `\Throwable` catch would otherwise have turned this into a 500 — see
     * that controller's own `rest.plazas_candidatos_fallida` event log and
     * this slice's task description for the real incident):
     * `?seccion=padron_completo` resolves its WHOLE population's metrics in
     * ONE batched `JugadorMetricasReader::resolveMuchos()` call BEFORE
     * pagination (see `buscarPaginado()`'s own docblock, "WHY PAGINATION
     * HAPPENS HERE, BEFORE THE N+1, NOT AFTER") — a single player anywhere in
     * that population with a stored puntaje of "0" used to throw
     * \InvalidArgumentException out of THAT call, which `buscarPaginado()`
     * never individually catches, aborting the response for every other
     * candidate on the page too. This test seeds that population — one
     * puntaje-zero player alongside two otherwise-viable candidates — and
     * asserts the whole page still resolves with the other candidates
     * intact, exactly like the fix's task brief requires ("the request still
     * succeeds").
     *
     * UPDATED for the "exclude unrated candidates" change: the puntaje-zero
     * player is no longer merely non-viable (`puntaje_indeterminado`) — it is
     * absent from the result and from `total` entirely (see class docblock,
     * "AN UNRESOLVABLE PUNTAJE IS NOT A CANDIDATE AT ALL — IN
     * buscarPaginado() ONLY"). The original 500-regression this test exists
     * for is still exercised: `resolveMuchos()` still runs over the whole
     * population including the puntaje-zero row, so a regression there would
     * still surface here as a thrown exception, not a passing test.
     */
    public function test_buscar_paginado_does_not_500_when_one_padron_completo_candidate_has_a_stored_puntaje_of_zero(): void {
        $plazaId = $this->plaza( 10 ); // techo 5.0 — nobody excluded by techo here

        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '0' ] );
        $this->seedPlayer( 801, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '2,5' ] );
        $this->seedPlayer( 802, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '3' ] );

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $resultado = $this->resolver->buscarPaginado(
            $plaza,
            CandidatosSeccion::PADRON_COMPLETO,
            self::LISTA_ESPERA_TEAM_ID,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            1,
            10
        );

        $this->assertSame( 2, $resultado['total'], 'total must count only the 2 rated candidates.' );

        $porId = [];
        foreach ( $resultado['candidatos'] as $c ) {
            $porId[ $c->playerId() ] = $c;
        }

        $this->assertArrayNotHasKey( 800, $porId, 'The puntaje-zero ("sin calificar") candidate must be absent entirely, not merely non-viable.' );
        $this->assertTrue( $porId[801]->viable() );
        $this->assertTrue( $porId[802]->viable() );
    }

    // -------------------------------------------------------------------------
    // buscarPaginado() — unrated candidates are excluded, not just non-viable
    // -------------------------------------------------------------------------

    /**
     * THE user-facing rule this slice's task brief states directly: without
     * a puntaje there is nothing to evaluate against the plaza's techo, so
     * such a player is not a candidate at all — in NEITHER screen section.
     * Covers the default (season-registered), `lista_espera`, AND
     * `padron_completo` populations in one test, since all three go through
     * the SAME exclusion filter in `buscarPaginado()` (see class docblock).
     */
    public function test_buscar_paginado_excludes_unrated_candidates_from_every_seccion(): void {
        $plazaId = $this->plaza( 10 ); // techo 5.0 — nobody excluded by techo here

        // Default (season-registered) population.
        $this->seedPlayer( 800, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '3' ] ); // rated
        $this->seedPlayer( 801, self::SEASON_ID, [ 'caracter' => 'Invitado' ] ); // unrated

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $resultadoDefault = $this->resolver->buscarPaginado(
            $plaza,
            null,
            null,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            1,
            10
        );

        $this->assertSame( 1, $resultadoDefault['total'] );
        $this->assertSame( [ 800 ], array_map( static fn ( $c ) => $c->playerId(), $resultadoDefault['candidatos'] ) );

        // lista_espera population.
        $this->seedPlayer( 820, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '3' ] ); // rated
        $this->seedEquipoMembership( 820, self::LISTA_ESPERA_TEAM_ID );
        $this->seedPlayer( 821, self::SEASON_ID, [ 'caracter' => 'Invitado' ] ); // unrated
        $this->seedEquipoMembership( 821, self::LISTA_ESPERA_TEAM_ID );

        $resultadoListaEspera = $this->resolver->buscarPaginado(
            $plaza,
            CandidatosSeccion::LISTA_ESPERA,
            self::LISTA_ESPERA_TEAM_ID,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            1,
            10
        );

        $this->assertSame( 1, $resultadoListaEspera['total'] );
        $this->assertSame( [ 820 ], array_map( static fn ( $c ) => $c->playerId(), $resultadoListaEspera['candidatos'] ) );

        // padron_completo population.
        $this->seedPlayerSinTemporada( 900, [ 'caracter' => 'Invitado', 'puntaje' => '3' ] ); // rated
        $this->seedPlayerSinTemporada( 901, [ 'caracter' => 'Invitado' ] ); // unrated

        $resultadoPadronCompleto = $this->resolver->buscarPaginado(
            $plaza,
            CandidatosSeccion::PADRON_COMPLETO,
            self::LISTA_ESPERA_TEAM_ID,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            1,
            10
        );

        $ids = array_map( static fn ( $c ) => $c->playerId(), $resultadoPadronCompleto['candidatos'] );
        $this->assertContains( 900, $ids );
        $this->assertNotContains( 901, $ids );
        $this->assertNotContains( 801, $ids, 'An unrated season-registered player must stay excluded from padron_completo too.' );
    }

    /**
     * THE regression this slice's task brief calls out explicitly: a stored
     * puntaje that is neither empty, nor "0", but genuinely malformed (not
     * one of the 9 valid puntajes) must still record `metrics.puntaje_invalido`
     * via `JugadorMetricasReader::extractPuntaje()` — see that class's own
     * class docblock, "AN OTHERWISE-INVALID STORED VALUE DEGRADES TO NULL
     * TOO, BUT IS LOGGED" — even though the candidate is now excluded from
     * `buscarPaginado()`'s population entirely rather than merely marked
     * non-viable. The exclusion filter must never swallow that signal.
     */
    public function test_buscar_paginado_still_logs_puntaje_invalido_for_an_excluded_malformed_candidate(): void {
        $plazaId = $this->plaza( 10 );

        $this->seedPlayer( 850, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => 'no-es-un-numero' ] );
        $this->seedPlayer( 851, self::SEASON_ID, [ 'caracter' => 'Invitado', 'puntaje' => '3' ] );

        $plaza = $this->plazaRepository->findPlaza( $plazaId );

        $resultado = $this->resolver->buscarPaginado(
            $plaza,
            null,
            null,
            BloqueoReemplazoPolicy::topeTresFechas(),
            $this->countResolvedFechasSinceFn,
            1,
            10
        );

        $this->assertSame( 1, $resultado['total'], 'The malformed-puntaje candidate must be excluded from total, same as a genuinely unrated one.' );
        $this->assertSame( [ 851 ], array_map( static fn ( $c ) => $c->playerId(), $resultado['candidatos'] ) );

        $this->assertTrue(
            $this->eventLog->has( 'metrics.puntaje_invalido' ),
            'A malformed stored puntaje must still be logged, even though the candidate is now excluded rather than just non-viable.'
        );
        $this->assertSame( 850, $this->eventLog->last()['contexto']['player_id'] ?? null );
    }
}

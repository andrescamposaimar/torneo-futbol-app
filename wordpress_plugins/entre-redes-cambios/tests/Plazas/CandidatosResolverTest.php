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

    /** Creates the `sp_team` taxonomy term itself — call ONCE per team id. */
    private function seedEquipoTaxonomyTerm( int $teamId ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'term_taxonomy', [ 'term_taxonomy_id' => $teamId, 'term_id' => $teamId, 'taxonomy' => 'sp_team' ] );
    }

    private function seedEquipoMembership( int $playerId, int $teamId ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'term_relationships', [ 'object_id' => $playerId, 'term_taxonomy_id' => $teamId ] );
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

        $this->seedEquipoTaxonomyTerm( self::LISTA_ESPERA_TEAM_ID );
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

        $this->seedEquipoTaxonomyTerm( self::LISTA_ESPERA_TEAM_ID );
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

        $this->seedEquipoTaxonomyTerm( self::LISTA_ESPERA_TEAM_ID );
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

        $this->seedEquipoTaxonomyTerm( self::LISTA_ESPERA_TEAM_ID );
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
}

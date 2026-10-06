<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Migrations;

/**
 * Creates (or upgrades) all 7 cambios_ tables.
 *
 * Uses dbDelta() for idempotent CREATE TABLE; safe to re-run on every plugin
 * upgrade — dbDelta only alters schema when columns differ.
 *
 * Important dbDelta formatting rules (mirrored from entre-redes-prode):
 *   - Each column definition on its own line.
 *   - Two spaces between the column name and its definition.
 *   - PRIMARY KEY must use the exact phrase "PRIMARY KEY".
 *   - No trailing commas on the last column before the closing KEY block.
 *
 * IMPORTANT — test harness constraint (see tests/wp-shim.php, copied literally
 * from entre-redes-prode): the SQLite DDL translator DROPS every UNIQUE KEY /
 * KEY / INDEX line. No uniqueness constraint declared below is enforced by the
 * PHPUnit in-memory DB. Every uniqueness invariant this schema relies on
 * (season+orden, match_id globally unique, fecha+match) is therefore defended
 * in application code via a SELECT-then-insert guard in
 * Calendario\FechaRepository, exactly like entre-redes-prode's FechaRepository.
 * The same translator also rewrites every `ENUM(...)` column (e.g. `estado`,
 * `estado_origen`) to `TEXT` — so a typo in an ENUM-backed value (like an
 * invalid `estado`) is silently accepted in tests too, never just in
 * non-strict MySQL. `Calendario\FechaRepository::setEstadoManual()` is what
 * actually guards `estado` against that, in code, for the same reason.
 *
 * NOTE ON IDENTITY: there is deliberately NO `UNIQUE KEY (season_id,
 * play_date)` — see Calendario\FechaRepository's class docblock, IDENTITY
 * MODEL, for why (play_date is mutable; a fecha's identity is its match_ids).
 */
class InitialSchema {

    /**
     * Values seeded into cambios_settings on activation.
     *
     * `season_id` and `timezone` are simple scalars. The four `plazo_*` keys
     * are JSON-encoded {"days":int,"time":"H:i:s"} pairs — the exact shape
     * Calendario\PlazosCalculator::compute() expects per plazo — so
     * Calendario\Settings can decode them directly without a second mapping
     * layer. Kept as a constant so Settings' fallback defaults can be asserted
     * against these seeds (see VersionConsistencyTest-style drift protection):
     * an unseeded setting must fall back to the SAME value the DB would have
     * held, or an operator who wipes cambios_settings silently gets different
     * behavior than a fresh install.
     *
     * @var array<string, string>
     */
    public const SEED_DEFAULTS = [
        'timezone'                   => 'America/Argentina/Buenos_Aires',
        'season_id'                  => '359',
        'plazo_apertura_solicitudes' => '{"days":-6,"time":"00:00:00"}',
        'plazo_cierre_regresos'      => '{"days":-4,"time":"23:59:59"}',
        'plazo_cierre_solicitudes'   => '{"days":-2,"time":"23:59:59"}',
        'plazo_publicacion'          => '{"days":-1,"time":"00:00:00"}',
        // OFF by default — the reglamento's "prioridad para padres" is today a
        // SOFT rule nobody enforces (see Dictamen\Reglas\PrioridadDePadresRespetada's
        // own docblock). Flipping this to '1' is the one, explicit way to turn
        // it into an actual gate; it must never silently become active on its
        // own just because the setting row is unseeded.
        'prioridad_padres_activa'    => '0',
        // `0` means "not configured" — NEVER a real `sp_team` post id, see
        // Calendario\Settings::listaEsperaTeamIdOverride()'s own docblock.
        // Unlike every other default above, this one is NOT the production
        // fallback value by itself: Plazas\ListaEsperaResolver falls back to
        // a DYNAMIC lookup (the `sp_team` whose slug is `lista-de-espera-{year}`
        // for the configured season) when this row is `0`, rather than to a
        // second hardcoded literal here — a specific team id would go stale
        // the first season it is not updated by hand. Set this to a real
        // `sp_team` post id only to OVERRIDE that dynamic lookup (e.g. the
        // slug convention breaks, or a season needs a different team).
        'lista_espera_team_id'       => '0',
    ];

    /**
     * Run all CREATE TABLE statements.
     *
     * @return string[] Array of dbDelta result messages (for logging).
     */
    public static function up(): array {
        global $wpdb;

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charset_collate = $wpdb->get_charset_collate();
        $p               = $wpdb->prefix;

        $sqls = [
            self::sqlCambiosFecha( $p, $charset_collate ),
            self::sqlCambiosFechaPartido( $p, $charset_collate ),
            self::sqlCambiosSettings( $p, $charset_collate ),
            self::sqlCambiosCapitan( $p, $charset_collate ),
            self::sqlCambiosPlaza( $p, $charset_collate ),
            self::sqlCambiosOcupacion( $p, $charset_collate ),
            self::sqlCambiosSolicitud( $p, $charset_collate ),
            self::sqlCambiosDecision( $p, $charset_collate ),
        ];

        $results = [];
        foreach ( $sqls as $sql ) {
            $results = array_merge( $results, dbDelta( $sql ) );
        }

        self::seedSettings( $p );

        return $results;
    }

    // -------------------------------------------------------------------------
    // Table definitions
    // -------------------------------------------------------------------------

    /**
     * cambios_fecha — one row per jornada of the ENTIRE season (Apertura +
     * Clausura together, and Clasificacion when the season carries one).
     * There is no `zona` column on purpose: a jornada is never suspended for
     * a single zone, so the suspension/state lifecycle is tracked once per
     * jornada, not three times.
     *
     * IDENTITY and `orden`: see Calendario\FechaRepository's class docblock
     * (IDENTITY MODEL, INVARIANT) and README.md's "orden vs numero_en_torneo"
     * section — both authoritative. In short: identity lives in `fecha_id`,
     * never in `orden` or `play_date`; `orden` is recomputed from scratch on
     * every seed run and is the only column valid for arithmetic.
     *
     * `numero_en_torneo` is what a human reads ("Fecha 3 del Apertura") and
     * resets to 1 whenever `torneo_label` changes — NEVER use it for date
     * maths. Valid `torneo_label` values: 'Clasificacion' | 'Apertura' |
     * 'Clausura'.
     *
     * `play_date_original` is set once, at creation, and is NEVER updated
     * again — it records the day the jornada was first scheduled for.
     * `veces_postergada` counts how many times `play_date` has since been
     * pushed forward (i.e. the jornada was suspended and moved to a later
     * day). Both exist purely to make a postponement visible after the fact:
     * nothing in SportsPress or in this plugin records "this jornada moved",
     * the sp_event dates just silently change.
     */
    private static function sqlCambiosFecha( string $p, string $charset ): string {
        return "CREATE TABLE {$p}cambios_fecha (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  season_id BIGINT UNSIGNED NOT NULL,
  orden SMALLINT UNSIGNED NOT NULL,
  torneo_liga_ids VARCHAR(64) NOT NULL,
  torneo_label VARCHAR(32) NOT NULL,
  numero_en_torneo SMALLINT UNSIGNED NOT NULL,
  play_date DATE NOT NULL,
  play_date_original DATE NOT NULL,
  veces_postergada SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  estado ENUM('programada','jugada','dirimida','suspendida') NOT NULL DEFAULT 'programada',
  estado_origen ENUM('derivado','manual') NOT NULL DEFAULT 'derivado',
  estado_actualizado_at DATETIME NULL DEFAULT NULL,
  estado_actualizado_por BIGINT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_season_orden (season_id, orden),
  KEY idx_estado (estado)
) ENGINE=InnoDB $charset;";
    }

    /**
     * cambios_fecha_partido — bridge to sp_event match posts. `zona` is
     * denormalized here (not just liga_id) because the human-facing label
     * ("Zona A") is cheaper to snapshot at seed time than to re-resolve later
     * from SportsPress term relationships, mirroring prode_fecha_matches'
     * snapshot strategy.
     *
     * `uq_match (match_id)` encodes the identity rule: a partido (a
     * WordPress sp_event post, globally unique by match_id) belongs to
     * EXACTLY ONE fecha, ever. `uq_fecha_match` is kept alongside it as a
     * cheap defensive constraint, but `uq_match` is the one that matters —
     * it is what makes "look up the existing fecha by its incoming
     * match_ids" a safe, unambiguous operation in
     * Calendario\FechaRepository::upsertFecha().
     */
    private static function sqlCambiosFechaPartido( string $p, string $charset ): string {
        return "CREATE TABLE {$p}cambios_fecha_partido (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha_id BIGINT UNSIGNED NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  liga_id BIGINT UNSIGNED NOT NULL,
  zona VARCHAR(255) NOT NULL DEFAULT '',
  kickoff DATETIME NOT NULL,
  tiene_resultado TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_fecha_match (fecha_id, match_id),
  UNIQUE KEY uq_match (match_id),
  KEY idx_match (match_id)
) ENGINE=InnoDB $charset;";
    }

    /**
     * cambios_settings — operator-configurable parameters. Same key/value
     * shape as entre-redes-prode's prode_settings table.
     */
    private static function sqlCambiosSettings( string $p, string $charset ): string {
        return "CREATE TABLE {$p}cambios_settings (
  setting_key VARCHAR(64) NOT NULL,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (setting_key)
) ENGINE=InnoDB $charset;";
    }

    /**
     * cambios_capitan — one row per captaincy DESIGNATION, not per team.
     * `revocado_at IS NULL` marks the currently-vigent row for a
     * `(season_id, team_id)` pair; every prior designation for that pair is
     * left in place with `revocado_at` set, so the table doubles as the
     * captaincy's audit history.
     *
     * `player_id` is the `sp_player` post id — the same value the prode JWT
     * carries as its `player_id` claim (see Auth\TokenVerifier), which is
     * exactly what makes `Capitania\CapitanAuthorizer` able to compare a
     * token's claim against this table directly, with no extra lookup.
     *
     * *** WHY THIS IS NOT A UNIQUE KEY ***
     * The business rule is "at most one VIGENT captain per (season_id,
     * team_id)" — but that is not expressible as `UNIQUE (season_id,
     * team_id)`, because the table is also the history: a revoked row for the
     * same pair must be allowed to coexist with the new vigent one. Scoping
     * the unique key to `revocado_at` (e.g. `UNIQUE (season_id, team_id,
     * revocado_at)`) does not work either — MySQL treats every NULL in a
     * UNIQUE index as distinct from every other NULL, so it would allow
     * multiple simultaneously-vigent rows for the same team, defeating the
     * whole point. The invariant is therefore defended the same way
     * Calendario\FechaRepository defends `uq_season_orden`: a SELECT-then-
     * insert guard in Capitania\CapitanRepository::designateCapitan() (find and
     * revoke the current vigent row, in a transaction, before inserting the
     * new one), verified by a test that asserts the PROPERTY ("never two
     * vigent rows for the same pair"), not a constraint. And — same as every
     * other table in this file — the SQLite test shim drops every KEY/INDEX
     * line below, so `idx_season_team` and `idx_player` are query-plan
     * optimizations only; nothing about the uniqueness invariant depends on
     * them being enforced by the test DB.
     */
    private static function sqlCambiosCapitan( string $p, string $charset ): string {
        return "CREATE TABLE {$p}cambios_capitan (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  season_id BIGINT UNSIGNED NOT NULL,
  team_id BIGINT UNSIGNED NOT NULL,
  player_id BIGINT UNSIGNED NOT NULL,
  designado_por BIGINT UNSIGNED NULL DEFAULT NULL,
  designado_at DATETIME NOT NULL,
  revocado_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_season_team (season_id, team_id),
  KEY idx_player (player_id)
) ENGINE=InnoDB $charset;";
    }

    /**
     * cambios_plaza — one row per PLAZA of a team's roster (11 per team, by
     * reglamento). Every plaza is a TITULAR plaza: there is no `tipo` column
     * splitting the 11 into "campo" and "suplente" plazas, because the
     * reglamento does not make that distinction either — 9 players on the
     * pitch and 2 on the bench describes an INSTANT during a match (which
     * rotates constantly via unlimited in-match substitutions), never a
     * fixed property of a plaza. An earlier version of this schema did carry
     * such a column; it was removed directly from this CREATE TABLE
     * statement, never as a separate versioned migration, because
     * `cambios_plaza` was still EMPTY in production at the time — there was
     * no row to migrate. This is safe ONLY because of that emptiness: `dbDelta()`
     * (see `Migrations\MigrationRunner`) adds and widens columns on an
     * upgrade, but it does NOT drop one — an install where this table had
     * already been created with the old column would keep it as a harmless,
     * unused leftover until manually dropped. A future column removal
     * against a table that already holds data needs an explicit migration
     * step in `MigrationRunner`, never a silent edit here. The plaza, NOT
     * the solicitud, is the aggregate slice 2 models — see
     * Plazas\CadenaResolver's class docblock for the full reasoning.
     *
     * `titular_player_id` is the PERMANENT owner: by reglamento a titular
     * never changes team, and this column is never reassigned after
     * `openPlaza()` creates the plaza — a return of the titular inserts a new
     * `cambios_ocupacion` row for this same player_id, it never rewrites this
     * column.
     *
     * *** `es_arco` — ADDED IN 0.1.13, A STORED FACT, NEVER A DERIVATION ***
     * The process owner stated the invariant plainly: every team has exactly
     * one goalkeeper, therefore exactly one of its 11 plazas IS the
     * goalkeeper's plaza — a team without that plaza cannot exist. Before
     * this column existed, "is this the goalkeeper's plaza" was answered by
     * re-deriving it on every read from the titular's CURRENT `sp_position`
     * (`Plazas\PosicionResolver::esPosicionDelArqueroTitular()`, term 3
     * only — see that method's own docblock for why term 125, "Arquero
     * Sup.", is deliberately excluded). That worked only as long as nobody
     * ever touched a player's position in WordPress: fixing a data-entry
     * error on a titular's `sp_position` would silently strip the team of
     * its goal plaza, and `Dictamen\Reglas\ArqueroNoOcupaPlazaDeCampo`
     * (0.1.12) would then block EVERY candidate from entering EVERY one of
     * that team's 11 plazas — including the one that is still, in reality,
     * the goal — with nothing anywhere explaining why.
     *
     * `es_arco` is therefore WRITTEN ONCE and read as a plain fact from then
     * on:
     *   - `Plazas\PlazaRepository::doOpenPlaza()` derives it from the
     *     titular's position AT THE MOMENT the plaza is opened (via
     *     `PosicionResolver::esPosicionDelArqueroTitular()`) and persists it
     *     — every NEW plaza (any future season's `Plazas\Alta\TitularesListImporter`
     *     run) gets a correct value from birth, with no separate backfill
     *     step required.
     *   - `Migrations\MigrationRunner::backfillEsArco()` derives it, once,
     *     for the 330 plazas that existed in production BEFORE this column
     *     did — see that method's own docblock.
     *   - `Dictamen\DictamenContextAssembler` and
     *     `Plazas\CandidatosResolver::buscarPaginado()` (both 0.1.12) now
     *     read THIS column directly instead of re-deriving it — a titular's
     *     `sp_position` changing later in WordPress no longer moves "which
     *     plaza is the goal" out from under either reader.
     *
     * The invariant this column backs — exactly one `es_arco = 1` per
     * `(season_id, team_id)` — is checked by
     * `Migrations\MigrationRunner::checkEsArcoInvariant()` (loud, an
     * EventLog event plus an `admin_notice`, same discipline as that class's
     * own `checkStorageEngine()`) and enforced PREVENTIVELY at roster-creation
     * time by `Plazas\Alta\TitularesListImporter::planificar()`, which refuses
     * to import a team whose 11 titulares include zero or more than one
     * goalkeeper.
     *
     * `puntaje_techo` is the ceiling SNAPSHOTTED at conformación (March) —
     * see Plazas\Puntaje for the ×2 integer encoding. It NEVER moves for the
     * lifetime of the plaza, no matter who occupies it later; only the
     * ocupación changes, never the plaza's ceiling.
     *
     * `closed_at` exists for a plaza that stops existing entirely (e.g. the
     * team is dissolved) — it is NOT how an ocupación record ends; that is
     * `cambios_ocupacion.fecha_hasta_id` (see that table's docblock). Nothing
     * in this slice ever sets it — no closure workflow is in scope here — but
     * the column exists so a later slice does not need a schema change to add
     * one.
     */
    private static function sqlCambiosPlaza( string $p, string $charset ): string {
        return "CREATE TABLE {$p}cambios_plaza (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  season_id BIGINT UNSIGNED NOT NULL,
  team_id BIGINT UNSIGNED NOT NULL,
  titular_player_id BIGINT UNSIGNED NOT NULL,
  es_arco TINYINT(1) NOT NULL DEFAULT 0,
  puntaje_techo SMALLINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  closed_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_season_team (season_id, team_id),
  KEY idx_titular (titular_player_id)
) ENGINE=InnoDB $charset;";
    }

    /**
     * cambios_ocupacion — one row per LINK in a plaza's chain of successive
     * occupations (titular → suplente → suplente → ... → titular again, and
     * so on). See Plazas\CadenaResolver's class docblock for how this chain
     * is read; this docblock only covers the columns.
     *
     * `es_genesis` marks the very first link of every chain — the plaza's
     * founding ocupación, inserted by `PlazaRepository::openPlaza()` in the
     * same transaction as the plaza itself. It is `1` on exactly one row per
     * plaza, ever, and `0` on every other link, including a later link opened
     * by `closeOcupacionByRegresoTitular()` when the titular returns — that
     * link is of the titular's player_id but is NOT the genesis row, so it is
     * NOT flagged again.
     *
     * THIS COLUMN DOES NOT ANSWER "WHO IS THE TITULAR" — it was previously
     * named `es_titular`, which claimed exactly that and was wrong: after any
     * regreso del titular, the returning titular's row is inserted with this
     * flag `0` (see previous paragraph), so `WHERE es_titular = 1` silently
     * returned zero rows for a plaza that HAD had a substitution. The real,
     * current source of truth for "who is the titular of this plaza" is
     * `cambios_plaza.titular_player_id`, which never changes for the
     * lifetime of the plaza — read that column, never this one, to find or
     * compare against the titular.
     *
     * `fecha_desde_id` / `fecha_hasta_id` are `cambios_fecha.id` values — a
     * LOGICAL FK, never `orden` (see Calendario\FechaRepository's class
     * docblock INVARIANT section for why `orden` must never be persisted as
     * an identity reference).
     *
     * THE INTERVAL IS HALF-OPEN: [fecha_desde_id, fecha_hasta_id). An
     * ocupación covers the fecha it starts on and every fecha up to, but NOT
     * including, the one recorded in `fecha_hasta_id` — which is exactly the
     * fecha its successor starts on. So the fechas an ocupación actually
     * covered are counted as "resolved fechas from fecha_desde_id, exclusive
     * of fecha_hasta_id", never as `hasta - desde + 1`.
     *
     * This matters more here than it looks. The whole feature is a counting
     * rule — three resolved fechas before a titular may return — so an
     * off-by-one in how an interval is read is an off-by-one in the business
     * answer. Half-open was chosen because the closed alternative ("the last
     * fecha actually occupied") would require looking up the fecha BEFORE the
     * successor's, and the calendar has gaps: weekends with no fecha at all.
     * That lookup is not a subtraction, and every caller would have to get it
     * right. Here the successor's start IS the predecessor's end, with
     * nothing in between to resolve. `fecha_hasta_id IS NULL` marks the currently
     * VIGENT ocupación — see PlazaRepository's class docblock for the
     * invariant that at most one such row exists per plaza, defended in code
     * exactly like `cambios_capitan`'s vigent-row invariant, for the same
     * reason (the SQLite test shim drops every KEY/UNIQUE KEY, so nothing
     * here is enforced by a DB constraint).
     *
     * `cerrada_por` records WHY a link closed: `regreso_titular` (the titular
     * came back), `reemplazada` (a new occupant took over while this one was
     * still within its rights), or `trunca` (this occupant left before
     * meeting the 3-fecha minimum, and is therefore blocked from re-entering
     * until the plaza's single, derived liberation moment — see
     * Plazas\CadenaResolver). `NULL` on the vigent row: it has not closed for
     * any reason yet.
     */
    private static function sqlCambiosOcupacion( string $p, string $charset ): string {
        return "CREATE TABLE {$p}cambios_ocupacion (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  plaza_id BIGINT UNSIGNED NOT NULL,
  player_id BIGINT UNSIGNED NOT NULL,
  es_genesis TINYINT(1) NOT NULL DEFAULT 0,
  fecha_desde_id BIGINT UNSIGNED NOT NULL,
  fecha_hasta_id BIGINT UNSIGNED NULL DEFAULT NULL,
  cerrada_por ENUM('regreso_titular','reemplazada','trunca') NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_plaza (plaza_id),
  KEY idx_player (player_id)
) ENGINE=InnoDB $charset;";
    }

    /**
     * cambios_solicitud — one row per SOLICITUD DE CAMBIO, from the moment a
     * captain submits it through its whole lifecycle
     * (`Solicitudes\EstadoSolicitud`: pendiente → aprobada/rechazada/anulada
     * → publicada). See `Solicitudes\SolicitudRepository`'s class docblock
     * for the full model this table backs; this docblock only covers the
     * columns.
     *
     * `tipo` / `entrante_player_id` / `plaza_id` / `fecha_id` mirror
     * `Dictamen\SolicitudDeCambio`'s own shape exactly — `entrante_player_id`
     * is NULL for a `regreso`, same reasoning as that class's docblock
     * (who returns is never a choice this request makes).
     *
     * `solicitud_instante_epoch` is a Unix epoch, deliberately NOT derived
     * from `solicitada_at` later — same reasoning as
     * `Dictamen\SolicitudDeCambio::instanteEpoch()`'s own docblock: an epoch
     * has no timezone to misread, while re-deriving it from a civil
     * `DATETIME` string would force a guess about which timezone that string
     * was written in. `SolicitudRepository::publicarLote()` reconstructs a
     * `Dictamen\SolicitudDeCambio` from a stored row to re-run the dictamen
     * pipeline before applying the change (see that method's docblock,
     * "RE-EVALUATING BEFORE APPLYING") — it reads THIS column for that
     * object's `instanteEpoch`, never `solicitada_at`.
     *
     * `dictamen_original` / `dictamen_aplicado` are JSON-encoded
     * `Dictamen\DictamenSnapshot`s — the former frozen the moment `crear()`
     * persists the solicitud, the latter written only by `publicarLote()`,
     * once, right before applying the change for real. Both exist because a
     * dictamen is a snapshot of facts that can go stale between Wednesday
     * (when a captain requests a change) and Friday (when the lote is
     * published) — see `SolicitudRepository`'s class docblock. `NULL` on
     * `dictamen_aplicado` means exactly "never published", not "no
     * dictamen" — a solicitud that is `rechazada` or `anulada` keeps this
     * column NULL forever.
     *
     * `estado` is one of `Solicitudes\EstadoSolicitud::todos()` — declared as
     * an `ENUM` here purely for readability in a real MySQL schema; nothing
     * about the TRANSITION graph between these values could be expressed by
     * an `ENUM` anyway, so `EstadoSolicitud` defends it in code regardless of
     * what the column type allows (see that class's own docblock, and
     * `Calendario\FechaRepository::VALID_ESTADOS`'s docblock for the same
     * ENUM-is-not-a-guard rationale repeated once more here).
     *
     * `resuelta_por` / `resuelta_at` / `nota` are a READ CONVENIENCE ONLY —
     * "what was the most recent decision on this solicitud" — kept because a
     * caller that only needs the latest decision (e.g. a captain checking
     * their own request) should not have to join `cambios_decision` for it.
     * They are NOT the historical record: every individual decision
     * (`aprobar()`, `rechazar()`, `anular()`, `publicarLote()`) additionally
     * appends its own row to `cambios_decision` (see that table's docblock),
     * which is the only source of truth for "who decided what, and when" —
     * these three columns get overwritten on every new decision, exactly
     * like `cambios_solicitud.estado` itself does, so they can never answer
     * "what did the FIRST decision say" once a second one has been made.
     *
     * `ocupacion_id` is the `cambios_ocupacion` row `publicarLote()` created
     * (or, for a `regreso`, closed) for THIS solicitud — written only at the
     * moment it publishes, alongside `dictamen_aplicado`, never before. It
     * exists so undoing a wrongly-published lote never again requires
     * cross-referencing the EventLog by `plaza_id` and timestamp by hand —
     * see `Solicitudes\SolicitudRepository::marcarPublicadaWithinTransaction()`'s
     * docblock. `NULL` means exactly what `dictamen_aplicado`'s `NULL`
     * means: "never published".
     *
     * *** `saliente_player_id` — ADDED IN 0.1.11, WHY IT IS A WRITE-TIME
     * SNAPSHOT, NEVER A READ-TIME DERIVATION ***
     * The captain-facing "Mis Solicitudes" screen needs to show WHO LEAVES a
     * plaza, not just who comes in — but "who currently occupies this plaza"
     * is not a fact fixed in time: `cambios_ocupacion`'s chain for a plaza
     * keeps advancing (a `publicarLote()` run, a later `regreso`), so
     * re-deriving "the saliente" from the CURRENT vigent ocupación at READ
     * time would make an old solicitud silently relabel itself with whoever
     * happens to occupy the plaza TODAY — stating something false about the
     * past. `Solicitudes\SolicitudRepository::crear()` therefore reads
     * `Plazas\PlazaRepository::findOcupacionVigente()` ONCE, at the exact
     * moment the solicitud is created, and freezes that player_id here —
     * same discipline as `dictamen_original` (a snapshot of a fact that can
     * go stale), applied to "who this request would replace" instead. This
     * is the vigent occupant AT REQUEST TIME for BOTH `tipo` values: for a
     * `sustitucion` that occupant is exactly who the `entrante_player_id`
     * would replace; for a `regreso` it is the suplente currently holding
     * the plaza the titular is asking to reclaim (see
     * `Rest\SolicitudesController::listar()`'s own docblock for how each
     * `tipo` derives its "entra" side from this same moment).
     *
     * `NULL` here means "not recorded" — either the plaza genuinely had no
     * vigent ocupación at the instant of `crear()` (should not happen once
     * `PlazaRepository::openPlaza()` has run, same invariant
     * `findOcupacionVigente()` itself documents, but `crear()` does not
     * assume it), or the row predates this column (added once
     * `cambios_solicitud` was confirmed EMPTY in production — see
     * `Migrations\MigrationRunner`'s own docblock for why a plain
     * `dbDelta()`-driven column add, not a versioned backfill migration, is
     * sufficient here: there is no existing row to migrate). Every reader of
     * this column MUST treat `NULL` as "unknown", never guess a value —
     * see `Rest\SolicitudesController`'s own docblock for how the REST
     * response and the app both degrade this to a visible "not recorded"
     * state instead of fabricating a name.
     */
    private static function sqlCambiosSolicitud( string $p, string $charset ): string {
        return "CREATE TABLE {$p}cambios_solicitud (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  season_id BIGINT UNSIGNED NOT NULL,
  team_id BIGINT UNSIGNED NOT NULL,
  plaza_id BIGINT UNSIGNED NOT NULL,
  tipo ENUM('sustitucion','regreso') NOT NULL,
  entrante_player_id BIGINT UNSIGNED NULL DEFAULT NULL,
  saliente_player_id BIGINT UNSIGNED NULL DEFAULT NULL,
  fecha_id BIGINT UNSIGNED NOT NULL,
  solicitada_por BIGINT UNSIGNED NOT NULL,
  solicitada_at DATETIME NOT NULL,
  solicitud_instante_epoch BIGINT UNSIGNED NOT NULL,
  dictamen_original TEXT NOT NULL,
  dictamen_aplicado TEXT NULL DEFAULT NULL,
  estado ENUM('pendiente','aprobada','rechazada','publicada','anulada') NOT NULL DEFAULT 'pendiente',
  resuelta_por BIGINT UNSIGNED NULL DEFAULT NULL,
  resuelta_at DATETIME NULL DEFAULT NULL,
  nota TEXT NULL DEFAULT NULL,
  ocupacion_id BIGINT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_season_estado (season_id, estado),
  KEY idx_plaza (plaza_id)
) ENGINE=InnoDB $charset;";
    }

    /**
     * cambios_decision — APPEND-ONLY. One row per decision ever made on a
     * solicitud (`aprobada`, `rechazada`, `anulada`, `publicada`) — never
     * updated, never deleted. This is the honest answer to "who approved or
     * rejected this, and when", which `cambios_solicitud.resuelta_por` /
     * `resuelta_at` cannot give once a solicitud has been decided more than
     * once (see that table's docblock): those three columns are overwritten
     * on every transition, so a solicitud approved Wednesday and then
     * rejected Thursday would otherwise lose the Wednesday decision entirely.
     *
     * `decidida_por` is the WP user id — same "id can go stale" problem every
     * other *_por column in this plugin has (the user can be deleted or
     * renamed later). `decidida_por_nombre` exists BECAUSE of that: it is the
     * display name (or login) captured AT THE MOMENT of the decision, a
     * deliberate snapshot, never re-derived from `wp_users` on read. The id
     * says who this points to TODAY; the name says who it was THEN — the same
     * split `Dictamen\DictamenSnapshot` makes for a dictamen's motivos, for
     * the same reason: a fact that was true at one instant must stay
     * legible even after the live source of truth has moved on.
     *
     * Written by `Solicitudes\SolicitudRepository` in the SAME transaction as
     * the `cambios_solicitud.estado` write it accompanies — see that class's
     * `transicionar()` and `publicarLote()` for why: a state change recorded
     * without its decision row, or a decision row for a state change that
     * never actually committed, are equally unacceptable half-truths.
     */
    private static function sqlCambiosDecision( string $p, string $charset ): string {
        return "CREATE TABLE {$p}cambios_decision (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  solicitud_id BIGINT UNSIGNED NOT NULL,
  accion ENUM('aprobada','rechazada','anulada','publicada') NOT NULL,
  decidida_por BIGINT UNSIGNED NOT NULL,
  decidida_por_nombre VARCHAR(255) NOT NULL,
  decidida_at DATETIME NOT NULL,
  nota TEXT NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_solicitud (solicitud_id)
) ENGINE=InnoDB $charset;";
    }

    // -------------------------------------------------------------------------
    // Post-migration seeds
    // -------------------------------------------------------------------------

    /**
     * Insert default settings rows (idempotent — INSERT IGNORE).
     */
    private static function seedSettings( string $p ): void {
        global $wpdb;

        // Every DATETIME column this plugin persists is UTC — see this
        // plugin's README ("Datetime columns are UTC") — so `$gmt = true` is
        // mandatory here, never the default `current_time('mysql')`, which
        // hands out the site's LOCAL civil time instead.
        $now = current_time( 'mysql', true );
        foreach ( self::SEED_DEFAULTS as $key => $value ) {
            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$p}cambios_settings (setting_key, setting_value, updated_at) VALUES (%s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                    $key,
                    $value,
                    $now
                )
            );
        }
    }
}

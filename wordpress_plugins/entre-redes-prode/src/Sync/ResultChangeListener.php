<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Sync;

use EntreRedes\Prode\Cron\ReevaluateFechaCron;

/**
 * Listener: self-heals an already-evaluated fecha when an operator corrects a
 * played match's result in SportsPress (ADR-G7-1).
 *
 * Problem this closes:
 *   FechaEvaluator flips a fecha to 'evaluated' once every match has a final
 *   score. If an operator later edits sp_score_1/sp_score_2 on one of those
 *   matches (e.g. 2-2 -> 2-1), nothing re-runs: EvaluatorCron only selects
 *   fechas with state != 'evaluated' (FechaRepository::listDueFechaIds), and
 *   EvaluationController rejects a manual POST /prode/evaluar-fecha against an
 *   already-evaluated fecha with 400 fecha_not_locked. prode_scores and
 *   prode_ranking_fecha_cache stay stale forever.
 *
 * Fix: detect the correction on save and schedule a repair pass that calls
 * FechaEvaluator::evaluateFecha() directly (see ReevaluateFechaCron), bypassing
 * both of the guards above on purpose — evaluateFecha() itself has none, and is
 * idempotent by construction (ADR-G3-1).
 *
 * CRITICAL — hook choice (ADR-G7-1, verified against WordPress core and this
 * codebase's own history):
 *   This listener MUST be bound to `save_post` at priority 20, and MUST NOT be
 *   bound to `save_post_sp_event`. WordPress fires `save_post_{$post_type}`
 *   BEFORE the generic `save_post` (wp-includes/post.php: the type-specific hook
 *   fires first, then the generic one). SportsPress writes ALL of its meta
 *   boxes — sp_score_1/sp_score_2 included — on `save_post` priority 1
 *   (class-sp-admin-meta-boxes.php). Priority only orders callbacks WITHIN the
 *   same hook, so no priority on `save_post_sp_event` can ever run after a
 *   `save_post` callback: a listener bound there would read the PREVIOUS save's
 *   score, one edit behind. The sibling entre-redes-api plugin hit exactly this
 *   bug syncing wpm2_jugador_partido and fixed it the same way this class does
 *   (save_post, priority 20). Binding is done in Plugin.php; see the hook
 *   registration test asserting priority 20 on save_post and absence from
 *   save_post_sp_event — that test is the regression guard for this decision.
 *
 * Defensive signature: WordPress always invokes `save_post` with 3 args
 * ($post_id, $post, $update), but some third-party code fires it manually with
 * a single argument. A required, typed second parameter would fatal in that
 * case, so $post is untyped and defaults to null, with a get_post() fallback.
 */
class ResultChangeListener {

    /**
     * WP hook entrypoint — bound to `save_post`, priority 20, 2 accepted args
     * (Plugin.php). Keep this signature unchanged; see class docblock.
     *
     * Every guard below is a plain early-return no-op — this listener never
     * throws and never blocks the post save it observes.
     *
     * @param int        $post_id
     * @param mixed      $post     Expected \WP_Post, but untyped defensively.
     */
    public static function onSavePost( int $post_id, $post = null ): void {
        $post = $post ?? get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        if ( 'sp_event' !== $post->post_type ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( 'publish' !== $post->post_status ) {
            return;
        }

        global $wpdb;
        $p = $wpdb->prefix;

        // The match must belong to a fecha we track. A match with no
        // prode_fecha_matches row was never part of a Prode round — nothing to repair.
        $matchRow = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT fecha_id, real_score_home, real_score_away, is_final
                   FROM {$p}prode_fecha_matches
                  WHERE match_id = %d
                  LIMIT 1",
                $post_id
            ),
            ARRAY_A
        );

        if ( empty( $matchRow ) ) {
            return;
        }

        $fechaId = (int) $matchRow['fecha_id'];

        // Only an already-evaluated fecha needs THIS repair path. A fecha that
        // is not yet evaluated is already covered by the normal EvaluatorCron
        // sweep (FechaRepository::listDueFechaIds), which will pick up the
        // corrected score on its own next pass.
        $fechaState = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT state FROM {$p}prode_fechas WHERE id = %d LIMIT 1",
                $fechaId
            )
        );

        if ( 'evaluated' !== $fechaState ) {
            return;
        }

        // Change detection: cheap, and deliberately biased toward re-evaluating
        // when uncertain. An EMPTY sp_score_1/sp_score_2 does NOT mean
        // "unchanged" — SportsPress can store the result elsewhere (the sibling
        // entre-redes-api plugin falls back to `main_results` from
        // /wp/v2/sp_event/{id} in exactly that case) — so only skip when both
        // metas are present AND match the snapshot we already scored against.
        $home = get_post_meta( $post_id, 'sp_score_1', true );
        $away = get_post_meta( $post_id, 'sp_score_2', true );

        $unchanged = is_string( $home ) && '' !== $home
            && is_string( $away ) && '' !== $away
            && 1 === (int) $matchRow['is_final']
            && (int) $home === (int) $matchRow['real_score_home']
            && (int) $away === (int) $matchRow['real_score_away'];

        if ( $unchanged ) {
            return;
        }

        // Purge the /partidos cache ourselves (ADR-G7-2). entre-redes-api also
        // purges this transient on save_post_sp_event, but that hook fires
        // BEFORE SportsPress writes its metas (see class docblock) — its purge
        // can run against the stale value and is unreliable for our purposes.
        // Running at save_post priority 20 (after the metas land), our purge is
        // the one guaranteed to happen after the corrected score is persisted,
        // so the deferred re-evaluation below reads fresh data.
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
              WHERE option_name LIKE '_transient_entre_redes_partidos_%'
                 OR option_name LIKE '_transient_timeout_entre_redes_partidos_%'"
        );

        // Defer the actual repair by 30s so multiple quick edits to the same
        // fecha collapse into one pass. WordPress dedupes identical (hook, args)
        // schedules within a 10-minute window, so correcting several matches of
        // the same fecha in a row still queues exactly one re-evaluation.
        wp_schedule_single_event( time() + 30, ReevaluateFechaCron::HOOK, [ $fechaId ] );
    }
}

<?php
/*
Plugin Name: Entre Redes REST Fix
Description: Fuerza la visibilidad en REST API de sp_event, sp_team y sp_player para SportsPress
Version: 1.0
*/

add_action('init', function () {
    global $wp_post_types;

    $types = ['sp_event', 'sp_team', 'sp_player'];

    foreach ($types as $pt) {
        if (isset($wp_post_types[$pt])) {
            $wp_post_types[$pt]->show_in_rest = true;
            $wp_post_types[$pt]->rest_base = $pt;
            $wp_post_types[$pt]->rest_controller_class = 'WP_REST_Posts_Controller';
        }
    }
}, 100);

add_action('init', function () {
    $taxonomies = ['sp_league', 'sp_season', 'sp_venue'];
    foreach ($taxonomies as $taxonomy) {
        register_taxonomy($taxonomy, null, [
            'show_in_rest' => true,
            'rest_base' => $taxonomy,
            'rest_controller_class' => 'WP_REST_Terms_Controller',
        ]);
    }
}, 100);

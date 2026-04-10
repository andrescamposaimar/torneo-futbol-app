<?php

/**
 * Template Name: Left Sidebar
 */

get_header();

while ( have_posts() ) {

	the_post();

	get_template_part( 'partials/content', 'page_left_sidebar' );
}

get_footer();
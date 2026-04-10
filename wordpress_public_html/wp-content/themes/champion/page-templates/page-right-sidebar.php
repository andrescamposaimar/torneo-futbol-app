<?php

/**
 * Template Name: Right Sidebar
 */

get_header();

while ( have_posts() ) {

	the_post();

	get_template_part( 'partials/content', 'page_right_sidebar' );
}

get_footer();